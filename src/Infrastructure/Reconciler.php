<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Container;
final class Reconciler
{
    public function __construct(private Container $app) {}
    public function run():int
    {
        $db=$this->app->db;$token=Database::id();
        $taken=$db->transaction(function()use($db,$token){
            $db->execute("INSERT INTO advisory_leases VALUES('reconcile',?,0) ON CONFLICT(name) DO NOTHING",[$token]);
            $row=$db->one("SELECT * FROM advisory_leases WHERE name='reconcile'".$db->lock());
            if((int)$row['expires_at']>time())return false;
            $db->execute("UPDATE advisory_leases SET token=?,expires_at=? WHERE name='reconcile'",[$token,time()+120]);return true;
        });
        if(!$taken)return 0;
        try{
            $money=(new \App\Observability\ConsistencyChecker($db))->run();
            // Some operational tests/tools construct a minimal Container.
            // `isset` is safe for an uninitialized typed property.
            if (isset($this->app->creators)) {
                $this->app->creators->releaseDue();
                $this->app->creators->reconcile('scheduler');
            }
            if (isset($this->app->paymentService)) $this->app->paymentService->requeueStaleEvents();
            if($money['status']!=='ok') error_log(json_encode(['event'=>'financial.drift','count'=>$money['count']]));
            $after='';$bucket=intdiv(time(),300);
            // Only retry providers that can be verified with the credentials
            // currently installed. Retrying demo or incomplete integrations
            // creates permanent dead jobs, while limiting this query to two
            // providers leaves every other successful checkout unfulfilled
            // after a lost webhook.
            // Some operational tools construct a minimal Container solely for
            // renewal scheduling. Keep that supported while full application
            // containers use the dynamic registry.
            $providers=isset($this->app->providers)
                ? array_keys($this->app->providers->enabled())
                : ['platega','yookassa','freekassa'];
            do{
                $placeholders=implode(',',array_fill(0,count($providers),'?'));
                $rows=$providers===[]?[]:$db->all("SELECT id,provider,provider_payment_id,freekassa_intid FROM orders WHERE id>? AND status='pending' AND provider IN ($placeholders) AND provider_payment_id IS NOT NULL ORDER BY id LIMIT 100",array_merge([$after],$providers));
                $db->transaction(function()use($rows,$bucket){foreach($rows as $row){$pid=$row['provider']==='freekassa'&&!empty($row['freekassa_intid'])?$row['freekassa_intid']:$row['provider_payment_id'];$this->app->outbox->enqueue('payment.verify','reconcile:'.$row['id'].':'.$bucket,['payment_id'=>$pid,'provider'=>$row['provider']]);}});
                if($rows)$after=end($rows)['id'];
                $db->execute("UPDATE advisory_leases SET expires_at=? WHERE name='reconcile' AND token=?",[time()+120,$token]);
            }while(count($rows)===100);
            $after='';
            do {
                $placeholders=implode(',',array_fill(0,count($providers),'?'));
                $rows=$providers===[]?[]:$db->all("SELECT id,provider,provider_payment_id FROM topups WHERE id>? AND status='pending' AND provider IN ($placeholders) AND provider_payment_id IS NOT NULL ORDER BY id LIMIT 100",array_merge([$after],$providers));
                foreach ($rows as $row) $this->app->outbox->enqueue('payment.verify','reconcile-topup:'.$row['id'].':'.$bucket,['payment_id'=>$row['provider_payment_id'],'provider'=>$row['provider']]);
                if ($rows) $after=end($rows)['id'];
            } while(count($rows)===100);
            // A worker can exhaust provisioning retries while the panel is down.
            // The original outbox dedup key remains occupied by the dead job, so
            // enqueue a time-bucketed recovery operation rather than attempting
            // to mutate a dead job in place. Worker::provision and the remote
            // deterministic username make this safe to repeat after a timeout.
            $stuck=$db->all("SELECT id FROM subscriptions WHERE status='provisioning' AND remote_id IS NULL AND expires_at>? ORDER BY created_at LIMIT 100",[time()]);
            foreach ($stuck as $subscription) {
                $this->app->outbox->enqueue('subscription.provision','reconcile-provision:'.$subscription['id'].':'.intdiv(time(),300),['subscription_id'=>$subscription['id']]);
            }
            // Recover provisioning work whose original outbox command exhausted
            // its retries. This covers renewals of already active accounts too.
            $recover=$db->all("SELECT p.subscription_id,s.remote_id FROM provisioning_accounts p JOIN subscriptions s ON s.id=p.subscription_id WHERE p.state IN ('retry','failed') AND s.expires_at>? AND s.lifecycle_status IN ('pending','active','grace') ORDER BY p.updated_at LIMIT 100",[time()]);
            foreach ($recover as $account) {
                $topic=$account['remote_id']===null?'subscription.provision':'subscription.extend';
                $this->app->outbox->enqueue($topic,'reconcile-account:'.$account['subscription_id'].':'.intdiv(time(),300),['subscription_id'=>$account['subscription_id']]);
            }
            // Auto-renew: enqueue renew jobs for subscriptions near expiry
            $autoEnabled=$db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_ENABLED'");
            if (!$autoEnabled || $autoEnabled['value']==='1') {
                $maxFails=(int)($db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_MAX_FAILS'")['value']??3);
                $rows=$db->all("SELECT id,expires_at,renew_fail_count FROM subscriptions WHERE auto_renew=1 AND status='active' AND expires_at>? AND renew_at IS NOT NULL AND renew_at<=? AND (renew_order_id IS NULL OR renew_order_id='') AND renew_fail_count<? LIMIT 100",[time(),time(),$maxFails]);
                $db->transaction(function()use($rows){foreach($rows as $r)$this->app->outbox->enqueue('subscription.renew','renew:'.$r['id'].':'.$r['expires_at'].':'.$r['renew_fail_count'],['subscription_id'=>$r['id']]);});
                // Handle failed renewal orders: if renew_order is canceled/expired, schedule retry
                $failed=$db->all("SELECT s.id,s.renew_order_id,s.renew_fail_count FROM subscriptions s JOIN orders o ON o.id=s.renew_order_id WHERE s.auto_renew=1 AND s.status='active' AND o.status='canceled' LIMIT 100");
                foreach($failed as $f){
                    $db->transaction(function()use($f,$maxFails){
                        $fresh=$this->app->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->app->db->lock(),[$f['id']]);
                        if(!$fresh || $fresh['renew_order_id']!==$f['renew_order_id']) return;
                        if((int)$fresh['renew_fail_count']>=$maxFails) return;
                        $this->app->db->execute('UPDATE subscriptions SET renew_order_id=NULL,renew_failed_at=?,renew_fail_count=renew_fail_count+1,renew_at=? WHERE id=?',[time(),time()+3600,$f['id']]);
                    });
                }
                // Expired renew window: if subscription expired and auto_renew still on, disable after max fails
                $db->execute("UPDATE subscriptions SET auto_renew=0 WHERE auto_renew=1 AND status='expired'");
            }
            // Remnawave sync: periodic drift check (once per hour, via advisory lease)
            $syncLease=$db->one("SELECT * FROM advisory_leases WHERE name='remnawave-sync'");
            $shouldSync=!$syncLease || (int)$syncLease['expires_at']<time();
            if($shouldSync){
                $db->execute("INSERT INTO advisory_leases VALUES('remnawave-sync',?,?) ON CONFLICT(name) DO UPDATE SET token=excluded.token,expires_at=excluded.expires_at",[Database::id(),time()+3600]);
                // Only sync if remnawave is configured
                $hasRemnawave=$db->one("SELECT value FROM app_settings WHERE name='REMNAWAVE_URL'");
                if($hasRemnawave && $hasRemnawave['value']!==''){
                    try {
                        $sync=new \App\Integration\RemnawaveSync($db,new \App\Integration\RemnawaveProvisioner(
                            \Symfony\Component\HttpClient\HttpClient::create(),
                            $this->app->config['REMNAWAVE_URL']??'',
                            $this->app->config['REMNAWAVE_TOKEN']??'',
                            $this->app->config['REMNAWAVE_SQUAD_UUID']??''
                        ));
                        $heal=$db->one("SELECT enabled FROM feature_flags WHERE name='reconciliation.auto_heal'");
                        $report=$sync->run(50,(int)($heal['enabled']??0)===1);
                        if($report['fixed']>0||$report['reprovisioned']>0||$report['disabled']>0){
                            error_log(json_encode(['event'=>'remnawave.sync','fixed'=>$report['fixed'],'reprovisioned'=>$report['reprovisioned'],'disabled'=>$report['disabled'],'errors'=>$report['errors']]));
                        }
                    } catch (\Throwable $e) {
                        error_log(json_encode(['event'=>'remnawave.sync.failed','error'=>get_class($e)]));
                    }
                }
            }
            // Self-heal subscription expiry: keep status AND lifecycle_status consistent
            // so the "active-but-expired" invariant (lifecycle_status='active' && expires_at<=now)
            // never accumulates. lifecycle_status is the source the invariant checks.
            $db->execute("UPDATE subscriptions SET status='expired', lifecycle_status='expired' WHERE status='active' AND expires_at<=?",[time()]);
            // Also sweep subs whose lifecycle_status still says active/grace but the date passed
            // (drift from webhooks, manual edits or interrupted renewals) so the dashboard
            // self-cleans. Keyed on lifecycle_status, since status may already be 'expired'.
            $db->execute("UPDATE subscriptions SET status='expired', lifecycle_status='expired' WHERE lifecycle_status IN ('active','grace') AND expires_at<=?",[time()]);
            // Auto-close operational cases whose underlying violation is already gone
            // (the billing self-healed the data, so the open case is stale). This keeps the
            // "Требуют внимания" queue honest without manual review for self-healed issues.
            $db->execute("UPDATE operational_cases SET status='resolved',resolved_at=? WHERE status='open' AND title='Активная подписка с истёкшим сроком' AND subscription_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.id=operational_cases.subscription_id AND s.lifecycle_status='active' AND s.expires_at<=?)",[time(),time()]);
            $db->execute("UPDATE operational_cases SET status='resolved',resolved_at=? WHERE status='open' AND title='Оплата/заказ без выдачи подписки' AND EXISTS (SELECT 1 FROM orders o JOIN subscriptions s ON s.order_id=o.id WHERE o.id=CAST(operational_cases.details AS jsonb)->>'order_id')",[time()]);
            // Flush pending transactional emails (registration welcome, password reset)
            try { $this->app->mailer->flushQueue(50); } catch (\Throwable $e) { error_log(json_encode(['event'=>'mail.flush.failed','error'=>get_class($e)])); }
            foreach(['sessions','telegram_links','login_challenges','mfa_enrollments','rate_limits'] as $table)$db->execute("DELETE FROM $table WHERE expires_at<=?",[time()]);
            $db->execute("DELETE FROM outbox WHERE status='done' AND created_at<?",[time()-30*86400]);
            $db->execute('DELETE FROM telegram_updates WHERE created_at<?',[time()-7*86400]);
            self::heartbeat($db,'scheduler');return 0;
        }finally{$db->execute("DELETE FROM advisory_leases WHERE name='reconcile' AND token=?",[$token]);}
    }
    public static function heartbeat(Database $db,string $name):void{$db->execute('INSERT INTO runtime_heartbeats VALUES(?,?) ON CONFLICT(name) DO UPDATE SET seen_at=excluded.seen_at',[$name,time()]);}
}
