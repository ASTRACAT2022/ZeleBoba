<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class CloudPaymentsProvider extends AbstractProvider
{
    public function id(): string { return 'cloudpayments'; }
    public function name(): string { return 'CloudPayments'; }
    public function configured(): bool
    {
        return ($this->config['CLOUDPAYMENTS_ENABLED'] ?? '0') === '1'
            && ($this->config['CLOUDPAYMENTS_PUBLIC_ID'] ?? '') !== ''
            && ($this->config['CLOUDPAYMENTS_API_SECRET'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $publicId = (string)($this->config['CLOUDPAYMENTS_PUBLIC_ID'] ?? '');
        $secret = (string)($this->config['CLOUDPAYMENTS_API_SECRET'] ?? '');
        if ($publicId === '' || $secret === '') throw new BillingError('CloudPayments не настроен.');
        $resp = $this->json('POST', 'https://api.cloudpayments.ru/'.$endpoint, [
            'auth_basic' => [$publicId, $secret],
            'json' => $data,
        ]);
        if (!($resp['Success'] ?? false)) throw new BillingError('CloudPayments: '.($resp['Message'] ?? 'API error'));
        return $resp['Model'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payments/cards/charge', [
            'Amount' => self::decimal((int)$topup['amount_kopeks']),
            'Currency' => 'RUB',
            'InvoiceId' => $topup['id'],
            'Description' => 'Пополнение баланса',
            'AccountId' => $topup['user_id'],
            'Email' => $user['email'] ?? null,
        ]);
        $url = $result['Url'] ?? $result['url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('CloudPayments не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['TransactionId'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payments/cards/charge', [
            'Amount' => self::decimal((int)$order['price_minor']),
            'Currency' => 'RUB',
            'InvoiceId' => $order['id'],
            'Description' => 'Подписка: '.$order['plan_name'],
            'AccountId' => $order['user_id'],
            'Email' => $user['email'] ?? null,
        ]);
        $url = $result['Url'] ?? $result['url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('CloudPayments не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['TransactionId'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payments/get', ['TransactionId' => (int)$paymentId]);
        $status = (string)($result['Status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['Amount'] ?? '0')));
        return [
            'status' => $status === 'Completed' ? 'paid' : ($status === 'Declined' ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => ['invoice_id' => (string)($result['InvoiceId'] ?? '')],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = $request->toArray();
        $paymentId = (string)($data['TransactionId'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['Status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => $status === 'Completed' ? 'paid' : 'pending'];
    }
}
