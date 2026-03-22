<?php

namespace Plugin\YooMoney;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
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
        $secret = $this->getConfig('secret');

        // Check required fields from Yoomoney HTTP notification
        if (!isset($params['notification_type'], $params['operation_id'], $params['amount'], $params['currency'], $params['datetime'], $params['sender'], $params['codepro'], $params['label'], $params['sha1_hash'])) {
            return false;
        }

        // Fraud prevention checks:
        // 1. Ensure the transfer is not protected by a code (codepro must be false)
        // 2. Ensure the transfer is not unaccepted (unaccepted must be false/not present)
        // 3. Ensure the currency is RUB (643)
        if ($params['codepro'] === 'true' || $params['codepro'] === true) {
            Log::error('YooMoney: Transfer is protected by a code (codepro=true). Rejected.');
            return false;
        }

        if (isset($params['unaccepted']) && ($params['unaccepted'] === 'true' || $params['unaccepted'] === true)) {
            Log::error('YooMoney: Transfer is unaccepted. Rejected.');
            return false;
        }

        if ($params['currency'] !== '643' && (int)$params['currency'] !== 643) {
            Log::error('YooMoney: Invalid currency, expected 643 (RUB).', ['received' => $params['currency']]);
            return false;
        }

        // SHA-1 verification
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

        if ($calculatedHash !== $params['sha1_hash']) {
            Log::error('YooMoney: SHA1 hash mismatch', ['calculated' => $calculatedHash, 'received' => $params['sha1_hash']]);
            return false;
        }

        // 'amount' is net amount (after fee), 'withdraw_amount' is gross amount (what user paid).
        // We use withdraw_amount to match the order total in Xboard so the payment is marked as paid.
        $amountToCredit = $params['withdraw_amount'] ?? $params['amount'];

        // Return verified information
        return [
            'trade_no' => $params['label'],
            'callback_no' => $params['operation_id'],
            'custom_result' => [
                'trade_no' => $params['label'],
                'callback_no' => $params['operation_id'],
                'amount' => (int)round($amountToCredit * 100)
            ]
        ];
    }
}
