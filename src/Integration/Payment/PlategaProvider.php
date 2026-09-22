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
 * Amounts use RUB major units; preserve the fractional kopeks.
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
            'headers' => $this->authHeaders(),
            'json' => $body,
        ]);
        if (isset($resp['errors'])) throw new BillingError('Platega: '.$this->summarizeErrors($resp['errors']));
        return $resp;
    }

    private function get(string $endpoint, array $query = []): array
    {
        $merchant = (string)($this->config['PLATEGA_MERCHANT_ID'] ?? '');
        $secret = (string)($this->config['PLATEGA_SECRET'] ?? '');
        if ($merchant === '' || $secret === '') throw new BillingError('Platega: не заполнены ключи (merchant_id/secret).');
        $url = $this->base().'/'.$endpoint;
        if ($query) $url .= '?'.http_build_query($query);
        $resp = $this->json('GET', $url, ['headers' => $this->authHeaders()]);
        if (isset($resp['errors'])) throw new BillingError('Platega: '.$this->summarizeErrors($resp['errors']));
        return $resp;
    }

    private function authHeaders(): array
    {
        return [
            'X-MerchantId' => (string)($this->config['PLATEGA_MERCHANT_ID'] ?? ''),
            'X-Secret' => (string)($this->config['PLATEGA_SECRET'] ?? ''),
            'Content-Type' => 'application/json',
        ];
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
        $amountMinor = (int)($orderOrTopup['amount_kopeks'] ?? $orderOrTopup['price_minor'] ?? 0);
        if ($amountMinor < 100) throw new BillingError('Platega: некорректная сумма заказа.');
        // Platega expects `amount` in RUB major units (rubles), not kopeks.
        // Sending kopeks here inflates the charge 100x (100 RUB became 10 000 RUB).
        if ($amountMinor < 100) throw new BillingError('Platega: некорректная сумма заказа.');
        $amountRub = self::decimal($amountMinor);
        $res = $this->post('v2/transaction/process', [
            'paymentDetails' => [
                'amount' => $amountRub,
                'currency' => 'RUB',
            ],
            'description' => $description,
            'return' => $return,
            'failedUrl' => $failedUrl,
            'orderId' => (string)$orderOrTopup['id'],
            'payload' => json_encode([isset($orderOrTopup['amount_kopeks'])?'topup_id':'order_id'=>(string)$orderOrTopup['id']],JSON_THROW_ON_ERROR),
            'metadata' => $this->metadata($user),
        ]);
        $url = (string)($res['url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'https://')) throw new BillingError('Platega не вернул ссылку оплаты.');
        $paymentId=$res['transactionId']??null;
        if (!is_string($paymentId) || $paymentId==='' || strlen($paymentId)>100) throw new BillingError('Platega не вернул идентификатор платежа.');
        return [
            'payment_id' => $paymentId,
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
        // HTTP failures (including 404 and 429) are not payment outcomes.
        // Keep the local payment pending and retry authoritative verification.
        $res = $this->get('transaction/'.rawurlencode($paymentId), []);
        $status = strtoupper((string)($res['status'] ?? ''));
        // Platega returns paymentDetails.amount in RUB rubles (gross) with a
        // separate `comission` field; the merchant-receivable (net) amount =
        // paymentDetails.amount - comission. ZeleBoba settles against the order
        // amount (net), so report the NET amount back in kopeks. Without this, a
        // customer charge of 199.00 + 9% commission (216.91) was read as 21691
        // kopeks vs the order's 19900 -> settle() rejected "Платёж не
        // соответствует заказу" and the Reconciler re-verified forever.
        $amountMinor = self::minor(self::normalizeAmount((string)($res['paymentDetails']['amount'] ?? 0)));
        $commissionMinor = self::minor(self::normalizeAmount((string)($res['comission'] ?? 0)));
        if ($commissionMinor > $amountMinor) throw new BillingError('Platega: некорректная комиссия.');
        $actualId=$res['id']??$res['transactionId']??null;
        if (!is_string($actualId) || $actualId!==$paymentId) throw new BillingError('Platega: несовпадение идентификатора платежа.');
        $metadata=json_decode((string)($res['payload']??'{}'),true);
        if (!is_array($metadata)) $metadata=[];
        if (!isset($metadata['order_id'],$metadata['topup_id']) && !empty($res['orderId'])) $metadata=['order_id'=>(string)$res['orderId'],'topup_id'=>(string)$res['orderId']];
        return [
            'status' => $status === 'CONFIRMED' ? 'paid' : (in_array($status, ['FAILED', 'EXPIRED', 'CANCELED'], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => $amountMinor - $commissionMinor,
            'currency' => (string)($res['paymentDetails']['currency'] ?? 'RUB'),
            'payment_id' => $actualId,
            'metadata' => $metadata,
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
        // Both authentication headers are required by the Platega callback contract.
        $merchant = (string)($this->config['PLATEGA_MERCHANT_ID'] ?? '');
        $incoming = (string)($request->headers->get('X-MerchantId') ?? $request->headers->get('X-Merchant-Id') ?? '');
        $secret=(string)($this->config['PLATEGA_SECRET']??'');
        if ($merchant==='' || $secret==='' || !hash_equals($merchant,$incoming) || !hash_equals($secret,(string)$request->headers->get('X-Secret',''))) return null;
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
