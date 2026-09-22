<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class ReferralService
{
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet, private ?array $config = null) {}
    /** Generate a unique referral code for a user. */
    public function ensureCode(string $userId): string
    {
        $existing = $this->db->one('SELECT referral_code FROM users WHERE id=?', [$userId]);
        if ($existing && $existing['referral_code'] !== null) return $existing['referral_code'];
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        } while ($this->db->one('SELECT id FROM users WHERE referral_code=?', [$code]));
        $this->db->execute('UPDATE users SET referral_code=? WHERE id=?', [$code, $userId]);
        return $code;
    }
    /** Attach a referrer to a user (idempotent, self-referral blocked). Returns referrer id or null. */
    public function attachReferrer(string $userId, string $referralCode): ?string
    {
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$userId]);
        if (!$user || $user['referred_by_id'] !== null) return null;
        $referrer = $this->db->one('SELECT * FROM users WHERE referral_code=?', [mb_strtoupper(trim($referralCode))]);
        if (!$referrer) return null;
        if ($referrer['id'] === $userId) return null;
        if ($referrer['email'] && $user['email'] && mb_strtolower($referrer['email']) === mb_strtolower($user['email'])) return null;
        $this->db->transaction(function () use ($userId, $referrer) {
            $claimed = $this->db->execute('UPDATE users SET referred_by_id=? WHERE id=? AND referred_by_id IS NULL', [$referrer['id'], $userId]);
            if ($claimed) {
                $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $userId, 'referral.attached', $referrer['id'], time()]);
            }
        });
        return $referrer['id'];
    }
    public function processSettledTopup(string $topupId): void
    {
        $this->db->transaction(function() use ($topupId) {
            $topup=$this->db->one('SELECT * FROM topups WHERE id=?'.$this->db->lock(),[$topupId]);
            if (!$topup || $topup['status']!=='paid' || (int)$topup['referral_processed']===1) return;
            $this->processTopup($topup['user_id'],(int)$topup['amount_kopeks'],(int)$topup['referral_first']===1);
            $this->db->execute('UPDATE topups SET referral_processed=1 WHERE id=?',[$topupId]);
        });
    }
    /** Process a topup of a referred user: award commission to the referrer chain. */
    public function processTopup(string $userId, int $amountKopeks, ?bool $firstPayment=null): void
    {
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$userId]);
        if (!$user || $user['referred_by_id'] === null) return;
        $referrer = $this->db->one('SELECT * FROM users WHERE id=?', [$user['referred_by_id']]);
        if (!$referrer) return;
        $percent = $this->commissionPercent($referrer, $firstPayment ?? (int)$user['has_made_first_topup'] === 0);
        $commission = intdiv($amountKopeks * $percent, 100);
        $minTopup = (int)($this->config['REFERRAL_MINIMUM_TOPUP_KOPEKS'] ?? 10000);
        $firstBonus = (int)($this->config['REFERRAL_FIRST_TOPUP_BONUS_KOPEKS'] ?? 10000);
        $inviterBonus = (int)($this->config['REFERRAL_INVITER_BONUS_KOPEKS'] ?? 10000);
        $this->db->transaction(function () use ($user, $referrer, $amountKopeks, $percent, $commission, $minTopup, $firstBonus, $inviterBonus, $firstPayment) {
            $isFirst = $firstPayment===true && $amountKopeks >= $minTopup;
            if ($firstPayment===null && (int)$user['has_made_first_topup'] === 0 && $amountKopeks >= $minTopup) {
                $claimed = $this->db->execute('UPDATE users SET has_made_first_topup=1 WHERE id=? AND has_made_first_topup=0', [$user['id']]);
                $isFirst = $claimed > 0;
            }
            if ($isFirst) {
                if ($firstBonus > 0) {
                    $this->wallet->credit($user['id'], $firstBonus, 'referral_reward', 'Бонус за первое пополнение по реферальной программе');
                }
                $total = $inviterBonus + $commission;
                if ($total > 0) {
                    $this->wallet->credit($referrer['id'], $total, 'referral_reward', 'Бонус за первое пополнение реферала');
                    $this->db->execute('INSERT INTO referral_earnings VALUES(?,?,?,?,?,?)', [Database::id(), $referrer['id'], $user['id'], $total, 'referral_first_topup', time()]);
                }
            } elseif ($commission > 0) {
                if ($this->commissionLimitReached($referrer['id'], $user['id'])) return;
                $this->wallet->credit($referrer['id'], $commission, 'referral_reward', 'Комиссия '.$percent.'% с пополнения');
                $this->db->execute('INSERT INTO referral_earnings VALUES(?,?,?,?,?,?)', [Database::id(), $referrer['id'], $user['id'], $commission, 'referral_commission_topup', time()]);
            }
        });
    }
    private function commissionPercent(array $referrer, bool $isFirstPayment): int
    {
        $base = (int)($referrer['referral_commission_percent'] ?? $this->config['REFERRAL_COMMISSION_PERCENT'] ?? 25);
        if ($isFirstPayment) {
            $first = $this->config['REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT'] ?? null;
            if ($first !== null && $first !== '') return (int)$first;
        }
        $tiers = (string)($this->config['REFERRAL_RECURRING_COMMISSION_TIERS'] ?? '');
        if ($tiers === '') return $base;
        $paid = (int)($this->db->one('SELECT COUNT(DISTINCT referral_id) AS c FROM referral_earnings WHERE user_id=? AND reason IN (\'referral_first_topup\',\'referral_commission_topup\')', [$referrer['id']])['c'] ?? 0);
        $selected = $base;
        foreach (explode(',', $tiers) as $tier) {
            [$threshold, $percent] = array_map('intval', explode(':', $tier));
            if ($paid >= $threshold) $selected = $percent; else break;
        }
        return $selected;
    }
    private function commissionLimitReached(string $referrerId, string $referralId): bool
    {
        $max = (int)($this->config['REFERRAL_MAX_COMMISSION_PAYMENTS'] ?? 0);
        if ($max <= 0) return false;
        $count = (int)($this->db->one('SELECT COUNT(*) AS c FROM referral_earnings WHERE user_id=? AND referral_id=? AND reason=\'referral_commission_topup\'', [$referrerId, $referralId])['c'] ?? 0);
        return $count >= $max;
    }
    public function stats(string $userId): array
    {
        $code = $this->ensureCode($userId);
        $referrals = $this->db->all('SELECT id,email,telegram_id,created_at,has_made_first_topup FROM users WHERE referred_by_id=? ORDER BY created_at DESC', [$userId]);
        $earnings = (int)($this->db->one('SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM referral_earnings WHERE user_id=?', [$userId])['s'] ?? 0);
        $paid = (int)($this->db->one('SELECT COUNT(*) AS c FROM users WHERE referred_by_id=? AND has_made_first_topup=1', [$userId])['c'] ?? 0);
        return ['code' => $code, 'referrals' => $referrals, 'earnings_kopeks' => $earnings, 'paid_referrals' => $paid];
    }
    /** Create a withdrawal request. */
    public function requestWithdrawal(string $userId, int $amountKopeks, string $details): array
    {
        if (($this->config['REFERRAL_WITHDRAWAL_ENABLED'] ?? '0') !== '1') throw new BillingError('Вывод средств отключён.');
        $min = (int)($this->config['REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS'] ?? 100000);
        if ($amountKopeks < $min) throw new BillingError('Минимальная сумма вывода: '.($min / 100).' ₽.');
        if (mb_strlen($details) < 5 || mb_strlen($details) > 500) throw new BillingError('Укажите реквизиты для вывода (5–500 символов).');
        $cooldown = (int)($this->config['REFERRAL_WITHDRAWAL_COOLDOWN_DAYS'] ?? 30) * 86400;
        return $this->db->transaction(function () use ($userId, $amountKopeks, $details, $cooldown) {
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$userId]);
            $last = $this->db->one("SELECT created_at FROM withdrawal_requests WHERE user_id=? AND status IN ('pending','approved') ORDER BY created_at DESC LIMIT 1", [$userId]);
            if ($last && time() - (int)$last['created_at'] < $cooldown) throw new BillingError('Заявка на вывод уже подана. Попробуйте позже.');
            $user = $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [$userId]);
            if (!$user) throw new BillingError('Аккаунт не найден.');
            $earnings = (int)($this->db->one('SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM referral_earnings WHERE user_id=?', [$userId])['s'] ?? 0);
            $withdrawn = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM withdrawal_requests WHERE user_id=? AND status IN ('pending','approved','paid')", [$userId])['s'] ?? 0);
            $available = $earnings - $withdrawn;
            if ($amountKopeks > $available) throw new BillingError('Недостаточно реферального баланса. Доступно: '.($available / 100).' ₽.');
            $this->wallet->debit($userId,$amountKopeks,'referral_withdrawal','Резерв средств на вывод');
            $id = Database::id();
            $now = time();
            $this->db->execute('INSERT INTO withdrawal_requests(id,user_id,amount_kopeks,status,payment_details,risk_score,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)', [$id, $userId, $amountKopeks, 'pending', $details, $this->riskScore($userId), $now, $now]);
            $this->db->execute('UPDATE withdrawal_requests SET wallet_reserved=1 WHERE id=?',[$id]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $userId, 'withdrawal.requested', $id, $now]);
            return $this->db->one('SELECT * FROM withdrawal_requests WHERE id=?', [$id]);
        });
    }
    private function riskScore(string $userId): int
    {
        $suspiciousMin = (int)($this->config['REFERRAL_WITHDRAWAL_SUSPICIOUS_MIN_DEPOSIT_KOPEKS'] ?? 50000);
        $maxPerMonth = (int)($this->config['REFERRAL_WITHDRAWAL_SUSPICIOUS_MAX_DEPOSITS_PER_MONTH'] ?? 10);
        $score = 0;
        $referrals = $this->db->all('SELECT id FROM users WHERE referred_by_id=?', [$userId]);
        foreach ($referrals as $r) {
            $topups = (int)($this->db->one('SELECT COUNT(*) AS c FROM topups WHERE user_id=? AND status=\'paid\' AND paid_at>?', [$r['id'], time() - 30 * 86400])['c'] ?? 0);
            if ($topups > $maxPerMonth) $score += 30;
            $sum = (int)($this->db->one('SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM topups WHERE user_id=? AND status=\'paid\'', [$r['id']])['s'] ?? 0);
            if ($sum >= $suspiciousMin) $score += 20;
        }
        return min(100, $score);
    }
    public function withdrawals(string $userId): array
    {
        return $this->db->all('SELECT * FROM withdrawal_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 20', [$userId]);
    }
    public function allWithdrawals(string $status = 'pending'): array
    {
        return $this->db->all('SELECT w.*,u.email,u.telegram_id FROM withdrawal_requests w JOIN users u ON u.id=w.user_id WHERE w.status=? ORDER BY w.created_at', [$status]);
    }
    public function processWithdrawal(string $id, string $status, ?string $comment, string $adminId): void
    {
        if (!in_array($status, ['approved', 'rejected', 'paid'], true)) throw new BillingError('Некорректный статус.');
        $this->db->transaction(function () use ($id, $status, $comment, $adminId) {
            $row = $this->db->one('SELECT * FROM withdrawal_requests WHERE id=?' . $this->db->lock(), [$id]);
            if (!$row) throw new BillingError('Заявка не найдена.');
            if ($row['status']===$status) return;
            if (!in_array($row['status'],['pending','approved'],true) || ($row['status']==='approved' && $status==='approved')) throw new BillingError('Заявка уже обработана.');
            // Older requests were not reserved; charge them before approval/payment.
            if ($status!=='rejected' && !(int)$row['wallet_reserved']) {
                $this->wallet->debit($row['user_id'],(int)$row['amount_kopeks'],'referral_withdrawal','Резерв средств на вывод');
                $this->db->execute('UPDATE withdrawal_requests SET wallet_reserved=1 WHERE id=?',[$id]);
            }
            if ($status==='rejected' && (int)$row['wallet_reserved']) $this->wallet->credit($row['user_id'],(int)$row['amount_kopeks'],'refund','Возврат резерва отклонённой заявки');
            $this->db->execute('UPDATE withdrawal_requests SET status=?,processed_by=?,processed_at=?,admin_comment=?,updated_at=? WHERE id=?', [$status, $adminId, time(), $comment, time(), $id]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $adminId, 'withdrawal.'.$status, $id, time()]);
        });
    }
}
