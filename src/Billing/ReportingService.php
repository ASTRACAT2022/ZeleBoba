<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
final class ReportingService
{
    public function __construct(private Database $db) {}
    /** Revenue and sales stats for a period. */
    public function salesStats(int $days = 30): array
    {
        $since = time() - $days * 86400;
        $revenue = (int)($this->db->one('SELECT COALESCE(SUM(amount_minor),0) AS s FROM ledger_entries WHERE account=\'provider_clearing\' AND created_at>?', [$since])['s'] ?? 0);
        $orders = (int)($this->db->one("SELECT COUNT(*) AS c FROM orders WHERE status IN ('paid','fulfilled') AND created_at>?", [$since])['c'] ?? 0);
        $topups = (int)($this->db->one("SELECT COUNT(*) AS c FROM topups WHERE status='paid' AND created_at>?", [$since])['c'] ?? 0);
        $newUsers = (int)($this->db->one('SELECT COUNT(*) AS c FROM users WHERE created_at>?', [$since])['c'] ?? 0);
        $activeSubs = (int)($this->db->one("SELECT COUNT(*) AS c FROM subscriptions WHERE status IN ('active','trial') AND expires_at>?", [time()])['c'] ?? 0);
        $trials = (int)($this->db->one("SELECT COUNT(*) AS c FROM subscriptions WHERE is_trial=1 AND status IN ('active','trial')", [])['c'] ?? 0);
        return [
            'revenue_kopeks' => $revenue,
            'orders' => $orders,
            'topups' => $topups,
            'new_users' => $newUsers,
            'active_subscriptions' => $activeSubs,
            'active_trials' => $trials,
        ];
    }
    /** Daily revenue series for a chart. */
    public function dailyRevenue(int $days = 14): array
    {
        $since = time() - $days * 86400;
        $rows = $this->db->all(
            "SELECT date(created_at,'unixepoch') AS day, SUM(amount_minor) AS total FROM ledger_entries WHERE account='provider_clearing' AND created_at>? GROUP BY day ORDER BY day",
            [$since]
        );
        $result = [];
        foreach ($rows as $row) $result[$row['day']] = (int)$row['total'];
        return $result;
    }
    /** Top referrers by earnings. */
    public function topReferrers(int $limit = 10): array
    {
        return $this->db->all(
            'SELECT u.id,u.email,u.telegram_id,SUM(e.amount_kopeks) AS total,COUNT(DISTINCT e.referral_id) AS referrals FROM referral_earnings e JOIN users u ON u.id=e.user_id GROUP BY u.id ORDER BY total DESC LIMIT ?',
            [$limit]
        );
    }
    /** User spending stats. */
    public function userSpending(string $userId): array
    {
        $spent = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM transactions WHERE user_id=? AND amount_kopeks<0 AND type IN ('subscription_purchase','subscription_renewal','gift_purchase','traffic_topup','device_addon')", [$userId])['s'] ?? 0);
        $topups = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM topups WHERE user_id=? AND status='paid'", [$userId])['s'] ?? 0);
        return ['spent_kopeks' => -$spent, 'topups_kopeks' => $topups];
    }
}
