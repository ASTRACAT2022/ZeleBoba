<?php
declare(strict_types=1);
namespace App\Subscriptions;

use App\Infrastructure\{Database,Outbox};

/** Owns the single business expiry timestamp for a subscription. */
final class SubscriptionService
{
    public function __construct(private Database $db, private Outbox $outbox) {}

    public function expiryAfter(int $base, int $durationDays, int $durationMonths=0): int
    {
        if ($durationMonths > 0) {
            $date=(new \DateTimeImmutable('@'.$base))->setTimezone(new \DateTimeZone('UTC'));
            $month=((int)$date->format('n')) + $durationMonths;
            $year=(int)$date->format('Y') + intdiv($month-1,12);
            $month=(($month-1)%12)+1;
            $last=(int)(new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00 UTC',$year,$month)))->format('t');
            return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d %s UTC',$year,$month,min((int)$date->format('j'),$last),$date->format('H:i:s'))))->getTimestamp();
        }
        return $base + $durationDays * 86400;
    }

    /** Called inside the payment transaction after the payment and order are locked. */
    public function extend(string $subscriptionId, array $order, int $now): int
    {
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->db->lock(),[$subscriptionId]);
        if (!$sub) throw new \RuntimeException('Subscription not found');
        // An order is a product snapshot. A later plan edit must not turn a
        // paid renewal from calendar-month billing into a day-based period.
        $months=(int)($order['plan_version_id']!==null
            ? ($this->db->one('SELECT duration_months FROM plan_versions WHERE id=?',[$order['plan_version_id']])['duration_months'] ?? 0)
            : ($this->db->one('SELECT duration_months FROM plans WHERE id=?',[$order['plan_id']])['duration_months'] ?? 0));
        $expires=$this->expiryAfter(max($now,(int)$sub['expires_at']), (int)$order['duration_days'], $months);
        $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active',lifecycle_status='active',updated_at=?,version=version+1 WHERE id=?",[$expires,$now,$subscriptionId]);
        $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at,correlation_id) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING',[
            Database::id(),'subscription.extend','extend:'.$subscriptionId.':'.$order['id'],json_encode(['subscription_id'=>$subscriptionId,'order_id'=>$order['id']],JSON_THROW_ON_ERROR),80,$now,$now,$order['id']
        ]);
        return $expires;
    }
}
