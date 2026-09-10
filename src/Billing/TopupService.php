<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class TopupService
{
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet, private string $provider, private ?array $config = null) {}
    /** Create a topup order. Returns the topup row. */
    public function create(string $userId, int $amountKopeks, string $key, ?string $provider = null): array
    {
        if ($this->config !== null) {
            foreach (\App\Settings\Settings::purchaseErrors($this->config) as $error) throw new BillingError($error);
        }
        if ($amountKopeks < 100 || $amountKopeks > 100000000) throw new BillingError('Сумма пополнения: от 1 до 1 000 000 ₽.');
        if (!preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D', $key)) throw new BillingError('Некорректный ключ операции.');
        $effective = $provider !== null && $provider !== '' ? $provider : $this->provider;
        if (!in_array($effective, ['demo','yookassa','freekassa','cryptobot','telegram_stars','lava','wata','heleket','platega','tribute','mulenpay','pal24','cloudpayments','kassa_ai','riopay','severpay','paypear','rollypay','overpay','aurapay','etoplatezhi','antilopay','jupiter','donut','cispay','tabpay','paritypay'], true)) throw new BillingError('Некорректный платёжный провайдер.');
        return $this->db->transaction(function () use ($userId, $amountKopeks, $key, $effective) {
            if (!$this->db->one('SELECT id FROM users WHERE id=? AND disabled=0' . $this->db->lock(), [$userId])) throw new BillingError('Аккаунт не найден.');
            $existing = $this->db->one('SELECT * FROM topups WHERE user_id=? AND idempotency_key=?', [$userId, $key]);
            if ($existing) {
                if ((int)$existing['amount_kopeks'] !== $amountKopeks) throw new BillingError('Этот ключ уже использован для другой суммы.');
                return $existing;
            }
            $id = Database::id();
            $this->db->execute(
                "INSERT INTO topups(id,user_id,amount_kopeks,currency,status,provider,idempotency_key,created_at) VALUES(?,?,?,?,'pending',?,?,?)",
                [$id, $userId, $amountKopeks, 'RUB', $effective, $key, time()]
            );
            $this->outbox->enqueue('topup.create', 'topup-checkout:' . $id, ['topup_id' => $id]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $userId, 'topup.created', $id, time()]);
            return $this->db->one('SELECT * FROM topups WHERE id=?', [$id]);
        });
    }
    /** Settle a topup after provider verification. Credits the wallet exactly once. */
    public function settle(string $topupId, string $provider, string $paymentId, int $amount, string $currency): void
    {
        $this->db->transaction(function () use ($topupId, $provider, $paymentId, $amount, $currency) {
            $topup = $this->db->one('SELECT * FROM topups WHERE id=?' . $this->db->lock(), [$topupId]);
            if (!$topup || $topup['provider'] !== $provider || (int)$topup['amount_kopeks'] !== $amount || $topup['currency'] !== $currency) throw new BillingError('Платёж не соответствует пополнению.');
            if ($topup['status'] !== 'pending') return;
            $dup = $this->db->one('SELECT id FROM topups WHERE provider_payment_id=? AND id<>?', [$paymentId, $topupId]);
            if ($dup) throw new BillingError('Платёж уже принадлежит другому пополнению.');
            $now = time();
            $this->db->execute("UPDATE topups SET status='paid',provider_payment_id=?,paid_at=? WHERE id=?", [$paymentId, $now, $topupId]);
            $this->wallet->credit($topup['user_id'], $amount, 'balance_topup', 'Пополнение баланса', $provider, $paymentId);
            $this->db->execute('UPDATE users SET has_made_first_topup=1 WHERE id=?', [$topup['user_id']]);
            $this->outbox->enqueue('topup.after', 'topup-after:' . $topupId, ['topup_id' => $topupId, 'user_id' => $topup['user_id']]);
            $this->outbox->enqueue('referral.topup', 'referral-topup:' . $topupId, ['user_id' => $topup['user_id'], 'amount_kopeks' => $amount]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), 'provider:' . $provider, 'topup.settled', $topupId, $now]);
        });
    }
    public function cancel(string $topupId): void
    {
        $this->db->execute("UPDATE topups SET status='canceled' WHERE id=? AND status='pending'", [$topupId]);
    }
}
