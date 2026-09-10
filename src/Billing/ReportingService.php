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
        $gifts = (int)($this->db->one("SELECT COUNT(*) AS c FROM guest_purchases WHERE is_gift=1 AND status IN ('paid','pending_activation','delivered') AND created_at>?", [$since])['c'] ?? 0);
        $avgCheck = $orders > 0 ? (int)($revenue / $orders) : 0;
        $conversion = $newUsers > 0 ? (int)round($orders * 100 / $newUsers) : 0;
        return [
            'revenue_kopeks' => $revenue,
            'orders' => $orders,
            'topups' => $topups,
            'new_users' => $newUsers,
            'active_subscriptions' => $activeSubs,
            'active_trials' => $trials,
            'gifts' => $gifts,
            'avg_check_kopeks' => $avgCheck,
            'conversion_percent' => $conversion,
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
    /** Revenue split by payment provider. */
    public function revenueByProvider(int $days = 30): array
    {
        $since = time() - $days * 86400;
        return $this->db->all(
            "SELECT o.provider, COUNT(*) AS cnt, SUM(o.price_minor) AS total FROM orders o WHERE o.status IN ('paid','fulfilled') AND o.created_at>? GROUP BY o.provider ORDER BY total DESC",
            [$since]
        );
    }
    /** Revenue split by plan. */
    public function revenueByPlan(int $days = 30): array
    {
        $since = time() - $days * 86400;
        return $this->db->all(
            "SELECT o.plan_name, COUNT(*) AS cnt, SUM(o.price_minor) AS total FROM orders o WHERE o.status IN ('paid','fulfilled') AND o.created_at>? GROUP BY o.plan_name ORDER BY total DESC",
            [$since]
        );
    }
    /** Revenue split by transaction type (topups vs purchases vs gifts). */
    public function revenueByType(int $days = 30): array
    {
        $since = time() - $days * 86400;
        $topups = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM topups WHERE status='paid' AND created_at>?", [$since])['s'] ?? 0);
        $purchases = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM transactions WHERE type IN ('subscription_purchase','subscription_renewal','trial_conversion') AND amount_kopeks<0 AND created_at>?", [$since])['s'] ?? 0);
        $gifts = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM transactions WHERE type='gift_purchase' AND amount_kopeks<0 AND created_at>?", [$since])['s'] ?? 0);
        $traffic = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM transactions WHERE type IN ('traffic_topup','device_addon') AND amount_kopeks<0 AND created_at>?", [$since])['s'] ?? 0);
        return [
            'topups_kopeks' => $topups,
            'purchases_kopeks' => -$purchases,
            'gifts_kopeks' => -$gifts,
            'addons_kopeks' => -$traffic,
        ];
    }
    /** Top customers by spend. */
    public function topCustomers(int $days = 30, int $limit = 10): array
    {
        $since = time() - $days * 86400;
        return $this->db->all(
            "SELECT u.id,u.email,u.telegram_id,COALESCE(SUM(-t.amount_kopeks),0) AS spent FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.amount_kopeks<0 AND t.type IN ('subscription_purchase','subscription_renewal','gift_purchase','traffic_topup','device_addon','trial_conversion') AND t.created_at>? GROUP BY u.id ORDER BY spent DESC LIMIT ?",
            [$since, $limit]
        );
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
