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
            if (isset($this->app->compensations)) $this->app->compensations->reconcileStatus();
            $money=(new \App\Observability\ConsistencyChecker($db))->run();
            // Some operational tests/tools construct a minimal Container.
            // `isset` is safe for an uninitialized typed property.
            if (isset($this->app->creators)) {
                $this->app->creators->releaseDue();
                $this->app->creators->reconcile('scheduler');
            }
            if (isset($this->app->paymentService)) $this->app->paymentService->requeueStaleEvents();
            if($money['status']!=='ok') error_log(json_encode(['event'=>'financial.drift','count'=>$money['count']]));
            // Paid-but-not-delivered protection (alert only — no money mutation):
            // a succeeded payment whose order is not paid/fulfilled means the
            // customer paid but the service was never issued. Surface each such
            // order as a visible, deduplicated operation so it is not silently
            // lost in dead jobs. Mark failed/critical so it shows as a problem
            // awaiting manual crediting. Uses a dedicated ConsistencyChecker
            // instance since Container does not expose one as a property.
            if (isset($this->app->operations)) {
                $gaps = (new \App\Observability\ConsistencyChecker($this->app->db))->deliveryGaps();
                foreach ($gaps as $gap) {
                    $ops = $this->app->operations;
                    $op = $ops->start('payment.delivery_gap', [
                        'user_id'  => $gap['user_id'],
                        'order_id' => $gap['order_id'],
                        'metadata' => [
                            'provider'            => $gap['provider'],
                            'provider_payment_id' => $gap['provider_payment_id'],
                            'amount_minor'        => $gap['amount_minor'],
                            'order_status'        => $gap['status'],
                        ],
                    ], 'delivery-gap:'.$gap['order_id']);
                    if (!$op['existing']) {
                        $ops->event($op['id'], 'payment.delivery_gap.detected', 'failed',
                            'Клиент заплатил, но услуга не выдана (заказ '.substr($gap['order_id'],0,8)
                            .', '.\App\Integration\Payment\AbstractProvider::decimal($gap['amount_minor']).' ₽, провайдер '.$gap['provider']
                            .'). Проверьте и зачислите вручную в Ops.',
                            ['metadata' => ['amount_minor' => $gap['amount_minor']]]);
                        $ops->complete($op['id'], 'failed', 'Ожидает ручного зачисления');
                    }
                }
            }
            // Breaker-open alert (read-only monitor — no money mutation). An
            // OPEN circuit breaker means an upstream (payment provider or
            // Remnawave) is failing and fail-fast is short-circuiting calls.
            // Surface each open breaker as a deduplicated Ops operation so the
            // operator is notified of the outage, not only via /admin/switches.
            if (isset($this->app->operations) && isset($this->app->circuitBreaker)) {
                $open = $this->app->db->all("SELECT name,state,failures,opened_at FROM circuit_breakers WHERE state<>'closed' ORDER BY name");
                foreach ($open as $b) {
                    $ops = $this->app->operations;
                    $name = (string)$b['name'];
                    $op = $ops->start('upstream.breaker_open', ['metadata' => [
                        'breaker' => $name,
                        'state'   => (string)$b['state'],
                        'failures'=> (int)$b['failures'],
                        'opened_at'=> (int)$b['opened_at'],
                    ]], 'breaker-open:'.$name);
                    if (!$op['existing']) {
                        $statusMsg = $name==='remnawave_api' ? 'Remnawave' : ('платежный провайдер «'.str_replace('_api','',$name).'»');
                        $ops->event($op['id'], 'upstream.breaker_open.detected', 'failed',
                            'Circuit breaker «'.$name.'» открыт ('.(string)$b['state'].', failures='.(int)$b['failures']
                            .'). Upstream '.$statusMsg.' недоступен — вызовы отклоняются fail-fast. Проверьте сервис и сбросьте breaker в /admin/switches.',
                            ['metadata' => ['failures' => (int)$b['failures']]]);
                        $ops->complete($op['id'], 'failed', 'Ожидает проверки оператора');
                    }
                }
                // Auto-resolve: when a breaker has returned to closed, resolve any
                // still-open breaker_open operation so stale failed alerts do not
                // accumulate. Matches only not-completed ops for that breaker.
                $resolved = $this->app->db->all(
                    "SELECT o.id,o.correlation_id FROM operations o WHERE o.type='upstream.breaker_open' AND o.status IN ('processing','failed') AND o.correlation_id LIKE 'breaker-open:%'"
                );
                $closed = $this->app->db->all("SELECT name FROM circuit_breakers WHERE state='closed'");
                $closedSet = [];
                foreach ($closed as $c) $closedSet[(string)$c['name']] = true;
                foreach ($resolved as $ro) {
                    $bname = substr($ro['correlation_id'], strlen('breaker-open:'));
                    if (isset($closedSet[$bname])) {
                        $ops = $this->app->operations;
                        $ops->event($ro['id'], 'upstream.breaker_open.resolved', 'success',
                            'Circuit breaker «'.$bname.'» вернулся в closed — сервис восстановлен.',
                            ['metadata' => ['breaker' => $bname]]);
                        $ops->complete($ro['id'], 'success', 'Breaker закрылся');
                    }
                }
            }
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
                : ['platega'];
            do{
                $placeholders=implode(',',array_fill(0,count($providers),'?'));
                $rows=$providers===[]?[]:$db->all("SELECT id,provider,provider_payment_id FROM orders WHERE id>? AND status='pending' AND provider IN ($placeholders) AND provider_payment_id IS NOT NULL ORDER BY id LIMIT 100",array_merge([$after],$providers));
                $db->transaction(function()use($rows,$bucket){foreach($rows as $row){$this->app->outbox->enqueue('payment.verify','reconcile:'.$row['id'].':'.$bucket,['payment_id'=>$row['provider_payment_id'],'provider'=>$row['provider']]);}});
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
            // §7.2 protection: the worst-case enqueue-gap is an order committed
            // as paid whose subscription was never created (settle() crashed
            // between the UPDATE orders and the INSERT subscriptions, both of
            // which live in the same tx — so this should not happen; if it
            // does, no subscription.provision job exists to re-run). Such an
            // order is money received with no service. Surface each one as a
            // visible, deduplicated operation (read-only, no money mutation) so
            // an operator credits/settles manually — same philosophy as
            // payment.delivery_gap. Also re-enqueue provisioning for paid
            // orders whose subscription is created but never left pending
            // (safe recovery: Worker::provision is idempotent via the
            // deterministic zb_<sub> username).
            $this->reconcilePaidNoSubscription($db);
            // Durable workflow recovery is independent of outbox retention.
            // It only recreates a disposable delivery command; the payment and
            // service intent were already committed in PostgreSQL.
            if (isset($this->app->workflows)) $this->app->workflows->recover(100);
            // Auto-renew: enqueue renew jobs for subscriptions near expiry
            $autoEnabled=$db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_ENABLED'");
            if (!$autoEnabled || $autoEnabled['value']==='1') {
                $maxFails=(int)($db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_MAX_FAILS'")['value']??3);
                $rows=$db->all("SELECT s.id,expires_at FROM subscriptions s JOIN plans p ON p.id=COALESCE(s.renew_plan_id,s.plan_id) WHERE s.auto_renew=1 AND s.status='active' AND s.expires_at>? AND s.renew_at IS NOT NULL AND s.renew_at<=? AND p.duration_days>1 AND (s.renew_order_id IS NULL OR s.renew_order_id='') AND s.renew_fail_count < COALESCE(NULLIF(p.autorenew_max_fails,0),?) LIMIT 100",[time(),time(),$maxFails]);
                // Insufficient wallet balance is a normal, recoverable state:
                // do not exhaust a retry counter that would prevent a later
                // topup from renewing the subscription.
                $db->transaction(function()use($rows){foreach($rows as $r)$this->app->outbox->enqueue('subscription.renew','renew:'.$r['id'].':'.intdiv(time(),60),['subscription_id'=>$r['id']]);});
                // Daily auto-charge: wake daily-priced active subs whose period has elapsed.
                $daily=$db->all("SELECT s.id FROM subscriptions s JOIN plans p ON p.id=COALESCE(s.renew_plan_id,s.plan_id) WHERE s.auto_renew=1 AND s.status='active' AND s.expires_at>? AND (s.last_daily_charge_at IS NULL OR s.last_daily_charge_at + p.duration_days*86400 <= ?) AND p.duration_days<=1 AND (s.renew_order_id IS NULL OR s.renew_order_id='') LIMIT 200",[time(),time()]);
                $db->transaction(function()use($daily){foreach($daily as $r)$this->app->outbox->enqueue('subscription.daily','daily:'.$r['id'].':'.intdiv(time(),3600),['subscription_id'=>$r['id']]);});
                // Handle failed renewal orders: if renew_order is canceled/expired, schedule retry
                $failed=$db->all("SELECT s.id,s.renew_order_id,s.renew_fail_count FROM subscriptions s JOIN orders o ON o.id=s.renew_order_id WHERE s.auto_renew=1 AND s.status='active' AND o.status='canceled' LIMIT 100");
                foreach($failed as $f){
                    $db->transaction(function()use($f,$maxFails){
                        $fresh=$this->app->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->app->db->lock(),[$f['id']]);
                        if(!$fresh || $fresh['renew_order_id']!==$f['renew_order_id']) return;
                        $prow=$this->app->db->one('SELECT autorenew_max_fails FROM plans WHERE id=?',[($fresh['renew_plan_id']??$fresh['plan_id'])]);
                        $eff=(isset($prow['autorenew_max_fails']) && (int)$prow['autorenew_max_fails']>0)?(int)$prow['autorenew_max_fails']:$maxFails;
                        if((int)$fresh['renew_fail_count']>=$eff) return;
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
            // `details` is JSON text. Keep this maintenance query portable to
            // the SQLite test/development backend as well as PostgreSQL; the
            // stored JSON is generated by json_encode(), so this exact field
            // fragment cannot match a different key or a prefix of another id.
            $db->execute("UPDATE operational_cases SET status='resolved',resolved_at=? WHERE status='open' AND title='Оплата/заказ без выдачи подписки' AND EXISTS (SELECT 1 FROM orders o JOIN subscriptions s ON s.order_id=o.id WHERE operational_cases.details LIKE '%\"order_id\":\"' || o.id || '\"%')",[time()]);
            // Flush pending transactional emails (registration welcome, password reset)
            try { $this->app->mailer->flushQueue(50); } catch (\Throwable $e) { error_log(json_encode(['event'=>'mail.flush.failed','error'=>get_class($e)])); }
            foreach(['sessions','telegram_links','login_challenges','mfa_enrollments','rate_limits'] as $table)$db->execute("DELETE FROM $table WHERE expires_at<=?",[time()]);
            $db->execute("DELETE FROM outbox WHERE status='done' AND created_at<?",[time()-30*86400]);
            $db->execute('DELETE FROM telegram_updates WHERE created_at<?',[time()-7*86400]);
            self::heartbeat($db,'scheduler');return 0;
        }finally{$db->execute("DELETE FROM advisory_leases WHERE name='reconcile' AND token=?",[$token]);}
    }
    public static function heartbeat(Database $db,string $name):void{$db->execute('INSERT INTO runtime_heartbeats VALUES(?,?) ON CONFLICT(name) DO UPDATE SET seen_at=excluded.seen_at',[$name,time()]);}
    /**
     * §7.2 proactive protection for the worst-case enqueue-gap: an order
     * committed as paid whose subscription was never created (settle() would
     * crash between UPDATE orders and INSERT subscriptions, both of which live
     * in the same tx — so this should not happen; if it does there is no
     * subscription.provision job to re-run). Money received with no service:
     * surface each one as a deduplicated, read-only operation so an operator
     * credits/settles manually — same philosophy as payment.delivery_gap.
     * Also re-enqueue provisioning for paid orders whose subscription was
     * created but never left pending (safe recovery: Worker::provision is
     * idempotent via the deterministic zb_<sub> username). This is NOT covered
     * by OperationsIntelligence::invariants(true), which only runs on an admin
     * manual click (/admin/intelligence); here it runs every reconcile tick.
     */
    private function reconcilePaidNoSubscription(Database $db): void
    {
        if (!isset($this->app->operations) || !isset($this->app->outbox)) return;
        $paidNoSub=$db->all(
            "SELECT o.id AS order_id,o.user_id,o.provider,o.plan_id,o.price_minor,o.status,o.paid_at FROM orders o
             WHERE o.status IN ('paid') AND o.paid_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.status='succeeded')
               AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.order_id=o.id)
             ORDER BY o.paid_at LIMIT 50"
        );
        foreach ($paidNoSub as $g) {
            $ops=$this->app->operations;
            $op=$ops->start('payment.paid_no_subscription',[
                'user_id'=>$g['user_id'],
                'order_id'=>$g['order_id'],
                'metadata'=>['provider'=>$g['provider'],'plan_id'=>$g['plan_id'],'amount_minor'=>(int)$g['price_minor'],'status'=>$g['status']],
            ],'paid-no-subscription:'.$g['order_id']);
            if ($op['existing']) continue;
            $dec=\App\Integration\Payment\AbstractProvider::decimal((int)$g['price_minor']);
            $ops->event($op['id'],'payment.paid_no_subscription.detected','failed',
                'Клиент заплатил ('.$dec.' ₽), но подписка НЕ создана (заказ '.substr($g['order_id'],0,8).'). Разрыв commit/enqueue — зачислите вручную и/или выдайте доступ.',
                ['metadata'=>['amount_minor'=>(int)$g['price_minor']]]);
            $ops->complete($op['id'],'failed','Ожидает ручного зачисления');
            if ($this->app->mailer !== null) {
                $adm=$db->one("SELECT email FROM users WHERE role='admin' AND email IS NOT NULL ORDER BY created_at LIMIT 1")['email']??'';
                if ($adm!=='') { try { $this->app->mailer->queue($adm,'[ASTRACAT] Paid order without subscription: '.substr($g['order_id'],0,8),'Клиент заплатил, но подписка НЕ создана. Разрыв enqueue. Проверьте в Ops (/admin/operations).'); } catch (\Throwable) {} }
            }
        }
        // Paid order whose sub exists but never left pending => re-enqueue provision.
        $paidSubPending=$db->all(
            "SELECT s.id AS sub_id,o.id AS order_id FROM orders o
             JOIN subscriptions s ON s.order_id=o.id
             WHERE o.status='paid' AND s.lifecycle_status IN ('pending')
               AND s.remote_id IS NULL AND s.expires_at>?
             ORDER BY o.paid_at LIMIT 100",[time()]
        );
        foreach ($paidSubPending as $ps) {
            $this->app->outbox->enqueue('subscription.provision','reconcile-paid-pending:'.$ps['sub_id'].':'.intdiv(time(),300),['subscription_id'=>$ps['sub_id']]);
        }
    }
}
