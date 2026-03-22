<?php

namespace Plugin\Yoomoney;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Models\Order;

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
            'label'
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
            $tradeNo = $params['label'];

            // Находим заказ в базе данных, чтобы проверить соответствие суммы
            $order = Order::where('trade_no', $tradeNo)->first();
            if (!$order) {
                return false;
            }

            // В Xboard total_amount хранится в копейках.
            $orderAmount = $order->total_amount / 100;

            // ЮMoney вычитает комиссию с получателя (0.5% для кошельков, 2% для карт).
            // 'withdraw_amount' - это полная сумма, которую заплатил отправитель (присутствует не всегда).
            // 'amount' - это сумма, которая пришла на счет (за вычетом комиссии).
            $paidAmount = isset($params['withdraw_amount']) ? (float)$params['withdraw_amount'] : (float)$params['amount'];

            // Если withdraw_amount отсутствует, мы вынуждены использовать 'amount'.
            // В этом случае минимально допустимая сумма = сумма заказа минус 2% максимальной комиссии.
            $minAcceptableAmount = isset($params['withdraw_amount'])
                ? ($orderAmount - 0.01)
                : ($orderAmount * 0.98 - 0.01);

            // Если оплаченная сумма меньше допустимой (защита от изменения суммы в форме)
            if ($paidAmount < $minAcceptableAmount) {
                return false;
            }

            return [
                'trade_no' => $tradeNo,       // Xboard's order number
                'callback_no' => $params['operation_id'], // Yoomoney transaction ID
                'custom_result' => [
                    // Для Xboard возвращаем полную сумму заказа, чтобы он корректно его зачислил
                    'amount' => (int) round($paidAmount * 100)
                ]
            ];
        }

        return false;
    }
}
