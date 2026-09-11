<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class ChannelService
{
    public function __construct(private Database $db, private Outbox $outbox) {}
    /** Add a required channel. */
    public function add(string $channelId, string $link, string $title, string $actor): array
    {
        if (!preg_match('/^-?[0-9]{5,20}$/D', $channelId) && !preg_match('/^@[A-Za-z0-9_]{4,32}$/D', $channelId)) throw new BillingError('Некорректный ID канала (число или @username).');
        if ($link !== '' && !str_starts_with($link, 'https://t.me/')) throw new BillingError('Ссылка: https://t.me/...');
        $id = Database::id();
        $this->db->execute('INSERT INTO required_channels(id,channel_id,channel_link,title,is_active,sort_order,created_at) VALUES(?,?,?,?,1,0,?)', [$id, $channelId, $link !== '' ? $link : null, $title !== '' ? $title : null, time()]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'channel.added', $id, time()]);
        return $this->db->one('SELECT * FROM required_channels WHERE id=?', [$id]);
    }
    public function toggle(string $id, bool $active, string $actor): void
    {
        $this->db->execute('UPDATE required_channels SET is_active=? WHERE id=?', [(int)$active, $id]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, $active ? 'channel.enabled' : 'channel.disabled', $id, time()]);
    }
    public function remove(string $id, string $actor): void
    {
        $this->db->execute('DELETE FROM required_channels WHERE id=?', [$id]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'channel.removed', $id, time()]);
    }
    public function list(): array
    {
        return $this->db->all('SELECT * FROM required_channels ORDER BY sort_order, created_at');
    }
    /** Check a user's membership in all required channels. Returns missing channels. */
    public function missingChannels(string $userId): array
    {
        $channels = $this->db->all('SELECT * FROM required_channels WHERE is_active=1 ORDER BY sort_order');
        if (!$channels) return [];
        $missing = [];
        foreach ($channels as $ch) {
            $row = $this->db->one('SELECT is_subscribed FROM user_channel_subscriptions WHERE user_id=? AND channel_id=?', [$userId, $ch['channel_id']]);
            if (!$row || (int)$row['is_subscribed'] !== 1) $missing[] = $ch;
        }
        return $missing;
    }
    /** Update cached membership from a Telegram getChatMember result. */
    public function updateMembership(string $userId, string $channelId, bool $subscribed): void
    {
        $this->db->execute(
            'INSERT INTO user_channel_subscriptions(id,user_id,channel_id,is_subscribed,checked_at) VALUES(?,?,?,?,?) ON CONFLICT(user_id,channel_id) DO UPDATE SET is_subscribed=excluded.is_subscribed,checked_at=excluded.checked_at',
            [Database::id(), $userId, $channelId, (int)$subscribed, time()]
        );
    }
}
