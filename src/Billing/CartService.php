<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
final class CartService
{
    public function __construct(private Database $db) {}
    /** Save cart data. When $intent is set, a topup may auto-purchase the cart. */
    public function save(string $userId, array $data, bool $intent = false): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $this->db->execute(
            'INSERT INTO carts(user_id,data,intent,updated_at) VALUES(?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data,intent=excluded.intent,updated_at=excluded.updated_at',
            [$userId, $json, (int)$intent, time()]
        );
    }
    public function get(string $userId): ?array
    {
        $row = $this->db->one('SELECT data,intent FROM carts WHERE user_id = ?', [$userId]);
        if (!$row) return null;
        $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        $data['_intent'] = (int)$row['intent'] === 1;
        return $data;
    }
    public function delete(string $userId): void
    {
        $this->db->execute('DELETE FROM carts WHERE user_id = ?', [$userId]);
    }
    public function hasIntent(string $userId): bool
    {
        return (int)($this->db->one('SELECT intent FROM carts WHERE user_id = ?', [$userId])['intent'] ?? 0) === 1;
    }
    public function clearIntent(string $userId): void
    {
        $this->db->execute('UPDATE carts SET intent = 0 WHERE user_id = ?', [$userId]);
    }
}
