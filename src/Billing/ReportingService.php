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
        $receipts = $this->plategaTotalsSince($since);
        $revenue = $receipts['revenue_kopeks'];
        $orders = (int)($this->db->one("SELECT COUNT(*) AS c FROM payments p WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id <> ('balance_' || p.order_id) AND p.paid_at>?", [$since])['c'] ?? 0);
        $topups = (int)($this->db->one("SELECT COUNT(*) AS c FROM topups WHERE provider='platega' AND status='paid' AND paid_at>?", [$since])['c'] ?? 0);
        $newUsers = (int)($this->db->one('SELECT COUNT(*) AS c FROM users WHERE created_at>?', [$since])['c'] ?? 0);
        $activeSubs = (int)($this->db->one("SELECT COUNT(*) AS c FROM subscriptions WHERE status IN ('active','trial') AND expires_at>?", [time()])['c'] ?? 0);
        $trials = (int)($this->db->one("SELECT COUNT(*) AS c FROM subscriptions WHERE is_trial=1 AND status IN ('active','trial')", [])['c'] ?? 0);
        $gifts = (int)($this->db->one("SELECT COUNT(*) AS c FROM guest_purchases WHERE is_gift=1 AND status IN ('paid','pending_activation','delivered') AND created_at>?", [$since])['c'] ?? 0);
        $avgCheck = $receipts['payments'] > 0 ? (int)($revenue / $receipts['payments']) : 0;
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
    /** Earnings overview: today / 7 days / this month / this year. */
    public function earningsOverview(): array
    {
        $dayStart = strtotime('today');
        $weekStart = strtotime('-6 days', $dayStart);
        $monthStart = strtotime('first day of this month');
        $yearStart = strtotime('first day of January');
        $periods = [
            'today' => ['label' => 'Сегодня', 'since' => $dayStart],
            'week' => ['label' => '7 дней', 'since' => $weekStart],
            'month' => ['label' => 'Месяц', 'since' => $monthStart],
            'year' => ['label' => 'Год', 'since' => $yearStart],
        ];
        $result = [];
        foreach ($periods as $key => $p) {
            $receipts = $this->plategaTotalsSince($p['since']);
            $revenue = $receipts['revenue_kopeks'];
            $orders = (int)($this->db->one("SELECT COUNT(*) AS c FROM payments p WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id <> ('balance_' || p.order_id) AND p.paid_at>=?", [$p['since']])['c'] ?? 0);
            $topups = (int)($this->db->one("SELECT COUNT(*) AS c FROM topups WHERE provider='platega' AND status='paid' AND paid_at>=?", [$p['since']])['c'] ?? 0);
            $result[$key] = [
                'label' => $p['label'],
                'revenue_kopeks' => $revenue,
                'orders' => $orders,
                'topups' => $topups,
            ];
        }
        return $result;
    }
    /** Daily revenue series for a chart. */
    public function dailyRevenue(int $days = 14): array
    {
        $since = time() - $days * 86400;
        $rows = $this->db->all(
            "SELECT paid_at AS received_at, amount_minor FROM payments WHERE provider='platega' AND status='succeeded' AND provider_payment_id <> ('balance_' || order_id) AND paid_at>?\n             UNION ALL\n             SELECT paid_at AS received_at, amount_kopeks AS amount_minor FROM topups WHERE provider='platega' AND status='paid' AND paid_at>?\n             ORDER BY received_at",
            [$since, $since]
        );
        $result = [];
        foreach ($rows as $row) {
            $day = gmdate('Y-m-d', (int)$row['received_at']);
            $result[$day] = ($result[$day] ?? 0) + (int)$row['amount_minor'];
        }
        return $result;
    }
    /** Revenue split by payment provider. */
    public function revenueByProvider(int $days = 30): array
    {
        $since = time() - $days * 86400;
        return $this->db->all(
            "SELECT provider, COUNT(*) AS cnt, SUM(amount_minor) AS total FROM (\n                SELECT p.provider,p.amount_minor FROM payments p WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id <> ('balance_' || p.order_id) AND p.paid_at>?\n                UNION ALL\n                SELECT t.provider,t.amount_kopeks AS amount_minor FROM topups t WHERE t.provider='platega' AND t.status='paid' AND t.paid_at>?\n             ) receipts GROUP BY provider ORDER BY total DESC",
            [$since, $since]
        );
    }
    /** Revenue split by plan. */
    public function revenueByPlan(int $days = 30): array
    {
        $since = time() - $days * 86400;
        return $this->db->all(
            "SELECT o.plan_name, COUNT(*) AS cnt, SUM(p.amount_minor) AS total FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id <> ('balance_' || p.order_id) AND p.paid_at>? GROUP BY o.plan_name ORDER BY total DESC",
            [$since]
        );
    }
    /** Revenue split by transaction type (topups vs purchases vs gifts). */
    public function revenueByType(int $days = 30): array
    {
        $since = time() - $days * 86400;
        $topups = (int)($this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM topups WHERE provider='platega' AND status='paid' AND paid_at>?", [$since])['s'] ?? 0);
        $purchases = (int)($this->db->one("SELECT COALESCE(SUM(amount_minor),0) AS s FROM payments WHERE provider='platega' AND status='succeeded' AND provider_payment_id <> ('balance_' || order_id) AND paid_at>?", [$since])['s'] ?? 0);
        return [
            'topups_kopeks' => $topups,
            'purchases_kopeks' => $purchases,
            'gifts_kopeks' => 0,
            'addons_kopeks' => 0,
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

    /** Gross incoming funds confirmed by Platega (orders and wallet topups). */
    public function plategaTotalsSince(int $since): array
    {
        // Balance-funded orders keep the order's configured provider for
        // fulfillment, but their synthetic balance_<order id> payment is not
        // an external cash receipt and must never appear in Platega revenue.
        $row = $this->db->one(
            "SELECT COALESCE(SUM(receipts.amount_minor),0) AS revenue,COUNT(*) AS payments FROM (\n                SELECT p.amount_minor FROM payments p WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id <> ('balance_' || p.order_id) AND p.paid_at>=?\n                UNION ALL\n                SELECT t.amount_kopeks AS amount_minor FROM topups t WHERE t.provider='platega' AND t.status='paid' AND t.paid_at>=?\n            ) receipts",
            [$since, $since]
        );
        return ['revenue_kopeks' => (int)($row['revenue'] ?? 0), 'payments' => (int)($row['payments'] ?? 0)];
    }
}
