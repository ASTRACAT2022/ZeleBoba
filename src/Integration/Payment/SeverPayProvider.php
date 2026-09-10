<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class SeverPayProvider extends AbstractProvider
{
    public function id(): string { return 'severpay'; }
    public function name(): string { return 'SeverPay'; }
    public function configured(): bool
    {
        return ($this->config['SEVERPAY_ENABLED'] ?? '0') === '1'
            && ($this->config['SEVERPAY_MID'] ?? '') !== ''
            && ($this->config['SEVERPAY_TOKEN'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $mid = (string)($this->config['SEVERPAY_MID'] ?? '');
        $token = (string)($this->config['SEVERPAY_TOKEN'] ?? '');
        if ($mid === '' || $token === '') throw new BillingError('SeverPay не настроен.');
        $data['mid'] = $mid;
        $data['nonce'] = (string)(microtime(true) * 1000000);
        ksort($data);
        $data['signature'] = hash_hmac('sha256', implode('|', array_map('strval', array_values($data))), $token);
        $resp = $this->json('POST', 'https://api.severpay.io/'.$endpoint, ['json' => $data]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('SeverPay: '.($resp['message'] ?? 'API error'));
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
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('SeverPay не вернул ссылку оплаты.');
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
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('SeverPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payment/status', ['id' => $paymentId]);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => $status === 'success' ? 'paid' : (in_array($status, ['decline', 'fail'], true) ? 'canceled' : 'pending'),
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
        return ['payment_id' => $paymentId, 'status' => $status === 'success' ? 'paid' : 'pending'];
    }
}
