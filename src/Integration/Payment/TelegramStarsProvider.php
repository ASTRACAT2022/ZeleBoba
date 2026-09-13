<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class TelegramStarsProvider extends AbstractProvider
{
    public function id(): string { return 'telegram_stars'; }
    public function name(): string { return 'Telegram Stars'; }
    public function configured(): bool
    {
        return ($this->config['TELEGRAM_STARS_ENABLED'] ?? '0') === '1'
            && ($this->config['TELEGRAM_BOT_TOKEN'] ?? '') !== ''
            && ($this->config['STARS_RATE_KOPEKS'] ?? '') !== '';
    }
    /** Stars per ruble rate: how many stars for 1 ruble. */
    private function starsFor(int $amountKopeks): int
    {
        $rate = (float)($this->config['STARS_RATE_KOPEKS'] ?? 0);
        if ($rate <= 0) throw new BillingError('Курс звёзд не настроен.');
        $rubles = $amountKopeks / 100;
        $stars = (int)round($rubles * $rate);
        if ($stars <= 0) throw new BillingError('Сумма слишком мала для звёзд.');
        return $stars;
    }
    private function createInvoiceLink(string $title, string $description, int $stars, string $payload): string
    {
        $token = (string)($this->config['TELEGRAM_BOT_TOKEN'] ?? '');
        $apiBase = rtrim($this->config['TELEGRAM_API_BASE'] ?? 'https://astracattg.netlify.app', '/');
        $resp = $this->json('POST', $apiBase.'/bot'.$token.'/createInvoiceLink', [
            'json' => [
                'title' => mb_substr($title, 0, 32),
                'description' => mb_substr($description, 0, 255),
                'payload' => $payload,
                'provider_token' => '',
                'currency' => 'XTR',
                'prices' => [['label' => mb_substr($title, 0, 32), 'amount' => $stars]],
            ],
        ]);
        if (!($resp['ok'] ?? false)) throw new BillingError('Telegram Stars: '.($resp['description'] ?? 'API error'));
        return (string)($resp['result'] ?? '');
    }
    public function createTopup(array $topup, array $user): array
    {
        $stars = $this->starsFor((int)$topup['amount_kopeks']);
        $url = $this->createInvoiceLink('Пополнение баланса', 'Пополнение баланса VPN', $stars, 'topup:'.$topup['id']);
        return ['payment_id' => 'stars_'.$topup['id'], 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $stars = $this->starsFor((int)$order['price_minor']);
        $url = $this->createInvoiceLink('Подписка '.$order['plan_name'], 'Подписка: '.$order['plan_name'], $stars, 'order:'.$order['id']);
        return ['payment_id' => 'stars_'.$order['id'], 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        // Stars payments are confirmed via Telegram pre_checkout_query / successful_payment updates.
        return ['status' => 'pending', 'amount_kopeks' => 0, 'currency' => 'XTR', 'payment_id' => $paymentId, 'metadata' => []];
    }
    public function handleWebhook(Request $request): ?array
    {
        // Stars are delivered through the Telegram webhook (successful_payment), not a separate endpoint.
        return null;
    }
}
