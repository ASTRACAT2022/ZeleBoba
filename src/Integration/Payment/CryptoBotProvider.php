<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class CryptoBotProvider extends AbstractProvider
{
    public function id(): string { return 'cryptobot'; }
    public function name(): string { return 'CryptoBot'; }
    public function configured(): bool
    {
        return ($this->config['CRYPTOBOT_ENABLED'] ?? '0') === '1' && ($this->config['CRYPTOBOT_API_TOKEN'] ?? '') !== '';
    }
    private function baseUrl(): string
    {
        return rtrim($this->config['CRYPTOBOT_BASE_URL'] ?? 'https://pay.crypt.bot', '/');
    }
    private function api(string $endpoint, array $data = []): array
    {
        $token = (string)($this->config['CRYPTOBOT_API_TOKEN'] ?? '');
        if ($token === '') throw new BillingError('CryptoBot не настроен.');
        $url = $this->baseUrl().'/api/'.$endpoint;
        $options = ['headers' => ['Crypto-Pay-API-Token' => $token, 'Content-Type' => 'application/json']];
        if ($data !== []) $options['json'] = $data;
        $resp = $this->json('POST', $url, $options);
        if (!($resp['ok'] ?? false)) throw new BillingError('CryptoBot: '.($resp['error'] ?? 'API error'));
        return $resp['result'] ?? [];
    }
    public function createTopup(array $topup, array $user): array
    {
        $amount = self::decimal((int)$topup['amount_kopeks']);
        $asset = (string)($this->config['CRYPTOBOT_DEFAULT_ASSET'] ?? 'USDT');
        $result = $this->api('createInvoice', [
            'currency_type' => 'crypto',
            'asset' => $asset,
            'amount' => $amount,
            'description' => 'Пополнение баланса',
            'payload' => 'topup:'.$topup['id'],
            'expires_in' => (int)($this->config['CRYPTOBOT_INVOICE_EXPIRES_HOURS'] ?? 24) * 3600,
        ]);
        $url = $result['bot_invoice_url'] ?? $result['mini_app_invoice_url'] ?? $result['web_app_invoice_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('CryptoBot не вернул ссылку оплаты.');
        return ['payment_id' => (string)$result['invoice_id'], 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $amount = self::decimal((int)$order['price_minor']);
        $asset = (string)($this->config['CRYPTOBOT_DEFAULT_ASSET'] ?? 'USDT');
        $result = $this->api('createInvoice', [
            'currency_type' => 'crypto',
            'asset' => $asset,
            'amount' => $amount,
            'description' => 'Подписка: '.$order['plan_name'],
            'payload' => 'order:'.$order['id'],
            'expires_in' => (int)($this->config['CRYPTOBOT_INVOICE_EXPIRES_HOURS'] ?? 24) * 3600,
        ]);
        $url = $result['bot_invoice_url'] ?? $result['mini_app_invoice_url'] ?? $result['web_app_invoice_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('CryptoBot не вернул ссылку оплаты.');
        return ['payment_id' => (string)$result['invoice_id'], 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('getInvoices', ['invoice_ids' => [(int)$paymentId]]);
        $invoices = $result['items'] ?? [];
        foreach ($invoices as $inv) {
            if ((string)($inv['invoice_id'] ?? '') !== $paymentId) continue;
            $status = (string)($inv['status'] ?? '');
            $amount = self::minor(self::normalizeAmount((string)($inv['amount'] ?? '0')));
            $payload = (string)($inv['payload'] ?? '');
            return [
                'status' => $status === 'paid' ? 'paid' : ($status === 'active' ? 'pending' : 'canceled'),
                'amount_kopeks' => $amount,
                'currency' => 'RUB',
                'payment_id' => $paymentId,
                'metadata' => ['payload' => $payload],
            ];
        }
        return ['status' => 'pending', 'amount_kopeks' => 0, 'currency' => 'RUB', 'payment_id' => $paymentId, 'metadata' => []];
    }
    public function handleWebhook(Request $request): ?array
    {
        $secret = (string)($this->config['CRYPTOBOT_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') return null;
        $signature = (string)$request->headers->get('crypto-pay-api-signature', '');
        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $signature)) return null;
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $update = $data['update_type'] ?? '';
        if ($update !== 'invoice_paid') return null;
        $inv = $data['payload'] ?? [];
        $paymentId = (string)($inv['invoice_id'] ?? '');
        if ($paymentId === '') return null;
        return ['payment_id' => $paymentId, 'status' => 'paid'];
    }
}
