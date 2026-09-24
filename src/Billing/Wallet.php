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
            $externalId = $this->normalizeExternalId($externalId);
            if ($externalId !== null && $this->idempotentReplay($userId, $type, $externalId, $amountKopeks)) {
                return $this->balance($userId);
            }
            $now = time();
            $this->db->execute('UPDATE users SET balance_kopeks = balance_kopeks + ? WHERE id = ?', [$amountKopeks, $userId]);
            $tx = Database::id();
            $this->db->execute(
                'INSERT INTO transactions(id,seq,user_id,type,amount_kopeks,description,payment_method,external_id,is_completed,created_at,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [$tx, $this->nextSeq(), $userId, $type, $amountKopeks, $description, $paymentMethod, $externalId, (int)$completed, $now, $completed ? $now : null]
            );
            $this->recordLedger($tx, $userId, $type, $amountKopeks, $now);
            return $this->balance($userId);
        });
    }
    /** Debit user balance inside the caller's transaction. Returns new balance. */
    public function debit(string $userId, int $amountKopeks, string $type, string $description, ?string $paymentMethod = null, ?string $externalId = null): array
    {
        return $this->db->transaction(function() use ($userId,$amountKopeks,$type,$description,$paymentMethod,$externalId) {
            if ($amountKopeks <= 0) throw new BillingError('Сумма должна быть положительной.');
            if (!in_array($type, self::TYPES, true)) throw new BillingError('Некорректный тип операции.');
            $externalId = $this->normalizeExternalId($externalId);
            if ($externalId !== null && $this->idempotentReplay($userId, $type, $externalId, -$amountKopeks)) {
                return $this->balance($userId);
            }
            $user = $this->db->one('SELECT balance_kopeks FROM users WHERE id = ?' . $this->db->lock(), [$userId]);
            if (!$user) throw new BillingError('Аккаунт не найден.');
            if ((int)$user['balance_kopeks'] < $amountKopeks) throw new BillingError('Недостаточно средств на балансе.');
            $now = time();
            $this->db->execute('UPDATE users SET balance_kopeks = balance_kopeks - ? WHERE id = ?', [$amountKopeks, $userId]);
            $tx = Database::id();
            $this->db->execute(
                'INSERT INTO transactions(id,seq,user_id,type,amount_kopeks,description,payment_method,external_id,is_completed,created_at,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [$tx, $this->nextSeq(), $userId, $type, -$amountKopeks, $description, $paymentMethod, $externalId, 1, $now, $now]
            );
            $this->recordLedger($tx, $userId, $type, -$amountKopeks, $now);
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
    public function ledgerBalance(string $userId): int
    {
        return (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS b FROM wallet_ledger_entries WHERE account=?", ['wallet:user:'.$userId])['b'] ?? 0);
    }
    /** Mark a pending transaction completed (e.g. after provider confirmation). */
    public function complete(string $transactionId): void
    {
        $this->db->execute("UPDATE transactions SET is_completed = 1, completed_at = ? WHERE id = ? AND is_completed = 0", [time(), $transactionId]);
    }
    private function normalizeExternalId(?string $externalId): ?string
    {
        $externalId = $externalId === null ? null : trim($externalId);
        if ($externalId === null) return null;
        if ($externalId === '') return null;
        if (strlen($externalId) > 100) throw new BillingError('Некорректный ключ операции.');
        return $externalId;
    }
    private function idempotentReplay(string $userId, string $type, string $externalId, int $amountKopeks): bool
    {
        $existing = $this->db->one('SELECT amount_kopeks FROM transactions WHERE user_id=? AND type=? AND external_id=?', [$userId, $type, $externalId]);
        if (!$existing) return false;
        if ((int)$existing['amount_kopeks'] !== $amountKopeks) {
            throw new BillingError('Этот ключ уже использован для другой суммы.');
        }
        return true;
    }
    private function recordLedger(string $transactionId, string $userId, string $type, int $amountKopeks, int $createdAt): void
    {
        $contra = $amountKopeks > 0 ? 'wallet:source:'.$type : 'wallet:sink:'.$type;
        foreach ([
            ['wallet:user:'.$userId, $amountKopeks, 'u'],
            [$contra, -$amountKopeks, 'c'],
        ] as [$account, $amount, $suffix]) {
            $this->db->execute(
                'INSERT INTO wallet_ledger_entries(id,transaction_id,account,amount_kopeks,currency,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(transaction_id,account) DO NOTHING',
                [$transactionId.$suffix, $transactionId, $account, $amount, 'RUB', $createdAt]
            );
        }
    }
}
