<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class PlategaProvider extends AbstractProvider
{
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
    private function api(string $endpoint, array $data = []): array
    {
        $merchant = (string)($this->config['PLATEGA_MERCHANT_ID'] ?? '');
        $secret = (string)($this->config['PLATEGA_SECRET'] ?? '');
        $base = rtrim((string)($this->config['PLATEGA_API_BASE'] ?? 'https://api.platega.com'), '/');
        if ($merchant === '' || $secret === '' || $base === '') throw new BillingError('Platega не настроен.');
        $data['merchant_id'] = $merchant;
        $data['signature'] = hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_UNICODE), $secret);
        $resp = $this->json('POST', $base.'/v1/'.$endpoint, ['json' => $data]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('Platega: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payment', [
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'currency' => 'RUB',
            'order_id' => $topup['id'],
            'description' => 'Пополнение баланса',
            'callback_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/webhooks/platega',
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
            'failed_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Platega не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payment', [
            'amount' => self::decimal((int)$order['price_minor']),
            'currency' => 'RUB',
            'order_id' => $order['id'],
            'description' => 'Подписка: '.$order['plan_name'],
            'callback_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/webhooks/platega',
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
            'failed_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Platega не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payment/'.$paymentId);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => $status === 'CONFIRMED' ? 'paid' : (in_array($status, ['FAILED', 'CANCELED', 'EXPIRED'], true) ? 'canceled' : 'pending'),
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
        return ['payment_id' => $paymentId, 'status' => $status === 'CONFIRMED' ? 'paid' : 'pending'];
    }
}
