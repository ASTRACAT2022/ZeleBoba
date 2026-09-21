<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
final class Wallet
{
    public const TYPES = [
        'balance_topup','subscription_purchase','subscription_renewal','subscription_daily','trial_conversion',
        'referral_reward','referral_withdrawal','traffic_topup','device_addon',
        'gift_purchase','promo_credit','manual_adjust','refund',
    ];
    public function __construct(private Database $db) {}
    /** Credit user balance inside the caller's transaction. Returns new balance. */
    public function credit(string $userId, int $amountKopeks, string $type, string $description, ?string $paymentMethod = null, ?string $externalId = null, bool $completed = true): array
    {
        return $this->db->transaction(function() use ($userId,$amountKopeks,$type,$description,$paymentMethod,$externalId,$completed) {
            if ($amountKopeks <= 0) throw new BillingError('Сумма должна быть положительной.');
            if (!in_array($type, self::TYPES, true)) throw new BillingError('Некорректный тип операции.');
            $now = time();
            $this->db->execute('UPDATE users SET balance_kopeks = balance_kopeks + ? WHERE id = ?', [$amountKopeks, $userId]);
            $tx = Database::id();
            $this->db->execute(
                'INSERT INTO transactions(id,seq,user_id,type,amount_kopeks,description,payment_method,external_id,is_completed,created_at,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [$tx, $this->nextSeq(), $userId, $type, $amountKopeks, $description, $paymentMethod, $externalId, (int)$completed, $now, $completed ? $now : null]
            );
            return $this->balance($userId);
        });
    }
    /** Debit user balance inside the caller's transaction. Returns new balance. */
    public function debit(string $userId, int $amountKopeks, string $type, string $description, ?string $paymentMethod = null, ?string $externalId = null): array
    {
        return $this->db->transaction(function() use ($userId,$amountKopeks,$type,$description,$paymentMethod,$externalId) {
            if ($amountKopeks <= 0) throw new BillingError('Сумма должна быть положительной.');
            if (!in_array($type, self::TYPES, true)) throw new BillingError('Некорректный тип операции.');
            $user = $this->db->one('SELECT balance_kopeks FROM users WHERE id = ?' . $this->db->lock(), [$userId]);
            if (!$user) throw new BillingError('Аккаунт не найден.');
            if ((int)$user['balance_kopeks'] < $amountKopeks) throw new BillingError('Недостаточно средств на балансе.');
            $now = time();
            $this->db->execute('UPDATE users SET balance_kopeks = balance_kopeks - ? WHERE id = ?', [$amountKopeks, $userId]);
            $this->db->execute(
                'INSERT INTO transactions(id,seq,user_id,type,amount_kopeks,description,payment_method,external_id,is_completed,created_at,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [Database::id(), $this->nextSeq(), $userId, $type, -$amountKopeks, $description, $paymentMethod, $externalId, 1, $now, $now]
            );
            return $this->balance($userId);
        });
    }
    public function balance(string $userId): array
    {
        $row = $this->db->one('SELECT balance_kopeks FROM users WHERE id = ?', [$userId]);
        return ['balance_kopeks' => (int)($row['balance_kopeks'] ?? 0)];
    }
    public function history(string $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->all('SELECT * FROM transactions WHERE user_id = ? ORDER BY seq DESC LIMIT ? OFFSET ?', [$userId, $limit, $offset]);
    }
    private function nextSeq(): int
    {
        // Serialize seq allocation so concurrent credit/debit can never mint a
        // duplicate seq (there is a UNIQUE index on transactions.seq). The
        // advisory xact lock is released when the caller's transaction ends.
        if ($this->db->postgres()) $this->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['zeleboba:transactions:seq']);
        $row=$this->db->one('SELECT COALESCE(MAX(seq),0)+1 AS s FROM transactions', []);
        return (int)($row['s'] ?? 1);
    }
    public function historyCount(string $userId): int
    {
        return (int)($this->db->one('SELECT COUNT(*) AS c FROM transactions WHERE user_id = ?', [$userId])['c'] ?? 0);
    }
    /** Mark a pending transaction completed (e.g. after provider confirmation). */
    public function complete(string $transactionId): void
    {
        $this->db->execute("UPDATE transactions SET is_completed = 1, completed_at = ? WHERE id = ? AND is_completed = 0", [time(), $transactionId]);
    }
}
