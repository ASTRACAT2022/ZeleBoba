<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class GiftService
{
    private const TOKEN_LENGTH = 64;
    private const BOT_PREFIX_LENGTH = 59;
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet, private ?array $config = null) {}
    public function enabled(): bool
    {
        return ($this->config['CABINET_GIFT_ENABLED'] ?? '0') === '1';
    }
    /** Generate a secure 64-char URL-safe token. */
    public function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }
    /** Canonical public code: GIFT_<59 chars>. */
    public function publicCode(string $token): string
    {
        return 'GIFT_'.substr($token, 0, self::BOT_PREFIX_LENGTH);
    }
    /** Purchase a gift from balance. Returns the purchase row. */
    public function purchaseFromBalance(string $buyerId, string $planId, string $idempotencyKey, ?string $recipientType = null, ?string $recipientValue = null, ?string $message = null, string $source = 'bot'): array
    {
        if (!$this->enabled()) throw new BillingError('Подарки отключены.');
        if (!preg_match('/^[a-zA-Z0-9:_-]{8,64}$/D', $idempotencyKey)) throw new BillingError('Некорректный ключ операции.');
        return $this->db->transaction(function () use ($buyerId, $planId, $idempotencyKey, $recipientType, $recipientValue, $message, $source) {
            $existing = $this->db->one('SELECT * FROM guest_purchases WHERE idempotency_key=?', [$idempotencyKey]);
            if ($existing) {
                if ($existing['buyer_user_id'] !== $buyerId || $existing['plan_id'] !== $planId) throw new BillingError('Ключ уже использован с другими параметрами.');
                return $existing;
            }
            $plan = $this->db->one('SELECT * FROM plans WHERE id=? AND active=1' . $this->db->lock(), [$planId]);
            if (!$plan) throw new BillingError('Тариф недоступен.');
            $buyer = $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [$buyerId]);
            if (!$buyer) throw new BillingError('Аккаунт не найден.');
            $price = (int)$plan['price_minor'];
            if ((int)$buyer['balance_kopeks'] < $price) throw new BillingError('Недостаточно средств на балансе.');
            $this->wallet->debit($buyerId, $price, 'gift_purchase', 'Покупка подарочной подписки: '.$plan['name']);
            $token = $this->generateToken();
            $now = time();
            $id = Database::id();
            $contactType = $buyer['email'] ? 'email' : 'telegram';
            $contactValue = $buyer['email'] ?? (string)$buyer['telegram_id'];
            $this->db->execute(
                'INSERT INTO guest_purchases(id,token,contact_type,contact_value,is_gift,source,buyer_user_id,gift_recipient_type,gift_recipient_value,gift_message,plan_id,period_days,traffic_bytes,device_limit,amount_kopeks,currency,payment_method,status,created_at,paid_at,idempotency_key) VALUES(?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id, $token, $contactType, $contactValue, $source, $buyerId, $recipientType, $recipientValue, $message, $plan['id'], (int)$plan['duration_days'], (int)$plan['traffic_bytes'], (int)$plan['devices'], $price, 'RUB', 'balance', 'paid', $now, $now, $idempotencyKey]
            );
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $buyerId, 'gift.purchased', $id, $now]);
            return $this->db->one('SELECT * FROM guest_purchases WHERE id=?', [$id]);
        });
    }
    /** Parse claim input (code, deep link, URL, token) into a token prefix. */
    public function parseClaimInput(string $value): ?string
    {
        $cleaned = trim($value);
        if ($cleaned === '') return null;
        $candidate = $cleaned;
        if (str_starts_with($candidate, 't.me/') || str_starts_with($candidate, 'www.t.me/')) $candidate = 'https://'.$candidate;
        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://') || str_starts_with($candidate, 'tg://')) {
            $parts = parse_url($candidate);
            $query = $parts['query'] ?? '';
            parse_str($query, $qs);
            $start = $qs['start'] ?? '';
            if (!is_string($start)) return null;
            if ($start !== '') {
                $upper = strtoupper($start);
                if (str_starts_with($upper, 'GIFT_') || str_starts_with($upper, 'GIFT-')) return substr($start, 5);
                if (preg_match('/^[a-zA-Z0-9_-]{64}$/D', $start)) return $start;
                return null;
            }
            if (str_contains($candidate, '/buy/gift/')) {
                $path = $parts['path'] ?? '';
                $idx = strrpos($path, '/buy/gift/');
                if ($idx !== false) return substr($path, $idx + 10);
            }
            return null;
        }
        $upper = strtoupper($cleaned);
        if (str_starts_with($upper, 'GIFT_') || str_starts_with($upper, 'GIFT-')) return substr($cleaned, 5);
        if (str_starts_with(strtolower($cleaned), 'giftclaim_') || str_starts_with(strtolower($cleaned), 'giftclaim-')) return substr($cleaned, 10);
        if (preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', $cleaned)) return $cleaned;
        return null;
    }
    /** Claim and activate a gift for a user. Returns the purchase row. */
    public function claim(string $claimantId, string $claimInput): array
    {
        $token = $this->parseClaimInput($claimInput);
        if ($token === null || !preg_match('/^(?:[a-zA-Z0-9_-]{59}|[a-zA-Z0-9_-]{64})$/D',$token)) throw new BillingError('Подарок не найден.');
        return $this->db->transaction(function () use ($claimantId, $token) {
            // Compare literally: '_' belongs to the token alphabet, not a SQL wildcard.
            $purchase = $this->db->one('SELECT * FROM guest_purchases WHERE is_gift=1 AND substr(token,1,?)=?' . $this->db->lock(), [strlen($token), $token]);
            if (!$purchase) throw new BillingError('Подарок не найден.');
            if ($purchase['buyer_user_id'] !== null && $purchase['buyer_user_id'] === $claimantId) throw new BillingError('Нельзя активировать собственный подарок.');
            if ($purchase['user_id'] !== null && $purchase['user_id'] !== $claimantId) throw new BillingError('Подарок уже активирован другим пользователем.');
            if ($purchase['status'] === 'delivered' && $purchase['user_id'] === $claimantId) return $purchase;
            if (!in_array($purchase['status'], ['paid', 'pending_activation'], true)) throw new BillingError('Подарок не может быть активирован.');
            $now = time();
            $sub = Database::id();
            $this->db->execute(
                "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,start_date,traffic_limit_bytes) VALUES(?,NULL,?,'provisioning',?,?,?,?,?,0,?,?)",
                [$sub, $claimantId, $now + (int)$purchase['period_days'] * 86400, $now, $purchase['plan_id'], (int)$purchase['traffic_bytes'] / 1073741824, (int)$purchase['device_limit'], $now, (int)$purchase['traffic_bytes']]
            );
            $this->db->execute("UPDATE guest_purchases SET status='delivered',user_id=?,delivered_at=? WHERE id=?", [$claimantId, $now, $purchase['id']]);
            $this->outbox->enqueue('subscription.provision', 'provision:'.$sub, ['subscription_id' => $sub]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $claimantId, 'gift.claimed', $purchase['id'], $now]);
            return $this->db->one('SELECT * FROM guest_purchases WHERE id=?', [$purchase['id']]);
        });
    }
    /** Gifts bought by a user (history & recovery). */
    public function boughtBy(string $userId): array
    {
        return $this->db->all('SELECT * FROM guest_purchases WHERE buyer_user_id=? AND is_gift=1 ORDER BY created_at DESC', [$userId]);
    }
    /** Gifts delivered to a user. */
    public function receivedBy(string $userId): array
    {
        return $this->db->all('SELECT * FROM guest_purchases WHERE user_id=? AND is_gift=1 ORDER BY created_at DESC', [$userId]);
    }
    public function botClaimUrl(string $token): string
    {
        $username = (string)($this->config['TELEGRAM_BOT_USERNAME'] ?? '');
        return $username !== '' ? 'https://t.me/'.$username.'?start=GIFT_'.substr($token, 0, self::BOT_PREFIX_LENGTH) : '';
    }
    public function cabinetClaimUrl(string $token): string
    {
        return rtrim((string)($this->config['APP_URL'] ?? ''), '/').'/buy/gift/'.$token;
    }
}
