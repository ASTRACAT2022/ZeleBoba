<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class YooKassaProvider extends AbstractProvider
{
    public function id(): string { return 'yookassa'; }
    public function name(): string { return 'ЮKassa'; }
    public function configured(): bool
    {
        return ($this->config['YOOKASSA_SHOP_ID'] ?? '') !== '' && ($this->config['YOOKASSA_SECRET'] ?? '') !== '';
    }
    private function api(string $method, string $path, array $options = []): array
    {
        $shopId = (string)($this->config['YOOKASSA_SHOP_ID'] ?? '');
        $secret = (string)($this->config['YOOKASSA_SECRET'] ?? '');
        if ($shopId === '' || $secret === '') throw new BillingError('ЮKassa не настроена.');
        $options = array_merge(['auth_basic' => [$shopId, $secret], 'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 0], $options);
        return $this->json($method, 'https://api.yookassa.ru/v3/'.$path, $options);
    }
    private function buildBody(array $entity, string $kind): array
    {
        $amount = $kind === 'topup' ? (int)$entity['amount_kopeks'] : (int)$entity['price_minor'];
        $currency = $kind === 'topup' ? ($entity['currency'] ?? 'RUB') : ($entity['currency'] ?? 'RUB');
        $id = $entity['id'];
        $returnUrl = $kind === 'topup'
            ? rtrim($this->config['APP_URL'] ?? 'http://127.0.0.1:8080', '/').'/balance'
            : ($entity['return_url'] ?: rtrim($this->config['APP_URL'] ?? 'http://127.0.0.1:8080', '/').'/orders/'.$id);
        $description = $kind === 'topup' ? 'Пополнение баланса' : 'Подписка: '.$entity['plan_name'];
        $body = [
            'amount' => ['value' => self::decimal($amount), 'currency' => $currency],
            'capture' => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'description' => $description,
            'metadata' => $kind === 'topup' ? ['topup_id' => $id, 'type' => 'balance_topup'] : ['order_id' => $id],
        ];
        if ($kind === 'order' && (int)($entity['receipt_enabled'] ?? 0) === 1) {
            $body['receipt'] = [
                'customer' => ['email' => $entity['receipt_email']],
                'items' => [[
                    'description' => mb_substr('Подписка '.$entity['plan_name'], 0, 128),
                    'quantity' => '1.00',
                    'amount' => $body['amount'],
                    'vat_code' => (int)($entity['vat_code'] ?? 1),
                    'payment_mode' => 'full_payment',
                    'payment_subject' => 'service',
                ]],
            ];
            if ($entity['tax_system']) $body['receipt']['tax_system_code'] = (int)$entity['tax_system'];
        }
        return $body;
    }
    public function createTopup(array $topup, array $user): array
    {
        if (time() - (int)$topup['created_at'] > 23 * 3600) throw new BillingError('Требуется ручная сверка платежа.');
        $data = $this->api('POST', 'payments', [
            'headers' => ['Idempotence-Key' => 'topup-'.$topup['id']],
            'json' => $this->buildBody($topup, 'topup'),
        ]);
        if (($this->config['APP_ENV'] ?? 'dev') === 'prod' && ($data['test'] ?? true) !== false) throw new BillingError('Магазин создал тестовый платёж в боевом режиме.');
        $url = $data['confirmation']['confirmation_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Провайдер не вернул ссылку оплаты.');
        return ['payment_id' => (string)$data['id'], 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        if (time() - (int)$order['created_at'] > 23 * 3600) throw new BillingError('Требуется ручная сверка платежа.');
        if ((string)$order['provider_account'] !== (string)($this->config['YOOKASSA_SHOP_ID'] ?? '')) throw new BillingError('Магазин заказа не соответствует настройкам.');
        $data = $this->api('POST', 'payments', [
            'headers' => ['Idempotence-Key' => $order['id']],
            'json' => $this->buildBody($order, 'order'),
        ]);
        if (($this->config['APP_ENV'] ?? 'dev') === 'prod' && ($data['test'] ?? true) !== false) throw new BillingError('Магазин создал тестовый платёж в боевом режиме.');
        $url = $data['confirmation']['confirmation_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Провайдер не вернул ссылку оплаты.');
        return ['payment_id' => (string)$data['id'], 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $paymentId)) throw new BillingError('Некорректный платёж.');
        $data = $this->api('GET', 'payments/'.rawurlencode($paymentId));
        if (($data['id'] ?? null) !== $paymentId) throw new BillingError('Некорректный ответ провайдера.');
        $status = (string)($data['status'] ?? '');
        $amount = self::minor((string)($data['amount']['value'] ?? '0'));
        $currency = (string)($data['amount']['currency'] ?? 'RUB');
        $metadata = $data['metadata'] ?? [];
        return [
            'status' => $status === 'succeeded' && ($data['paid'] ?? false) === true ? 'paid' : ($status === 'canceled' ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => $currency,
            'payment_id' => $paymentId,
            'metadata' => is_array($metadata) ? $metadata : [],
            'test' => (bool)($data['test'] ?? true),
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = $request->toArray();
        if (!in_array($data['event'] ?? '', ['payment.succeeded', 'payment.canceled'], true)) return null;
        $paymentId = (string)($data['object']['id'] ?? '');
        if ($paymentId === '') return null;
        return ['payment_id' => $paymentId, 'status' => $data['event'] === 'payment.succeeded' ? 'paid' : 'canceled'];
    }
}
