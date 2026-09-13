<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class WataProvider extends AbstractProvider
{
    public function id(): string { return 'wata'; }
    public function name(): string { return 'WATA'; }
    public function configured(): bool
    {
        return ($this->config['WATA_ENABLED'] ?? '0') === '1'
            && ($this->config['WATA_ACCESS_TOKEN'] ?? '') !== ''
            && ($this->config['WATA_TERMINAL_PUBLIC_ID'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $token = (string)($this->config['WATA_ACCESS_TOKEN'] ?? '');
        if ($token === '') throw new BillingError('WATA не настроен.');
        $resp = $this->json('POST', 'https://api.wata.ru/api/v1/'.$endpoint, [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
        if (($resp['success'] ?? false) !== true) throw new BillingError('WATA: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payment-links', [
            'terminal_public_id' => (string)$this->config['WATA_TERMINAL_PUBLIC_ID'],
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'currency' => 'RUB',
            'description' => 'Пополнение баланса',
            'order_id' => $topup['id'],
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
            'failed_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('WATA не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payment-links', [
            'terminal_public_id' => (string)$this->config['WATA_TERMINAL_PUBLIC_ID'],
            'amount' => self::decimal((int)$order['price_minor']),
            'currency' => 'RUB',
            'description' => 'Подписка: '.$order['plan_name'],
            'order_id' => $order['id'],
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
            'failed_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('WATA не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payment-links/'.$paymentId);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => $status === 'paid' ? 'paid' : ($status === 'canceled' ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => ['order_id' => (string)($result['order_id'] ?? '')],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = $request->toArray();
        $paymentId = (string)($data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => $status === 'paid' ? 'paid' : 'pending'];
    }
}
