<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class DonutProvider extends AbstractProvider
{
    public function id(): string { return 'donut'; }
    public function name(): string { return 'Donut'; }
    public function configured(): bool
    {
        return ($this->config['DONUT_ENABLED'] ?? '0') === '1'
            && ($this->config['DONUT_TOKEN'] ?? '') !== ''
            && ($this->config['DONUT_SECRET'] ?? '') !== '';
    }
    private function baseUrl(): string
    {
        return rtrim($this->config['DONUT_BASE_URL'] ?? 'https://gw.donut.business', '/');
    }
    private function api(string $endpoint, array $data = []): array
    {
        $token = (string)($this->config['DONUT_TOKEN'] ?? '');
        $secret = (string)($this->config['DONUT_SECRET'] ?? '');
        if ($token === '' || $secret === '') throw new BillingError('Donut не настроен.');
        $data['token'] = $token;
        $data['nonce'] = (string)(microtime(true) * 1000000);
        ksort($data);
        $data['signature'] = hash_hmac('sha256', implode('|', array_map('strval', array_values($data))), $secret);
        $resp = $this->json('POST', $this->baseUrl().'/api/'.$endpoint, ['json' => $data]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('Donut: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'currency' => 'RUB',
            'order_id' => $topup['id'],
            'description' => 'Пополнение баланса',
            'method_id' => (string)($this->config['DONUT_METHOD_ID'] ?? ''),
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Donut не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$order['price_minor']),
            'currency' => 'RUB',
            'order_id' => $order['id'],
            'description' => 'Подписка: '.$order['plan_name'],
            'method_id' => (string)($this->config['DONUT_METHOD_ID'] ?? ''),
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Donut не вернул ссылку оплаты.');
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
        $data = $request->toArray();
        $paymentId = (string)($data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => in_array($status, ['paid', 'success'], true) ? 'paid' : 'pending'];
    }
}
