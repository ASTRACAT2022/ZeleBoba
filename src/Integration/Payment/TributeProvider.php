<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class TributeProvider extends AbstractProvider
{
    public function id(): string { return 'tribute'; }
    public function name(): string { return 'Tribute'; }
    public function configured(): bool
    {
        return ($this->config['TRIBUTE_ENABLED'] ?? '0') === '1'
            && ($this->config['TRIBUTE_API_KEY'] ?? '') !== ''
            && ($this->config['TRIBUTE_DONATE_LINK'] ?? '') !== '';
    }
    public function createTopup(array $topup, array $user): array
    {
        $link = (string)($this->config['TRIBUTE_DONATE_LINK'] ?? '');
        $sep = str_contains($link, '?') ? '&' : '?';
        $url = $link.$sep.'user_id='.$topup['user_id'].'&amount='.self::decimal((int)$topup['amount_kopeks']);
        return ['payment_id' => 'tribute_'.$topup['id'], 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $link = (string)($this->config['TRIBUTE_DONATE_LINK'] ?? '');
        $sep = str_contains($link, '?') ? '&' : '?';
        $url = $link.$sep.'user_id='.$order['user_id'].'&amount='.self::decimal((int)$order['price_minor']);
        return ['payment_id' => 'tribute_'.$order['id'], 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        return ['status' => 'pending', 'amount_kopeks' => 0, 'currency' => 'RUB', 'payment_id' => $paymentId, 'metadata' => []];
    }
    public function handleWebhook(Request $request): ?array
    {
        $key = (string)($this->config['TRIBUTE_API_KEY'] ?? '');
        if ($key === '') return null;
        $signature = (string)$request->headers->get('X-Tribute-Signature', '');
        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $key);
        if (!hash_equals($expected, $signature)) return null;
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $paymentId = (string)($data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => $status === 'paid' ? 'paid' : 'pending'];
    }
}
