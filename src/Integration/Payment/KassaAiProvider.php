<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class KassaAiProvider extends AbstractProvider
{
    public function id(): string { return 'kassa_ai'; }
    public function name(): string { return 'Kassa AI'; }
    public function configured(): bool
    {
        return ($this->config['KASSA_AI_ENABLED'] ?? '0') === '1'
            && ($this->config['KASSA_AI_SHOP_ID'] ?? '') !== ''
            && ($this->config['KASSA_AI_API_KEY'] ?? '') !== '';
    }
    private function api(string $path, array $params): array
    {
        $apiKey = (string)($this->config['KASSA_AI_API_KEY'] ?? '');
        if ($apiKey === '') throw new BillingError('Kassa AI не настроен.');
        if (!isset($params['nonce'])) $params['nonce'] = (string)(microtime(true) * 1000000);
        $sorted = $params; ksort($sorted);
        $params['signature'] = hash_hmac('sha256', implode('|', array_map('strval', array_values($sorted))), $apiKey);
        $resp = $this->json('POST', 'https://api.fk.life/v1/'.$path, ['json' => $params]);
        if (($resp['type'] ?? '') === 'error' || isset($resp['error'])) {
            throw new BillingError('Kassa AI: '.($resp['error'] ?? $resp['message'] ?? 'API error'));
        }
        return $resp;
    }
    private function createInvoice(array $entity, string $kind): array
    {
        $email = (string)($entity['receipt_email'] ?? '');
        if ($email === '' && $kind === 'topup') {
            $user = $this->config['_user'] ?? null;
            $email = $user['email'] ?? '';
            if ($email === '' && !empty($user['telegram_id'])) $email = $user['telegram_id'].'@telegram.org';
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BillingError('Некорректный email для Kassa AI.');
        $amount = $kind === 'topup' ? (int)$entity['amount_kopeks'] : (int)$entity['price_minor'];
        $params = [
            'shopId' => (int)$this->config['KASSA_AI_SHOP_ID'],
            'nonce' => (string)(microtime(true) * 1000000),
            'paymentId' => $entity['id'],
            'i' => (int)($this->config['KASSA_AI_PAYMENT_SYSTEM_ID'] ?? 44),
            'email' => $email,
            'ip' => '8.8.8.8',
            'amount' => self::decimal($amount),
            'currency' => 'RUB',
        ];
        $data = $this->api('orders/create', $params);
        $location = $data['location'] ?? null;
        $orderId = $data['orderId'] ?? null;
        if (!$location || !str_starts_with($location, 'https://')) throw new BillingError('Kassa AI не вернул ссылку оплаты.');
        return ['payment_id' => (string)($orderId ?? $entity['id']), 'checkout_url' => $location];
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
        $params = ['shopId' => (int)$this->config['KASSA_AI_SHOP_ID'], 'nonce' => (string)(microtime(true) * 1000000)];
        if (preg_match('/^[0-9]+$/D', $paymentId)) $params['orderId'] = (int)$paymentId; else $params['paymentId'] = $paymentId;
        $data = $this->api('orders', $params);
        $orders = $data['orders'] ?? [];
        if (!is_array($orders) || count($orders) === 0) {
            return ['status' => 'pending', 'amount_kopeks' => 0, 'currency' => 'RUB', 'payment_id' => $paymentId, 'metadata' => []];
        }
        $o = $orders[0];
        $status = (int)($o['status'] ?? -1);
        $amount = self::minor(self::normalizeAmount((string)($o['amount'] ?? '0')));
        return [
            'status' => $status === 1 ? 'paid' : (in_array($status, [8, 9], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => (string)($o['fk_order_id'] ?? $o['orderId'] ?? $paymentId),
            'metadata' => ['merchant_order_id' => (string)($o['merchant_order_id'] ?? '')],
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
        if ((string)($this->config['KASSA_AI_SHOP_ID'] ?? '') !== $merchantId) return null;
        $expected = md5($merchantId.':'.$amount.':'.($this->config['KASSA_AI_SECRET_WORD_2'] ?? '').':'.$orderId);
        if (!hash_equals(strtolower($expected), strtolower($sign))) return null;
        return ['payment_id' => $orderId, 'status' => 'paid'];
    }
}
