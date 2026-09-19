<?php
declare(strict_types=1);
namespace App\Integration\Payment;

use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;

/**
 * Platega payment provider.
 *
 * Real API (docs.platega.io, project 766087):
 *   POST /v2/transaction/process  -- create a payment link
 *   Auth: header X-MerchantId + X-Secret
 *   Body: paymentDetails{amount,currency}, description, return, failedUrl,
 *         payload, orderId, metadata{userId,userName,clientIp}
 *   Reply: { transactionId, status, url, expiresIn, rate }
 *
 * amount is in minor units (kopeks) — the docs sample "amount":500 with
 * rate ~91.2 is 5.00 RUB.
 */
final class PlategaProvider extends AbstractProvider
{
    private const DEFAULT_BASE = 'https://app.platega.io';

    public function id(): string { return 'platega'; }
    public function name(): string { return 'Platega'; }

    public function configured(): bool
    {
        $enabled = ($this->config['PLATEGA_ENABLED'] ?? '0') === '1'
            || (($this->config['PAYMENT_DRIVER'] ?? '') === 'platega');
        return $enabled
            && ($this->config['PLATEGA_MERCHANT_ID'] ?? '') !== ''
            && ($this->config['PLATEGA_SECRET'] ?? '') !== '';
    }

    private function base(): string
    {
        $base = rtrim((string)($this->config['PLATEGA_API_BASE'] ?? self::DEFAULT_BASE), '/');
        if ($base === '') throw new BillingError('Platega: не задан API-адрес.');
        return $base;
    }

    /**
     * Signed POST to the Platega API. Auth is carried in headers, not in the
     * body, so this is the only place headers are attached.
     */
    private function post(string $endpoint, array $body): array
    {
        $merchant = (string)($this->config['PLATEGA_MERCHANT_ID'] ?? '');
        $secret = (string)($this->config['PLATEGA_SECRET'] ?? '');
        if ($merchant === '' || $secret === '') throw new BillingError('Platega: не заполнены ключи (merchant_id/secret).');
        $resp = $this->json('POST', $this->base().'/'.$endpoint, [
            'headers' => [
                'X-MerchantId' => $merchant,
                'X-Secret' => $secret,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);
        if (isset($resp['errors'])) throw new BillingError('Platega: '.$this->summarizeErrors($resp['errors']));
        return $resp;
    }

    private function summarizeErrors($errors): string
    {
        if (is_array($errors)) {
            foreach ($errors as $v) {
                if (is_array($v)) return implode('; ', array_map('strval', $v));
                if (is_string($v) && $v !== '') return $v;
            }
            return 'Некорректные параметры запроса.';
        }
        return is_string($errors) && $errors !== '' ? $errors : 'Некорректные параметры запроса.';
    }

    private function metadata(array $user): array
    {
        $meta = [
            'userId' => (string)($user['telegram_id'] ?? $user['id'] ?? ''),
            'userName' => (string)($user['username'] ?? $user['email'] ?? ''),
        ];
        return array_filter($meta, fn($v) => $v !== '');
    }

    /** POST /v2/transaction/process → check the created payment URL. */
    private function create(array $orderOrTopup, array $user, string $description, string $return, string $failedUrl): array
    {
        $amount = (int)($orderOrTopup['amount_kopeks'] ?? $orderOrTopup['price_minor'] ?? 0);
        if ($amount < 100) throw new BillingError('Platega: некорректная сумма заказа.');
        $res = $this->post('v2/transaction/process', [
            'paymentDetails' => [
                'amount' => $amount,
                'currency' => 'RUB',
            ],
            'description' => $description,
            'return' => $return,
            'failedUrl' => $failedUrl,
            'orderId' => (string)$orderOrTopup['id'],
            'metadata' => $this->metadata($user),
        ]);
        $url = (string)($res['url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'https://')) throw new BillingError('Platega не вернул ссылку оплаты.');
        return [
            'payment_id' => (string)($res['transactionId'] ?? $orderOrTopup['id']),
            'checkout_url' => $url,
        ];
    }

    public function createTopup(array $topup, array $user): array
    {
        $app = rtrim($this->config['APP_URL'] ?? '', '/');
        return $this->create($topup, $user, 'Пополнение баланса', $app.'/balance', $app.'/balance');
    }

    public function createOrder(array $order, array $user): array
    {
        $app = rtrim($this->config['APP_URL'] ?? '', '/');
        return $this->create(
            $order,
            $user,
            'Подписка: '.($order['plan_name'] ?? 'VPN'),
            $app.'/orders/'.$order['id'],
            $app.'/orders/'.$order['id']
        );
    }

    /**
     * Verify a transaction. Statuses observed from the docs: PENDING /
     * CONFIRMED / FAILED / EXPIRED / CANCELED. We query the transaction by
     * its id; the status endpoint returns the same fields as creation.
     */
    public function verify(string $paymentId): array
    {
        // A canceled/expired transaction may return a non-2xx from the status
        // endpoint (the tx is no longer an open charge). Treat 4xx as canceled
        // so topups/orders that were never funded settle-as-canceled instead of
        // poisoning the worker with retry churn. HTTP 5xx stays terminal-error.
        $res = [];
        try {
            $res = $this->post('v2/transaction/'.rawurlencode($paymentId), []);
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            $code = 0;
            try { $code = $e->getResponse()?->getStatusCode() ?? 0; } catch (\Throwable) {}
            // 4xx client error on a status probe => the tx is gone/invalid.
            if ($code >= 404) {
                return [
                    'status' => 'canceled',
                    'amount_kopeks' => 0,
                    'currency' => 'RUB',
                    'payment_id' => $paymentId,
                    'metadata' => ['order_id' => '', 'topup_id' => ''],
                ];
            }
            throw $e;
        }
        $status = strtoupper((string)($res['status'] ?? ''));
        return [
            'status' => $status === 'CONFIRMED' ? 'paid' : (in_array($status, ['FAILED', 'EXPIRED', 'CANCELED'], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => (int)($res['amount'] ?? $res['paymentDetails']['amount'] ?? 0),
            'currency' => (string)($res['currency'] ?? 'RUB'),
            'payment_id' => (string)($res['transactionId'] ?? $paymentId),
            'metadata' => ['order_id' => (string)($res['orderId'] ?? ''), 'topup_id' => (string)($res['orderId'] ?? '')],
        ];
    }

    /**
     * Platega callback (webhook) — POST to /webhooks/platega. Payload carries
     * the transaction state; map it to the internal paid/pending/canceled
     * status used by the dispatcher. Returns null if the payload is not a
     * Platega payment callback.
     */
    public function handleWebhook(Request $request): ?array
    {
        // Reject callbacks that don't identify this merchant (defense in depth).
        $merchant = (string)($this->config['PLATEGA_MERCHANT_ID'] ?? '');
        $incoming = (string)($request->headers->get('X-MerchantId') ?? $request->headers->get('X-Merchant-Id') ?? '');
        if ($merchant !== '' && $incoming !== '' && !hash_equals($merchant, $incoming)) return null;
        $data = $request->toArray();
        $paymentId = (string)($data['transactionId'] ?? $data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = strtoupper((string)($data['status'] ?? ''));
        $orderId = (string)($data['orderId'] ?? $data['order_id'] ?? '');
        return [
            'payment_id' => $paymentId,
            'status' => $status === 'CONFIRMED' ? 'paid' : (in_array($status, ['FAILED', 'EXPIRED', 'CANCELED'], true) ? 'canceled' : 'pending'),
            'metadata' => ['order_id' => $orderId, 'topup_id' => $orderId],
        ];
    }
}
