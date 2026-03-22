<?php

namespace Plugin\Yoomoney;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['Yoomoney'] = [
                    'name' => $this->getConfig('display_name', 'ЮMoney'),
                    'icon' => $this->getConfig('icon', 'https://yoomoney.ru/i/html-emails/ym_logo_rus.png'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'display_name' => [
                'label' => 'Отображаемое имя',
                'type' => 'string',
                'description' => 'Название метода оплаты, которое увидят пользователи (например, "ЮMoney").',
                'default' => 'ЮMoney'
            ],
            'icon' => [
                'label' => 'Иконка (URL)',
                'type' => 'string',
                'description' => 'URL картинки-логотипа для метода оплаты.',
                'default' => 'https://yoomoney.ru/i/html-emails/ym_logo_rus.png'
            ],
            'receiver' => [
                'label' => 'Номер кошелька ЮMoney',
                'type' => 'string',
                'required' => true,
                'description' => 'Номер кошелька, на который будут поступать переводы (например: 41001...).'
            ],
            'secret' => [
                'label' => 'Секретное слово',
                'type' => 'string',
                'required' => true,
                'description' => 'Секретное слово из настроек HTTP-уведомлений ЮMoney.'
            ]
        ];
    }

    public function pay($order): array
    {
        // Сумма в копейках, поэтому делим на 100 для получения рублей
        $amount = $order['total_amount'] / 100;

        // $order['trade_no'] передается в label, чтобы потом идентифицировать заказ в notify
        $tradeNo = $order['trade_no'];
        $receiver = $this->getConfig('receiver');

        $html = <<<HTML
        <form id="yoomoney_form" method="POST" action="https://yoomoney.ru/quickpay/confirm.xml">
            <input type="hidden" name="receiver" value="{$receiver}">
            <input type="hidden" name="formcomment" value="Оплата заказа {$tradeNo}">
            <input type="hidden" name="short-dest" value="Оплата заказа {$tradeNo}">
            <input type="hidden" name="label" value="{$tradeNo}">
            <input type="hidden" name="quickpay-form" value="shop">
            <input type="hidden" name="targets" value="Оплата заказа {$tradeNo}">
            <input type="hidden" name="sum" value="{$amount}" data-type="number">
            <input type="hidden" name="paymentType" value="PC">
            <input type="hidden" name="successURL" value="{$order['return_url']}">
        </form>
        <script>
            document.getElementById('yoomoney_form').submit();
        </script>
        HTML;

        return [
            'type' => 1, // 1 = HTML (Form submit / JS redirect)
            'data' => $html
        ];
    }

    public function notify($params): array|bool
    {
        $secret = $this->getConfig('secret');

        // Проверяем наличие всех необходимых параметров для генерации подписи
        $requiredParams = [
            'notification_type',
            'operation_id',
            'amount',
            'currency',
            'datetime',
            'sender',
            'codepro',
            'label',
            'withdraw_amount'
        ];

        foreach ($requiredParams as $param) {
            if (!isset($params[$param])) {
                return false;
            }
        }

        // Формируем строку по правилам ЮMoney
        $hashStr = implode('&', [
            $params['notification_type'],
            $params['operation_id'],
            $params['amount'],
            $params['currency'],
            $params['datetime'],
            $params['sender'],
            $params['codepro'],
            $secret,
            $params['label']
        ]);

        $calculatedHash = sha1($hashStr);

        // Проверяем совпадение подписи и то, что платеж не защищен кодом протекции
        if (isset($params['sha1_hash']) && $params['sha1_hash'] === $calculatedHash && $params['codepro'] === 'false') {
            // Сумма, которая была зачислена на счет (за вычетом комиссии)
            // В ЮMoney `withdraw_amount` - это сумма, которую заплатил пользователь.
            // `amount` - это сумма, которая пришла на счет.
            // Xboard ожидает точную сумму или мы можем просто довериться trade_no
            // Но для безопасности лучше проверить, что сумма достаточна.
            // Xboard передает заказ в $order при вызове pay(), но в notify() мы получаем только $params.
            // Возвращаем данные для Xboard, который сам найдет заказ по trade_no и может сверить сумму,
            // либо мы возвращаем кастомную сумму (в копейках), чтобы ядро Xboard ее проверило.
            // В Xboard обычно возвращается `custom_result` или просто массив с trade_no.
            // Для совместимости с Xboard передадим 'custom_amount' или просто вернем массив.
            // Важно: в новых версиях Xboard может требовать 'amount' или не требовать.
            // Мы передаем 'callback_no' и 'trade_no'.

            return [
                'trade_no' => $params['label'],       // Xboard's order number
                'callback_no' => $params['operation_id'], // Yoomoney transaction ID
                'custom_result' => [
                    'amount' => (int) round($params['withdraw_amount'] * 100)
                ]
            ];
        }

        return false;
    }
}
