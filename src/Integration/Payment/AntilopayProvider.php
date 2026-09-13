<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class AntilopayProvider extends AbstractProvider
{
    public function id(): string { return 'antilopay'; }
    public function name(): string { return 'Antilopay'; }
    public function configured(): bool
    {
        return ($this->config['ANTILOPAY_ENABLED'] ?? '0') === '1'
            && ($this->config['ANTILOPAY_SECRET_ID'] ?? '') !== ''
            && ($this->config['ANTILOPAY_PROJECT_ID'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $secretId = (string)($this->config['ANTILOPAY_SECRET_ID'] ?? '');
        $projectId = (string)($this->config['ANTILOPAY_PROJECT_ID'] ?? '');
        if ($secretId === '' || $projectId === '') throw new BillingError('Antilopay не настроен.');
        $data['project_id'] = $projectId;
        $data['nonce'] = (string)(microtime(true) * 1000000);
        ksort($data);
        $data['signature'] = hash_hmac('sha256', implode('|', array_map('strval', array_values($data))), $secretId);
        $resp = $this->json('POST', 'https://api.antilopay.com/'.$endpoint, ['json' => $data]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('Antilopay: '.($resp['message'] ?? 'API error'));
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
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Antilopay не вернул ссылку оплаты.');
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
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Antilopay не вернул ссылку оплаты.');
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
