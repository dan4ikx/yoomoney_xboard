<?php

namespace Plugin\YooMoney;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['YooMoney'] = [
                    'name' => $this->getConfig('display_name', 'ЮMoney'),
                    'icon' => $this->getConfig('icon', '💵'),
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
            'receiver' => [
                'label' => 'Номер кошелька ЮMoney',
                'type' => 'string',
                'required' => true,
                'description' => 'Ваш номер кошелька ЮMoney (например, 41001...)'
            ],
            'secret' => [
                'label' => 'Секретное слово',
                'type' => 'string',
                'required' => true,
                'description' => 'Секретное слово из настроек HTTP-уведомлений ЮMoney'
            ]
        ];
    }

    public function pay($order): array
    {
        // Force HTTPS in the logged URL to prevent POST data loss during 301/302 HTTP redirects
        $notifyUrl = str_replace('http://', 'https://', $order['notify_url']);
        Log::info('YooMoney EXACT NOTIFY URL FROM XBOARD:', ['url' => $notifyUrl]);

        // $order['total_amount'] is in cents for Xboard standard, so we divide by 100
        $amount = number_format($order['total_amount'] / 100, 2, '.', '');

        $receiver = $this->getConfig('receiver');

        // Quickpay payment form fields
        $params = [
            'receiver' => $receiver,
            'quickpay-form' => 'shop',
            'targets' => 'Оплата заказа ' . $order['trade_no'],
            'sum' => $amount,
            'label' => $order['trade_no'],
            'successURL' => $order['return_url']
        ];

        // yoomoney p2p link
        $url = 'https://yoomoney.ru/quickpay/confirm?' . http_build_query($params);

        return [
            'type' => 1, // 1 is for redirect url in xboard
            'data' => $url
        ];
    }

    public function notify($params): array|bool
    {
        // Extract raw post data if $params is not populated as expected
        if (empty($params['sha1_hash'])) {
            $params = request()->post();
            if (empty($params)) {
                $params = request()->all();
            }
        }

        $secret = $this->getConfig('secret');

        Log::info('YooMoney webhook received:', is_array($params) ? $params : []);

        // Check required fields from Yoomoney HTTP notification (using array_key_exists since some can be null like 'sender')
        $requiredFields = ['notification_type', 'operation_id', 'amount', 'currency', 'datetime', 'sender', 'codepro', 'label', 'sha1_hash'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $params)) {
                Log::error("YooMoney: Missing required field in webhook: {$field}", is_array($params) ? $params : []);
                return false;
            }
        }

        // Fraud prevention checks:
        // 1. Ensure the transfer is not protected by a code (codepro must be false)
        // 2. Ensure the transfer is not unaccepted (unaccepted must be false/not present)
        // 3. Ensure the currency is RUB (643)
        if ($params['codepro'] === 'true' || $params['codepro'] === true) {
            Log::error('YooMoney: Transfer is protected by a code (codepro=true). Rejected.');
            return false;
        }

        if (array_key_exists('unaccepted', $params) && ($params['unaccepted'] === 'true' || $params['unaccepted'] === true)) {
            Log::error('YooMoney: Transfer is unaccepted. Rejected.');
            return false;
        }

        if ($params['currency'] !== '643' && (int)$params['currency'] !== 643) {
            Log::error('YooMoney: Invalid currency, expected 643 (RUB).', ['received' => $params['currency']]);
            return false;
        }

        // SHA-1 verification
        // YooMoney requires null fields to be represented as empty strings in the hash concatenation
        $hashStr = implode('&', [
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

        $calculatedHash = sha1($hashStr);

        if ($calculatedHash !== $params['sha1_hash']) {
            Log::error('YooMoney: SHA1 hash mismatch', ['calculated' => $calculatedHash, 'received' => $params['sha1_hash']]);
            return false;
        }

        // Verify the payment amount against the original order amount
        // Quickpay redirect links are not signed, so a user could alter the amount parameter in their browser
        $order = \App\Models\Order::where('trade_no', $params['label'])->first();
        if (!$order) {
            Log::error('YooMoney: Order not found', ['label' => $params['label']]);
            return false;
        }

        $expectedAmount = $order->total_amount / 100; // Xboard amounts are stored in cents
        // Use withdraw_amount (what the user actually paid) if available, otherwise fallback to amount (what the merchant received after fees)
        $paidAmount = isset($params['withdraw_amount']) ? (float)$params['withdraw_amount'] : (float)$params['amount'];

        // Add a tiny margin of error (e.g. 1-2 kopecks) just in case of float rounding, but generally exact match is expected
        if (round($paidAmount, 2) < round($expectedAmount, 2)) {
            Log::error('YooMoney: Amount mismatch. Possible fraud.', [
                'paid_by_user' => $paidAmount,
                'received_by_merchant' => (float)$params['amount'],
                'expected' => $expectedAmount
            ]);
            return false;
        }

        // Return verified information
        // In Xboard, returning the 'amount' field ensures the payment gateway service verifies the correct sum
        return [
            'trade_no' => $params['label'],
            'callback_no' => $params['operation_id'],
            'amount' => $order->total_amount, // Xboard expects the amount in cents
            'custom_result' => true
        ];
    }
}
