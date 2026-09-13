<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class RollyPayProvider extends AbstractProvider
{
    public function id(): string { return 'rollypay'; }
    public function name(): string { return 'RollyPay'; }
    public function configured(): bool
    {
        return ($this->config['ROLLYPAY_ENABLED'] ?? '0') === '1'
            && ($this->config['ROLLYPAY_API_KEY'] ?? '') !== ''
            && ($this->config['ROLLYPAY_SIGNING_SECRET'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $key = (string)($this->config['ROLLYPAY_API_KEY'] ?? '');
        if ($key === '') throw new BillingError('RollyPay не настроен.');
        $resp = $this->json('POST', 'https://api.rollypay.io/v1/'.$endpoint, [
            'headers' => ['X-API-Key' => $key, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('RollyPay: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'currency' => 'RUB',
            'order_id' => $topup['id'],
            'description' => 'Пополнение баланса',
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('RollyPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$order['price_minor']),
            'currency' => 'RUB',
            'order_id' => $order['id'],
            'description' => 'Подписка: '.$order['plan_name'],
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('RollyPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payment/status', ['id' => $paymentId]);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => in_array($status, ['paid', 'success', 'completed'], true) ? 'paid' : (in_array($status, ['canceled', 'failed', 'expired'], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => [],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $secret = (string)($this->config['ROLLYPAY_SIGNING_SECRET'] ?? '');
        if ($secret === '') return null;
        $signature = (string)$request->headers->get('X-Signature', '');
        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $signature)) return null;
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $paymentId = (string)($data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => in_array($status, ['paid', 'success'], true) ? 'paid' : 'pending'];
    }
}
