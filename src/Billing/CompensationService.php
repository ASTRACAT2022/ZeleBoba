<?php
declare(strict_types=1);
namespace App\Billing;

use App\Infrastructure\{Database,Outbox};

final class CompensationService
{
    public const SEGMENTS = ['all','active','inactive','paid','trial','telegram'];
    public const KINDS = ['balance','days','traffic'];
    private const BATCH_SIZE = 200;

    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet) {}

    /** Freeze the recipients and terms when the administrator submits the form. */
    public function create(string $segment, string $kind, int $value, string $reason, string $adminId, string $adminName, ?string $planId = null, ?string $requestKey = null): array
    {
        if ($requestKey !== null && !preg_match('/^[a-f0-9]{32}$/D', $requestKey)) throw new BillingError('Обновите форму компенсации.');
        $id = $requestKey ?? Database::id();
        if ($requestKey !== null && ($existing = $this->db->one('SELECT * FROM compensations WHERE id=?', [$id]))) return $existing;
        if (!in_array($segment, self::SEGMENTS, true)) throw new BillingError('Некорректный сегмент.');
        if (!in_array($kind, self::KINDS, true)) throw new BillingError('Некорректный тип компенсации.');
        $maximum = match ($kind) { 'balance' => 100000000, 'days' => 3650, 'traffic' => 100000 };
        if ($value < 1 || $value > $maximum) throw new BillingError('Значение компенсации вне допустимого диапазона.');
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) throw new BillingError('Причина: 3–200 символов.');
        $plan = null;
        if ($kind === 'days' && $planId !== null && $planId !== '') {
            $plan = $this->db->one('SELECT id,traffic_bytes,devices FROM plans WHERE id=? AND active=1', [$planId]);
            if (!$plan) throw new BillingError('Выберите активный тариф для новых подписок.');
        }
        $now = time();
        $this->db->transaction(function () use ($id,$segment,$kind,$value,$reason,$adminId,$adminName,$plan,$now) {
            $inserted = $this->db->execute(
                'INSERT INTO compensations(id,segment,kind,value,reason,total_count,processed_count,status,admin_id,admin_name,created_at,plan_id,plan_traffic_gb,plan_devices) VALUES(?,?,?,?,?,0,0,?,?,?,?,?,?,?) ON CONFLICT(id) DO NOTHING',
                [$id,$segment,$kind,$value,$reason,'in_progress',$adminId,$adminName,$now,$plan['id'] ?? null,$plan ? intdiv((int)$plan['traffic_bytes'],1073741824) : null,$plan ? (int)$plan['devices'] : null]
            );
            if (!$inserted) return;
            $this->snapshot($id,$segment,$kind,$now);
            $count = (int)$this->db->one('SELECT COUNT(*) AS n FROM compensation_targets WHERE compensation_id=?', [$id])['n'];
            $this->db->execute('UPDATE compensations SET total_count=? WHERE id=?', [$count,$id]);
            $this->outbox->enqueue('compensation.run', 'compensation:'.$id.':0', ['compensation_id' => $id]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(),$adminId,'compensation.created',$id,$now]);
        });
        return $this->db->one('SELECT * FROM compensations WHERE id=?', [$id]);
    }

    /** Queue a bounded batch; a committed continuation survives worker crashes. */
    public function run(string $compensationId): void
    {
        $this->db->transaction(function () use ($compensationId) {
            $c = $this->db->one('SELECT * FROM compensations WHERE id=?'.$this->db->lock(), [$compensationId]);
            if (!$c || !in_array($c['status'], ['in_progress','running'], true)) return;
            // Jobs created before migration 039 did not have a recipient snapshot.
            if ($c['status'] === 'in_progress' && (int)$c['total_count'] === 0) {
                $this->snapshot($compensationId,$c['segment'],$c['kind'],(int)$c['created_at']);
                $count = (int)$this->db->one('SELECT COUNT(*) AS n FROM compensation_targets WHERE compensation_id=?', [$compensationId])['n'];
                $this->db->execute('UPDATE compensations SET total_count=? WHERE id=?', [$count,$compensationId]);
                $c['total_count'] = $count;
            }
            if ((int)$c['total_count'] === 0) {
                $this->db->execute("UPDATE compensations SET status='completed',completed_at=? WHERE id=?", [time(),$compensationId]);
                return;
            }
            $rows = $this->db->all('SELECT user_id FROM compensation_targets WHERE compensation_id=? AND user_id>? ORDER BY user_id LIMIT ?', [$compensationId,(string)$c['last_queued_user_id'],self::BATCH_SIZE]);
            foreach ($rows as $row) {
                $userId = (string)$row['user_id'];
                $this->outbox->enqueue('compensation.grant', 'cgrant:'.$compensationId.':'.$userId, ['compensation_id'=>$compensationId,'user_id'=>$userId]);
            }
            $queued = (int)$c['queued_count'] + count($rows);
            $cursor = $rows ? (string)$rows[count($rows)-1]['user_id'] : (string)$c['last_queued_user_id'];
            $this->db->execute("UPDATE compensations SET queued_count=?,last_queued_user_id=?,status='running',queue_error=0 WHERE id=?", [$queued,$cursor,$compensationId]);
            if ($queued < (int)$c['total_count']) {
                $this->outbox->enqueue('compensation.run', 'compensation:'.$compensationId.':'.$queued, ['compensation_id'=>$compensationId]);
            }
        });
    }

    /** Local grant and its receipt commit together; replay cannot issue it twice. */
    public function grant(string $compensationId, string $userId): void
    {
        $this->db->transaction(function () use ($compensationId,$userId) {
            $target = $this->db->one('SELECT status FROM compensation_targets WHERE compensation_id=? AND user_id=?'.$this->db->lock(), [$compensationId,$userId]);
            $c = $this->db->one('SELECT * FROM compensations WHERE id=?', [$compensationId]);
            if (!$c || $c['status'] !== 'running') return;
            if (!$target && (int)$c['queued_count'] === 0 && (int)$c['total_count'] > 0) {
                // Drain a pre-migration batch already present in the outbox.
                $this->db->execute("INSERT INTO compensation_targets(compensation_id,user_id,status) VALUES(?,?,'pending') ON CONFLICT(compensation_id,user_id) DO NOTHING", [$compensationId,$userId]);
                $target = ['status'=>'pending'];
            }
            if (!$target || $target['status'] !== 'pending') return;
            $user = $this->db->one('SELECT disabled FROM users WHERE id=?', [$userId]);
            $detail = null;
            if (!$user || (int)$user['disabled'] !== 0) {
                $detail = 'account_disabled';
            } elseif ($c['kind'] === 'balance') {
                $this->wallet->credit($userId,(int)$c['value'],'manual_adjust','Компенсация: '.$c['reason'],null,'compensation:'.$compensationId);
            } elseif ($c['kind'] === 'days') {
                $detail = $this->grantDays($c,$userId);
            } elseif ($c['kind'] === 'traffic') {
                $detail = $this->grantTraffic($c,$userId);
            } else {
                throw new BillingError('Некорректный тип компенсации.');
            }
            $status = $detail === null ? 'applied' : 'skipped';
            if ($status === 'applied') {
                $this->db->execute('INSERT INTO compensation_grants(id,compensation_id,user_id,created_at) VALUES(?,?,?,?) ON CONFLICT(compensation_id,user_id) DO NOTHING', [Database::id(),$compensationId,$userId,time()]);
            }
            $this->db->execute('UPDATE compensation_targets SET status=?,detail=?,completed_at=? WHERE compensation_id=? AND user_id=?', [$status,$detail,time(),$compensationId,$userId]);
            $counter = $status === 'applied' ? 'processed_count' : 'skipped_count';
            $this->db->execute("UPDATE compensations SET $counter=$counter+1 WHERE id=?", [$compensationId]);
            $this->completeIfFinished($compensationId);
        });
    }

    /** Called only when the outbox has exhausted its retries. */
    public function markFailed(string $compensationId, string $userId): void
    {
        $this->db->transaction(function () use ($compensationId,$userId) {
            $c = $this->db->one('SELECT queued_count,total_count,status FROM compensations WHERE id=?', [$compensationId]);
            if ($c && $c['status']==='running' && (int)$c['queued_count']===0 && (int)$c['total_count']>0) {
                $this->db->execute("INSERT INTO compensation_targets(compensation_id,user_id,status) VALUES(?,?,'pending') ON CONFLICT(compensation_id,user_id) DO NOTHING", [$compensationId,$userId]);
            }
            $changed = $this->db->execute("UPDATE compensation_targets SET status='failed',detail='worker_retry_exhausted',completed_at=? WHERE compensation_id=? AND user_id=? AND status='pending'", [time(),$compensationId,$userId]);
            if ($changed) $this->db->execute('UPDATE compensations SET failed_count=failed_count+1 WHERE id=?', [$compensationId]);
        });
    }

    public function markRunFailed(string $compensationId): void
    {
        $this->db->execute("UPDATE compensations SET queue_error=1 WHERE id=? AND status IN ('in_progress','running')", [$compensationId]);
    }

    public function retryFailed(string $compensationId, string $adminId): int
    {
        return $this->db->transaction(function () use ($compensationId,$adminId) {
            $c = $this->db->one('SELECT status FROM compensations WHERE id=?'.$this->db->lock(), [$compensationId]);
            if (!$c) throw new BillingError('Компенсация не найдена.');
            $rows = $this->db->all("SELECT user_id FROM compensation_targets WHERE compensation_id=? AND status='failed'", [$compensationId]);
            $retried = 0;
            foreach ($rows as $row) {
                $userId = (string)$row['user_id'];
                $changed = $this->db->execute("UPDATE outbox SET status='pending',attempts=0,available_at=?,locked_until=NULL,lock_token=NULL,last_error=NULL WHERE dedup_key=? AND status='dead'", [time(),'cgrant:'.$compensationId.':'.$userId]);
                if (!$changed) continue;
                $this->db->execute("UPDATE compensation_targets SET status='pending',detail=NULL,completed_at=NULL WHERE compensation_id=? AND user_id=?", [$compensationId,$userId]);
                $retried++;
            }
            $runJobs = $this->db->execute("UPDATE outbox SET status='pending',attempts=0,available_at=?,locked_until=NULL,lock_token=NULL,last_error=NULL WHERE topic='compensation.run' AND dedup_key LIKE ? AND status='dead'", [time(),'compensation:'.$compensationId.':%']);
            $syncJobs = $this->db->execute("UPDATE outbox SET status='pending',attempts=0,available_at=?,locked_until=NULL,lock_token=NULL,last_error=NULL WHERE topic IN ('subscription.extend','subscription.provision','subscription.traffic') AND (dedup_key LIKE ? OR dedup_key LIKE ?) AND status='dead'", [time(),'comp-days:'.$compensationId.':%','comp-traffic:'.$compensationId.':%']);
            if ($retried) {
                $this->db->execute("UPDATE compensations SET failed_count=failed_count-?,status='running',completed_at=NULL WHERE id=?", [$retried,$compensationId]);
            }
            if ($runJobs) $this->db->execute('UPDATE compensations SET queue_error=0 WHERE id=?', [$compensationId]);
            if ($retried || $runJobs || $syncJobs) {
                $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(),$adminId,'compensation.retried',$compensationId,time()]);
            }
            return $retried + $runJobs + $syncJobs;
        });
    }

    /** Without a chosen plan, only extend an existing subscription. */
    private function grantDays(array $c, string $userId): ?string
    {
        $now = time();
        $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial','provisioning') AND expires_at>? ORDER BY expires_at DESC LIMIT 1".$this->db->lock(), [$userId,$now]);
        if ($sub) {
            $newExpiry = (int)$sub['expires_at'] + (int)$c['value'] * 86400;
            $status = $sub['status'] === 'provisioning' ? 'provisioning' : 'active';
            $this->db->execute('UPDATE subscriptions SET expires_at=?,status=?,updated_at=? WHERE id=?', [$newExpiry,$status,$now,$sub['id']]);
            $topic = $status === 'provisioning' ? 'subscription.provision' : 'subscription.extend';
            $this->outbox->enqueue($topic,'comp-days:'.$c['id'].':'.$sub['id'],['subscription_id'=>$sub['id']]);
        } else {
            if (!$c['plan_id']) return 'no_active_subscription';
            $id = Database::id();
            $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,start_date,updated_at,lifecycle_status) VALUES(?,NULL,?,'provisioning',?,?,?,?,?,0,?,?,'pending')", [$id,$userId,$now+(int)$c['value']*86400,$now,$c['plan_id'],(int)$c['plan_traffic_gb'],(int)$c['plan_devices'],$now,$now]);
            $this->outbox->enqueue('subscription.provision','comp-days:'.$c['id'].':'.$id,['subscription_id'=>$id]);
        }
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(),'system','compensation.days',$userId,$now]);
        return null;
    }

    /** Returns a skip reason if there is no finite active subscription. */
    private function grantTraffic(array $c, string $userId): ?string
    {
        $now = time();
        $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','provisioning') AND expires_at>? AND traffic_limit_gb>0 ORDER BY expires_at DESC LIMIT 1".$this->db->lock(), [$userId,$now]);
        if (!$sub) return 'no_limited_active_subscription';
        $this->db->execute('UPDATE subscriptions SET purchased_traffic_gb=purchased_traffic_gb+?,updated_at=? WHERE id=?', [(int)$c['value'],$now,$sub['id']]);
        $this->outbox->enqueue('subscription.traffic','comp-traffic:'.$c['id'].':'.$sub['id'],['subscription_id'=>$sub['id'],'traffic_gb'=>(int)$c['value']]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(),'system','compensation.traffic',$userId,$now]);
        return null;
    }

    private function snapshot(string $id, string $segment, string $kind, int $now): void
    {
        $condition = match ($segment) {
            'all' => '1=1',
            'active' => "EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id=u.id AND s.status IN ('active','trial') AND s.expires_at>$now)",
            'inactive' => "NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id=u.id AND s.status IN ('active','trial') AND s.expires_at>$now)",
            'paid' => 'u.has_had_paid_subscription=1',
            'trial' => "EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id=u.id AND s.is_trial=1 AND s.status IN ('active','trial') AND s.expires_at>$now)",
            'telegram' => 'u.telegram_id IS NOT NULL',
            default => throw new BillingError('Некорректный сегмент.'),
        };
        if ($kind === 'traffic') {
            $condition .= " AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id=u.id AND s.status IN ('active','provisioning') AND s.expires_at>$now AND s.traffic_limit_gb>0)";
        }
        $this->db->execute("INSERT INTO compensation_targets(compensation_id,user_id,status) SELECT ?,u.id,'pending' FROM users u WHERE u.disabled=0 AND $condition ON CONFLICT(compensation_id,user_id) DO NOTHING", [$id]);
    }

    private function completeIfFinished(string $id): void
    {
        $this->db->execute("UPDATE compensations SET status='completed',completed_at=? WHERE id=? AND status='running' AND processed_count+skipped_count=total_count AND failed_count=0", [time(),$id]);
    }

    public function list(int $limit = 50): array
    {
        $rows = $this->db->all('SELECT * FROM compensations ORDER BY created_at DESC LIMIT ?', [$limit]);
        foreach ($rows as &$row) {
            $sync = $this->db->all("SELECT status,COUNT(*) AS n FROM outbox WHERE topic IN ('subscription.extend','subscription.provision','subscription.traffic') AND (dedup_key LIKE ? OR dedup_key LIKE ?) GROUP BY status", ['comp-days:'.$row['id'].':%','comp-traffic:'.$row['id'].':%']);
            $row['sync_pending_count'] = 0;
            $row['sync_failed_count'] = 0;
            foreach ($sync as $item) {
                if ($item['status'] === 'dead') $row['sync_failed_count'] += (int)$item['n'];
                if (in_array($item['status'], ['pending','processing'], true)) $row['sync_pending_count'] += (int)$item['n'];
            }
        }
        unset($row);
        return $rows;
    }
}
