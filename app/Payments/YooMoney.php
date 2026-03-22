<?php

namespace App\Payments;

class YooMoney {
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'receiver' => [
                'label' => 'Номер кошелька ЮMoney',
                'description' => 'Номер кошелька, на который будут поступать средства (обычно начинается с 4100)',
                'type' => 'input',
            ],
            'secret' => [
                'label' => 'Секретное слово',
                'description' => 'Секретное слово для проверки HTTP-уведомлений (настраивается в настройках кошелька)',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'receiver' => $this->config['receiver'],
            'quickpay-form' => 'shop',
            'targets' => 'Оплата заказа ' . $order['trade_no'],
            'paymentType' => 'AC', // AC - Банковская карта, PC - Кошелек ЮMoney
            'sum' => $order['total_amount'] / 100,
            'label' => $order['trade_no'],
            'successURL' => $order['return_url']
        ];

        $url = 'https://yoomoney.ru/quickpay/confirm?' . http_build_query($params);

        return [
            'type' => 1, // 1 означает редирект на URL
            'data' => $url
        ];
    }

    public function notify($params)
    {
        $secret = $this->config['secret'];

        $hashString = implode('&', [
            $params['notification_type'] ?? '',
            $params['operation_id'] ?? '',
            $params['amount'] ?? '',
            $params['currency'] ?? '',
            $params['datetime'] ?? '',
            $params['sender'] ?? '',
            $params['codepro'] ?? '',
            $secret,
            $params['label'] ?? ''
        ]);

        $hash = sha1($hashString);

        if (strtolower($hash) !== strtolower($params['sha1_hash'] ?? '')) {
            return false;
        }

        // Проверяем, что перевод не защищен кодом протекции и зачислен
        if (isset($params['unaccepted']) && $params['unaccepted'] === 'true') {
            return false;
        }

        if (isset($params['codepro']) && $params['codepro'] === 'true') {
            return false;
        }

        $order = \App\Models\Order::where('trade_no', $params['label'])->first();
        if (!$order) {
            return false;
        }

        $paidAmount = (float)$params['amount'];

        // Сравниваем сумму платежа (до вычета комиссии) с суммой заказа
        if ($paidAmount < ($order->total_amount / 100)) {
            return false;
        }

        return [
            'trade_no' => $params['label'],
            'callback_no' => $params['operation_id']
        ];
    }
}
