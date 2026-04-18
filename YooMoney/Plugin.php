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
        // Extract raw post data if $params is not populated as expected by xboard
        if (!is_array($params) || (!isset($params['sha1_hash']) && !isset($params['sign']))) {
            $params = request()->post();
            if (empty($params)) {
                $params = request()->all();
            }
            // Last resort: parse raw request body (handles edge cases where Laravel doesn't parse the POST data)
            if (empty($params)) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    parse_str($rawInput, $params);
                }
            }
        }

        if (!is_array($params) || empty($params)) {
            Log::error('YooMoney: Webhook received with no data. Ensure YooMoney sends POST to this URL.', [
                'method' => request()->method(),
                'content_type' => request()->header('Content-Type'),
            ]);
            return false;
        }

        $secret = $this->getConfig('secret');

        Log::info('YooMoney webhook received:', $params);

        // Check required fields from YooMoney HTTP notification (using array_key_exists since some can be null like 'sender')
        // YooMoney now sends 'sign' (HMAC-SHA256) instead of legacy 'sha1_hash' (SHA-1)
        $requiredFields = ['notification_type', 'operation_id', 'amount', 'currency', 'datetime', 'sender', 'codepro', 'label'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $params)) {
                Log::error("YooMoney: Missing required field in webhook: {$field}", $params);
                return false;
            }
        }

        // Determine which hash field is present: new 'sign' (SHA-256) or legacy 'sha1_hash' (SHA-1)
        $useSha256 = array_key_exists('sign', $params);
        $useSha1 = array_key_exists('sha1_hash', $params);
        if (!$useSha256 && !$useSha1) {
            Log::error('YooMoney: Missing hash field in webhook (neither sign nor sha1_hash found)', $params);
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

        if (array_key_exists('unaccepted', $params) && ($params['unaccepted'] === 'true' || $params['unaccepted'] === true)) {
            Log::error('YooMoney: Transfer is unaccepted. Rejected.');
            return false;
        }

        if ($params['currency'] !== '643' && (int)$params['currency'] !== 643) {
            Log::error('YooMoney: Invalid currency, expected 643 (RUB).', ['received' => $params['currency']]);
            return false;
        }

        // Hash verification
        // YooMoney requires null fields to be represented as empty strings in the hash concatenation
        // Field values used in the hash (same order for all algorithms)
        $hashFields = [
            $params['notification_type'] ?? '',
            $params['operation_id'] ?? '',
            $params['amount'] ?? '',
            $params['currency'] ?? '',
            $params['datetime'] ?? '',
            $params['sender'] ?? '',
            $params['codepro'] ?? '',
        ];

        if ($useSha256) {
            // New YooMoney format: 'sign' field with HMAC-SHA256
            // YooMoney's new sign field uses HMAC-SHA256 with the notification secret as the HMAC key,
            // rather than concatenating the secret into the hash string (as the legacy sha1_hash did).
            $hmacStr = implode('&', array_merge($hashFields, [$params['label'] ?? '']));
            $calculatedHash = hash_hmac('sha256', $hmacStr, $secret);

            if (!hash_equals($calculatedHash, $params['sign'])) {
                // Fallback: try legacy-style plain SHA-256 with secret in the string (same format as sha1_hash
                // but with SHA-256). This covers the transitional case where YooMoney may use the same string
                // format as sha1_hash but with SHA-256 algorithm, since official docs are ambiguous on the exact
                // format for the new sign field.
                $legacyStr = implode('&', array_merge($hashFields, [$secret, $params['label'] ?? '']));
                $calculatedHashLegacy = hash('sha256', $legacyStr);

                if (!hash_equals($calculatedHashLegacy, $params['sign'])) {
                    Log::error('YooMoney: sign verification failed (tried HMAC-SHA256 and plain SHA-256)', [
                        'hmac_sha256' => $calculatedHash,
                        'plain_sha256' => $calculatedHashLegacy,
                        'received' => $params['sign'],
                        'hash_input_fields' => $hmacStr,
                    ]);
                    return false;
                }
            }
        } else {
            // Legacy format: 'sha1_hash' field with SHA-1 (secret concatenated into the string)
            $hashStr = implode('&', array_merge($hashFields, [$secret, $params['label'] ?? '']));
            $calculatedHash = sha1($hashStr);
            if (!hash_equals($calculatedHash, $params['sha1_hash'])) {
                Log::error('YooMoney: SHA-1 hash mismatch', ['calculated' => $calculatedHash, 'received' => $params['sha1_hash']]);
                return false;
            }
        }

        // Verify the payment amount against the original order amount
        // Quickpay redirect links are not signed, so a user could alter the amount parameter in their browser
        $order = \App\Models\Order::where('trade_no', $params['label'])->first();
        if (!$order) {
            Log::error('YooMoney: Order not found', ['label' => $params['label']]);
            return false;
        }

        $expectedAmount = $order->total_amount / 100; // Xboard amounts are stored in cents
        // Use withdraw_amount (gross amount the user actually paid) if available, otherwise fallback to amount
        // (net amount after YooMoney commission). Using amount would cause false fraud rejections since
        // YooMoney deducts ~3% commission: e.g. user pays 30.00 RUB but amount=29.10 after fees.
        $paidAmount = isset($params['withdraw_amount']) ? (float)$params['withdraw_amount'] : (float)$params['amount'];

        // Use round() to avoid floating-point precision issues in comparison (e.g. 30.00 vs 29.999999...)
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
