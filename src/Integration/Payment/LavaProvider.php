<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class LavaProvider extends AbstractProvider
{
    public function id(): string { return 'lava'; }
    public function name(): string { return 'Lava Business'; }
    public function configured(): bool
    {
        return ($this->config['LAVA_ENABLED'] ?? '0') === '1'
            && ($this->config['LAVA_SHOP_ID'] ?? '') !== ''
            && ($this->config['LAVA_SECRET_KEY'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $shopId = (string)($this->config['LAVA_SHOP_ID'] ?? '');
        $secret = (string)($this->config['LAVA_SECRET_KEY'] ?? '');
        if ($shopId === '' || $secret === '') throw new BillingError('Lava не настроен.');
        $data['shopId'] = $shopId;
        $data['nonce'] = (string)(microtime(true) * 1000000);
        ksort($data);
        $sign = hash_hmac('sha256', implode('|', array_map('strval', array_values($data))), $secret);
        $data['signature'] = $sign;
        $resp = $this->json('POST', 'https://gate.lava.ru/business/'.$endpoint, ['json' => $data]);
        if (($resp['status'] ?? '') !== 'success') throw new BillingError('Lava: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? [];
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('invoice/create', [
            'sum' => self::decimal((int)$topup['amount_kopeks']),
            'orderId' => $topup['id'],
            'memo' => 'Пополнение баланса',
            'failUrl' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
            'successUrl' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
            'hookUrl' => rtrim($this->config['APP_URL'] ?? '', '/').'/webhooks/lava',
        ]);
        $url = $result['url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Lava не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('invoice/create', [
            'sum' => self::decimal((int)$order['price_minor']),
            'orderId' => $order['id'],
            'memo' => 'Подписка: '.$order['plan_name'],
            'failUrl' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
            'successUrl' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
            'hookUrl' => rtrim($this->config['APP_URL'] ?? '', '/').'/webhooks/lava',
        ]);
        $url = $result['url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Lava не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('invoice/info', ['id' => $paymentId]);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['sum'] ?? '0')));
        return [
            'status' => $status === 'success' ? 'paid' : (in_array($status, ['cancel', 'cancelled', 'expired', 'error', 'failed'], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => ['order_id' => (string)($result['orderId'] ?? '')],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $secret = (string)($this->config['LAVA_SECRET_KEY'] ?? '');
        if ($secret === '') return null;
        $data = $request->toArray();
        $signature = (string)($data['signature'] ?? '');
        unset($data['signature']);
        ksort($data);
        $expected = hash_hmac('sha256', implode('|', array_map('strval', array_values($data))), $secret);
        if (!hash_equals($expected, $signature)) return null;
        $status = (string)($data['status'] ?? '');
        $paymentId = (string)($data['id'] ?? '');
        if ($paymentId === '') return null;
        return ['payment_id' => $paymentId, 'status' => $status === 'success' ? 'paid' : 'pending'];
    }
}
