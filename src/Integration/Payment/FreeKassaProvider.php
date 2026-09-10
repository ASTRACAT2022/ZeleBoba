<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class FreeKassaProvider extends AbstractProvider
{
    private const API = 'https://api.fk.life/v1/';
    public function id(): string { return 'freekassa'; }
    public function name(): string { return 'FreeKassa'; }
    public function configured(): bool
    {
        return ($this->config['FREEKASSA_SHOP_ID'] ?? '') !== '' && ($this->config['FREEKASSA_API_KEY'] ?? '') !== '';
    }
    private function api(string $path, array $params): array
    {
        $apiKey = (string)($this->config['FREEKASSA_API_KEY'] ?? '');
        if ($apiKey === '') throw new BillingError('FreeKassa не настроена.');
        if (!isset($params['nonce'])) $params['nonce'] = $this->nonce();
        $sorted = $params; ksort($sorted);
        $values = array_map(fn($v) => (string)$v, array_values($sorted));
        $params['signature'] = hash_hmac('sha256', implode('|', $values), $apiKey);
        $resp = $this->json('POST', self::API.$path, ['json' => $params]);
        if (($resp['type'] ?? '') === 'error' || isset($resp['error'])) {
            throw new BillingError('FreeKassa: '.($resp['error'] ?? $resp['message'] ?? 'API error'));
        }
        return $resp;
    }
    private function nonce(): int
    {
        static $last = 0;
        $now = (int)(microtime(true) * 1000000);
        if ($now <= $last) $now = $last + 1;
        $last = $now;
        return $now;
    }
    private function createInvoice(array $entity, string $kind): array
    {
        if (time() - (int)$entity['created_at'] > 23 * 3600) throw new BillingError('Требуется ручная сверка платежа.');
        if ((string)$entity['provider'] !== (string)($this->config['FREEKASSA_SHOP_ID'] ?? '')) throw new BillingError('Магазин заказа не соответствует настройкам.');
        if (!$this->config['FREEKASSA_SHOP_ID'] || !$this->config['FREEKASSA_API_KEY']) throw new BillingError('FreeKassa не настроена.');
        $email = (string)($entity['receipt_email'] ?? '');
        if ($email === '' && $kind === 'topup') {
            $user = $this->config['_user'] ?? null;
            $email = $user['email'] ?? '';
            if ($email === '' && !empty($user['telegram_id'])) $email = $user['telegram_id'].'@telegram.org';
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BillingError('Некорректный email для FreeKassa.');
        $ip = (string)($entity['client_ip'] ?? '');
        if (!$ip || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) $ip = '8.8.8.8';
        $amount = $kind === 'topup' ? (int)$entity['amount_kopeks'] : (int)$entity['price_minor'];
        $params = [
            'shopId' => (int)$this->config['FREEKASSA_SHOP_ID'],
            'nonce' => $this->nonce(),
            'paymentId' => $entity['id'],
            'i' => (int)($this->config['FREEKASSA_PAYMENT_ID'] ?? 44),
            'email' => $email,
            'ip' => $ip,
            'amount' => self::decimal($amount),
            'currency' => $entity['currency'] ?? 'RUB',
        ];
        $data = $this->api('orders/create', $params);
        $location = $data['location'] ?? null;
        $fkOrderId = $data['orderId'] ?? null;
        if (!$location || !is_string($location) || !str_starts_with($location, 'https://')) throw new BillingError('Провайдер не вернул ссылку оплаты.');
        if ($fkOrderId === null) throw new BillingError('Провайдер не вернул номер заказа.');
        return ['payment_id' => (string)$fkOrderId, 'checkout_url' => $location];
    }
    public function createTopup(array $topup, array $user): array
    {
        $this->config['_user'] = $user;
        return $this->createInvoice($topup, 'topup');
    }
    public function createOrder(array $order, array $user): array
    {
        $this->config['_user'] = $user;
        return $this->createInvoice($order, 'order');
    }
    public function verify(string $paymentId): array
    {
        if (!$this->config['FREEKASSA_SHOP_ID'] || !$this->config['FREEKASSA_API_KEY']) throw new BillingError('FreeKassa не настроена.');
        $isNumeric = preg_match('/^[0-9]+$/D', $paymentId);
        $params = ['shopId' => (int)$this->config['FREEKASSA_SHOP_ID'], 'nonce' => $this->nonce()];
        if ($isNumeric) $params['orderId'] = (int)$paymentId; else $params['paymentId'] = $paymentId;
        $data = $this->api('orders', $params);
        $orders = $data['orders'] ?? [];
        if (!is_array($orders) || count($orders) === 0) {
            return ['status' => 'pending', 'amount_kopeks' => 0, 'currency' => 'RUB', 'payment_id' => $paymentId, 'metadata' => []];
        }
        $o = $orders[0];
        $status = (int)($o['status'] ?? -1);
        $amount = self::minor(self::normalizeAmount((string)($o['amount'] ?? '0')));
        $currency = (string)($o['currency'] ?? 'RUB');
        $fkId = (string)($o['fk_order_id'] ?? $o['orderId'] ?? $paymentId);
        return [
            'status' => $status === 1 ? 'paid' : (in_array($status, [8, 9], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => $currency,
            'payment_id' => $fkId,
            'metadata' => ['merchant_order_id' => (string)($o['merchant_order_id'] ?? $o['paymentId'] ?? '')],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = array_merge($request->query->all(), $request->request->all());
        $merchantId = (string)($data['MERCHANT_ID'] ?? '');
        $amount = (string)($data['AMOUNT'] ?? '');
        $orderId = (string)($data['MERCHANT_ORDER_ID'] ?? '');
        $sign = (string)($data['SIGN'] ?? '');
        if ($merchantId === '' || $amount === '' || $orderId === '' || $sign === '') return null;
        if ((string)($this->config['FREEKASSA_SHOP_ID'] ?? '') !== $merchantId) return null;
        $expected = md5($merchantId.':'.$amount.':'.($this->config['FREEKASSA_SECRET2'] ?? '').':'.$orderId);
        if (!hash_equals(strtolower($expected), strtolower($sign))) return null;
        return ['payment_id' => $orderId, 'status' => 'paid', 'amount' => $amount];
    }
}
