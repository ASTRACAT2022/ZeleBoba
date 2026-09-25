<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Integration\{Payments,Provisioner,PaymentService};
use App\Billing\{TopupService,CustomerTimeline,BillingService};
use App\Observability\OperationsService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
final class Worker
{
    public function __construct(private Database $db, private Outbox $outbox, private Payments $payments, private Provisioner $provisioner, private HttpClientInterface $http, private string $botToken, private bool $allowDemo=true, private string $telegramApiBase='https://astracattg.netlify.app', private ?TopupService $topups=null, private ?\App\Billing\AutoPurchaseService $autoPurchase=null, private ?PaymentService $paymentService=null, private ?\App\Billing\ReferralService $referrals=null, private ?\App\Billing\BroadcastService $broadcasts=null, private ?\App\Billing\CompensationService $compensations=null, private string $defaultProvisionDriver='demo', private ?CustomerTimeline $timeline=null, private ?DurableWorkflow $workflows=null, private ?BillingService $billing=null) {}
    public function handle(string $topic,array $payload): void
    {
        $span=Telemetry::start('billing.outbox.process',['messaging.operation'=>'process','messaging.destination.name'=>$topic]);
        try {
            match ($topic) {
                'payment.create'=>$this->paymentService?$this->paymentService->createOrder($payload['order_id']):$this->payments->create($payload['order_id']),
                'payment.verify'=>$this->paymentService?$this->paymentService->verify($payload['payment_id'],$payload['provider']??null):$this->payments->refresh($payload['payment_id']),
                'payment.event.process'=>$this->paymentService===null ? throw new \RuntimeException('Payment event processor unavailable') : $this->paymentService->processEvent($payload['event_id']),
                'topup.create'=>$this->topupCreate($payload['topup_id']),
                'topup.after'=>$this->topupAfter($payload['user_id']),
                'referral.topup'=>isset($payload['topup_id'])?$this->referrals?->processSettledTopup($payload['topup_id']):$this->referralTopup($payload['user_id'],(int)($payload['amount_kopeks']??0)),
                'subscription.provision'=>$this->provision($payload['subscription_id']),
                'subscription.extend'=>$this->extend($payload['subscription_id'],$payload['order_id']??null),
                'subscription.renew'=>$this->renew($payload['subscription_id']),
                'subscription.daily'=>$this->dailyCharge($payload['subscription_id']),
                'subscription.traffic'=>$this->traffic($payload['subscription_id'],(int)($payload['traffic_gb']??0)),
                'subscription.devices'=>$this->devices($payload['subscription_id'],(int)($payload['devices']??0)),
                'subscription.admin_sync'=>$this->adminSync($payload['subscription_id']),
                'gift.create'=>$this->giftCreate($payload),
                'broadcast.run'=>$this->broadcastRun($payload['broadcast_id']),
                'broadcast.send'=>$this->broadcastSend($payload['broadcast_id'],$payload['chat_id'],$payload['text']),
                'compensation.run'=>$this->compensationRun($payload['compensation_id']),
                'compensation.grant'=>$this->compensationGrant($payload['compensation_id'],$payload['user_id']),
                'telegram.send'=>$this->send($payload),
                'telegram.answer'=>$this->answer($payload),
                default=>throw new \RuntimeException('Unknown outbox topic')
            };
            Telemetry::complete($span);
        } catch (\Throwable $e) {
            Telemetry::fail($span,$e);
            throw $e;
        }
    }
    private function broadcastRun(string $id): void
    {
        if ($this->broadcasts) $this->broadcasts->run($id);
    }
    private function compensationRun(string $id): void
    {
        if ($this->compensations) $this->compensations->run($id);
    }
    private function compensationGrant(string $id, string $userId): void
    {
        if ($this->compensations) $this->compensations->grant($id, $userId);
    }
    private function broadcastSend(string $id, string $chatId, string $text): void
    {
        try {
            $this->send(['chat_id' => $chatId, 'text' => $text]);
        } catch (JobPermanentFailure $e) {
            // The outbox records the terminal result with its current lease.
            throw $e;
        } catch (\Throwable $e) {
            // A temporary failure is still pending; count only its final outcome.
            throw new \RuntimeException('Broadcast send failed', 0, $e);
        }
    }
    private function topupCreate(string $id): void
    {
        $topup=$this->db->one('SELECT * FROM topups WHERE id=?',[$id]);
        if (!$topup || $topup['status']!=='pending' || $topup['checkout_url']) return;
        if ($this->paymentService) { $this->paymentService->createTopup($id); return; }
        $this->payments->createTopup($topup);
    }
    private function topupAfter(string $userId): void
    {
        if ($this->autoPurchase) $this->autoPurchase->afterTopup($userId);
        // A successful topup is the fastest safe wake-up signal for a renewal
        // that was waiting for balance. Row-level locking in BillingService
        // makes this harmless if the scheduler wakes it simultaneously.
        if ($this->billing) {
            $now=time();
            foreach($this->db->all("SELECT s.id,p.duration_days FROM subscriptions s JOIN plans p ON p.id=COALESCE(s.renew_plan_id,s.plan_id) WHERE s.user_id=? AND s.auto_renew=1 AND s.status='active' AND s.renew_order_id IS NULL AND s.expires_at>? AND (s.renew_at<=? OR s.renew_failed_at IS NOT NULL)",[$userId,$now,$now]) as $sub) {
                // A balance topup is an explicit retry signal, so make a
                // previously backoff-scheduled insufficient-funds renewal due
                // now. The renewal transaction still verifies every guard.
                $this->db->execute('UPDATE subscriptions SET renew_at=? WHERE id=? AND renew_order_id IS NULL',[$now,$sub['id']]);
                $daily=(int)$sub['duration_days']>0 && (int)$sub['duration_days']<=1;
                $topic=$daily?'subscription.daily':'subscription.renew';
                $prefix=$daily?'daily':'renew';
                $bucket=$daily?intdiv($now,3600):intdiv($now,60);
                $this->outbox->enqueue($topic,$prefix.':'.$sub['id'].':'.$bucket,['subscription_id'=>$sub['id']]);
            }
        }
    }
    private function referralTopup(string $userId, int $amountKopeks): void
    {
        if ($this->referrals) $this->referrals->processTopup($userId, $amountKopeks);
    }
    private function subscription(string $id): ?array
    {
        $s=$this->db->one("SELECT s.*,
            s.device_limit AS devices,COALESCE(o.provision_driver,?) AS provision_driver,
            COALESCE(o.squad_uuid,p.squad_uuid,'') AS squad_uuid
            FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id
            LEFT JOIN plans p ON p.id=s.plan_id WHERE s.id=?",[$this->defaultProvisionDriver,$id]);
        if (!$s) return null;
        // Traffic bytes computed in PHP to avoid integer overflow on large plans
        // (GB*1073741824 exceeds PostgreSQL int4 for >2GB plans).
        $s['traffic_bytes']=(int)$s['traffic_limit_gb']===0?0:((int)$s['traffic_limit_gb']+(int)$s['purchased_traffic_gb'])*1073741824;
        return $s;
    }
    private function traffic(string $id,int $gb): void
    {
        if($this->provisioningPaused()) throw new JobDeferred($this->provisioningDelay());
        $s=$this->subscription($id);
        if ($s && $s['provision_driver']!=='demo' && in_array($s['status'],['active','provisioning'],true) && $s['remote_id']===null) throw new JobDeferred(30);
        if (!$s || $s['status']!=='active' || $s['provision_driver']==='demo') return;
        $this->provisioner->setTraffic($s,0);
    }
    private function devices(string $id,int $count): void
    {
        if($this->provisioningPaused()) throw new JobDeferred($this->provisioningDelay());
        $s=$this->subscription($id);
        if ($s && $s['provision_driver']!=='demo' && in_array($s['status'],['active','provisioning'],true) && $s['remote_id']===null) throw new JobDeferred(30);
        if (!$s || $s['status']!=='active' || $s['provision_driver']==='demo') return;
        $this->provisioner->setDevices($s,0);
    }
    private function adminSync(string $id): void
    {
        if($this->provisioningPaused()) throw new JobDeferred($this->provisioningDelay());
        $s=$this->subscription($id);
        if (!$s || $s['status']!=='active' || $s['provision_driver']==='demo') return;
        if ((int)($s['remnawave_id']??0)>0) {
            if (!method_exists($this->provisioner,'updateById')) throw new \RuntimeException('Provisioner cannot sync legacy subscription by id');
            $this->provisioner->updateById((int)$s['remnawave_id'],(int)$s['traffic_bytes'],(int)$s['devices'],(int)$s['expires_at']);
            return;
        }
        if ($s['remote_id']===null) throw new JobDeferred(30);
        $this->provisioner->extend($s);
    }
    private function giftCreate(array $payload): void
    {
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[\App\Infrastructure\Database::id(),$payload['user_id'],'gift.created',$payload['plan_id'],time()]);
    }
    private function provision(string $id): void
    {
        if($this->provisioningPaused()) throw new JobDeferred($this->provisioningDelay());
        $flag=$this->db->one("SELECT enabled FROM feature_flags WHERE name='provisioning.enabled'");
        if($flag && (int)$flag['enabled']===0) throw new \RuntimeException('Provisioning disabled by kill switch');
        $lease=$this->workflows?->claimActivation($id,gethostname().':'.getmypid());
        // Legacy subscriptions have no operation row yet and remain supported.
        if ($this->workflows!==null && $this->db->one("SELECT id FROM provisioning_operations WHERE subscription_id=? AND operation_type='activate'",[$id]) && !$lease) return;
        $s=$this->subscription($id);
        // A local remote_id is only a remembered result, not proof that the
        // account still exists in the panel. A crash after local activation
        // must be recovered by a live read before completing the workflow.
        if ($s && $s['status']==='active' && ($s['remote_id']!==null || (int)($s['remnawave_id']??0)>0 || !empty($s['remnawave_short_uuid']))) {
            $verifiedId=$s['remote_id'];
            $verifiedUrl=$s['subscription_url'];
            if ($s['provision_driver']!=='demo') {
                try {
                    if (!method_exists($this->provisioner,'resolve')) throw new \RuntimeException('Provisioner cannot verify panel account');
                    $remote=$this->provisioner->resolve($s);
                    $panelId=(int)($remote['id']??0);
                    if ($panelId<=0) throw new \RuntimeException('Remnawave user not found after activation');
                    $this->verifyExtended($s,$panelId);
                    $verifiedId=(string)$panelId;
                    $verifiedUrl=$remote['subscriptionUrl']??$verifiedUrl;
                    $this->db->execute('UPDATE subscriptions SET remote_id=?,remnawave_id=?,subscription_url=COALESCE(subscription_url,?) WHERE id=?',[$verifiedId,$panelId,$verifiedUrl,$id]);
                } catch (\Throwable $e) {
                    $this->workflows?->unknown($id,$e);
                    throw $e;
                }
            }
            $this->workflows?->succeeded($id,['remote_id'=>$verifiedId,'subscription_url'=>$verifiedUrl]);
            return;
        }
        if (!$s || !in_array($s['status'],['provisioning','active','trial'],true) || $s['remote_id']!==null || (int)$s['expires_at']<=time()) return;
        $this->db->execute("UPDATE provisioning_accounts SET state='processing',updated_at=? WHERE subscription_id=? AND provider=?",[time(),$id,$s['provision_driver']]);
        $this->operationEvent($id,'provisioning.started','processing','Provisioning job started');
        $this->timeline?->record($s['user_id'], 'vpn.provisioning_started', ['subscription_id'=>$id]);
        if($s['provision_driver']==='demo' && !$this->allowDemo) throw new \RuntimeException('Demo provisioning forbidden');
        try {
            $remote=$s['provision_driver']==='demo'?(new \App\Integration\DemoProvisioner())->provision($s):$this->provisioner->provision($s);
            if ($s['provision_driver']!=='demo') {
                if (!method_exists($this->provisioner,'resolve')) throw new \RuntimeException('Provisioner cannot verify panel account');
                $panel=$this->provisioner->resolve($s);
                $panelId=(int)($panel['id']??0);
                if ($panelId<=0) throw new \RuntimeException('Remnawave user not found after activation');
                $this->verifyExtended($s,$panelId);
            }
        } catch (\Throwable $e) {
            // The request may have reached Remnawave even if its response did
            // not. Preserve UNKNOWN and let the next leased run verify the
            // deterministic remote identity instead of treating it as failure.
            $this->workflows?->unknown($id,$e);
            throw $e;
        }
        $this->db->transaction(function () use ($s,$remote,$id) {
            $changed=$this->db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',remote_id=?,subscription_url=?,updated_at=?,version=version+1 WHERE id=? AND status IN ('provisioning','active','trial') AND remote_id IS NULL",[$remote['id'],$remote['url'],time(),$id]);
            if (!$changed) return;
            // Provisioning may finish after an operator cancellation/refund.
            // Never overwrite a terminal order state from an asynchronous job.
            $this->db->execute("UPDATE orders SET status='fulfilled',workflow_status='fulfilled' WHERE id=? AND status='paid'",[$s['order_id']]);
            $this->db->execute("UPDATE provisioning_accounts SET external_user_id=?,state='active',last_synced_at=?,last_error=NULL,updated_at=? WHERE subscription_id=? AND provider=?",[$remote['id'],time(),time(),$id,$s['provision_driver']]);
            $this->operationEvent($id,'provisioning.completed','success','Provisioning ACTIVE');
            $this->timeline?->record($s['user_id'], 'vpn.resource_updated', ['subscription_id'=>$id]);
            $this->timeline?->record($s['user_id'], 'subscription.active', ['subscription_id'=>$id]);
            $user=$this->db->one('SELECT telegram_id FROM users WHERE id=?',[$s['user_id']]);
            if ($user['telegram_id']) $this->outbox->enqueue('telegram.send','activated:'.$id,['chat_id'=>$user['telegram_id'],'text'=>'Подписка готова. Откройте веб-кабинет или отправьте /status.']);
        });
        $this->workflows?->succeeded($id,['remote_id'=>$remote['id'],'subscription_url'=>$remote['url']]);
    }
    private function extend(string $id, ?string $orderId=null): void
    {
        if($this->provisioningPaused()) throw new JobDeferred($this->provisioningDelay());
        $flag=$this->db->one("SELECT enabled FROM feature_flags WHERE name='provisioning.enabled'");
        if($flag && (int)$flag['enabled']===0) throw new \RuntimeException('Provisioning disabled by kill switch');
        $s=$this->subscription($id);
        if ($s && $s['status']==='provisioning') throw new JobDeferred(30);
        if (!$s || $s['status']!=='active') return;
        $this->db->execute("UPDATE provisioning_accounts SET state='processing',updated_at=? WHERE subscription_id=? AND provider=?",[time(),$id,$s['provision_driver']]);
        $this->operationEvent($id,'provisioning.started','processing','Provisioning synchronization started');
        if($s['provision_driver']==='demo'){
            $this->markExtended($s,$orderId);
            return;
        }
        if ($s['remote_id']===null && (int)($s['remnawave_id']??0)<=0) { $this->provision($id); return; }
        if (!method_exists($this->provisioner,'resolve') || !method_exists($this->provisioner,'updateById')) throw new \RuntimeException('Provisioner cannot resolve panel account');
        $remote=$this->provisioner->resolve($s);
        $panelId=(int)($remote['id']??0);
        if ($panelId<=0) throw new \RuntimeException('Remnawave user not found for renewal');
        $this->provisioner->updateById($panelId,(int)$s['traffic_bytes'],(int)$s['devices'],(int)$s['expires_at']);
        $this->verifyExtended($s,$panelId);
        $this->markExtended($s,$orderId,$panelId);
        $this->timeline?->record($s['user_id'], 'vpn.resource_updated', ['subscription_id'=>$id]);
        $this->operationEvent($id,'provisioning.completed','success','Remnawave synchronization successful',['expires_at'=>$s['expires_at']]);
        $user=$this->db->one('SELECT telegram_id FROM users WHERE id=?',[$s['user_id']]);
        if ($user['telegram_id']) $this->outbox->enqueue('telegram.send','renewed:'.$id.':'.$s['expires_at'],['chat_id'=>$user['telegram_id'],'text'=>'Подписка продлена до '.gmdate('d.m.Y H:i',(int)$s['expires_at']).' UTC.']);
    }
    private function verifyExtended(array $s, int $panelId): void
    {
        if (!method_exists($this->provisioner,'fetchById')) throw new \RuntimeException('Provisioner cannot verify panel account');
        $remote=$this->provisioner->fetchById($panelId);
        $expiry=is_array($remote)?strtotime((string)($remote['expireAt']??'')):false;
        if (!$expiry || abs($expiry-(int)$s['expires_at'])>60 || ($remote['status']??null)!=='ACTIVE') {
            throw new \RuntimeException('Remnawave expiry verification failed');
        }
    }
    private function markExtended(array $s, ?string $orderId, ?int $panelId=null): void
    {
        $now=time();
        $this->db->transaction(function() use($s,$now,$orderId,$panelId) {
            if ($orderId!==null) $this->db->execute("UPDATE orders SET status='fulfilled',workflow_status='fulfilled' WHERE id=? AND status='paid'",[$orderId]);
            if ($panelId!==null) $this->db->execute('UPDATE subscriptions SET remote_id=?,remnawave_id=? WHERE id=?',[(string)$panelId,$panelId,$s['id']]);
            $this->db->execute("UPDATE provisioning_accounts SET state='active',last_synced_at=?,last_error=NULL,updated_at=? WHERE subscription_id=? AND provider=?",[$now,$now,$s['id'],$s['provision_driver']]);
        });
    }
    private function provisioningPaused(): bool
    {
        $safe=$this->db->one("SELECT value FROM app_settings WHERE name='GLOBAL_SAFETY_MODE'");
        if(($safe['value']??'0')==='1') return true;
        return (bool)$this->db->one("SELECT id FROM service_maintenance_windows WHERE service='remnawave' AND starts_at<=? AND ends_at>? LIMIT 1",[time(),time()]);
    }
    private function provisioningDelay(): int
    {
        $window=$this->db->one("SELECT ends_at FROM service_maintenance_windows WHERE service='remnawave' AND starts_at<=? AND ends_at>? ORDER BY ends_at DESC LIMIT 1",[time(),time()]);
        return $window ? max(30,(int)$window['ends_at']-time()+5) : 60;
    }
    private function operationEvent(string $subscriptionId,string $type,string $status,string $message,array $metadata=[]): void
    {
        $op=$this->db->one('SELECT id FROM operations WHERE subscription_id=? ORDER BY started_at DESC LIMIT 1',[$subscriptionId]);
        if($op)(new OperationsService($this->db))->event($op['id'],$type,$status,$message,['subscription_id'=>$subscriptionId,'metadata'=>$metadata]);
    }
    /** Clean daily auto-charge for daily-priced tariffs (see BillingService::dailyChargeFromBalance). */
    private function dailyCharge(string $subscriptionId): void
    {
        if ($this->billing) {
            $this->billing->dailyChargeFromBalance($subscriptionId);
            return;
        }
        // Without BillingService wiring nothing safe can be done here; the
        // subscription simply stays in grace until reconciliation enqueues again.
        $this->db->execute('UPDATE subscriptions SET renew_failed_at=?,renew_at=? WHERE id=? AND auto_renew=1 AND status=\'active\'',[time(),time()+3600,$subscriptionId]);
    }
    private function renew(string $id): void
    {
        if ($this->billing) {
            $plan=$this->db->one('SELECT p.duration_days FROM subscriptions s JOIN plans p ON p.id=COALESCE(s.renew_plan_id,s.plan_id) WHERE s.id=?',[$id]);
            if ($plan && (int)$plan['duration_days']>0 && (int)$plan['duration_days']<=1) {
                $this->billing->dailyChargeFromBalance($id);
            } else {
                $this->billing->autoRenewFromBalance($id);
            }

            return;
        }
        $s=$this->db->one('SELECT * FROM subscriptions WHERE id=?',[$id]);
        if (!$s || (int)$s['auto_renew']!==1 || $s['status']!=='active' || (int)$s['expires_at']<=time()) return;
        if ($s['renew_order_id']) return;
        // Check global autorenew toggle
        $enabled=$this->db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_ENABLED'");
        if ($enabled && $enabled['value']==='0') return;
        $planId=$s['renew_plan_id']??$this->db->one('SELECT plan_id FROM orders WHERE id=?',[$s['order_id']])['plan_id']??null;
        if (!$planId) return;
        $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
        if (!$plan) return;
        // Check max fails (per-plan override wins over global)
        $globalMaxFails=(int)($this->db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_MAX_FAILS'")['value']??3);
        $maxFails=(isset($plan['autorenew_max_fails']) && (int)$plan['autorenew_max_fails']>0)?(int)$plan['autorenew_max_fails']:$globalMaxFails;
        if ((int)$s['renew_fail_count']>=$maxFails) return;
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$s['user_id']]);
        if (!$user || (int)$user['disabled']===1) return;
        $email=$user['email']?:($user['telegram_id']?$user['telegram_id'].'@telegram.org':null);
        if (!$email || !filter_var($email,FILTER_VALIDATE_EMAIL)) return;
        $orderId=\App\Infrastructure\Database::id();
        $key='renew:'.$id.':'.(int)$s['expires_at'].':'.(int)$s['renew_fail_count'];
        // Idempotency: if renewal order already exists for this expiry, skip
        if ($this->db->one('SELECT id FROM orders WHERE idempotency_key=?',[$key])) return;
        $provider=$this->db->one('SELECT provider FROM orders WHERE id=?',[$s['order_id']])['provider']??'demo';
        $provisionDriver=$this->db->one('SELECT provision_driver FROM orders WHERE id=?',[$s['order_id']])['provision_driver']??'demo';
        $squadUuid=$this->db->one('SELECT squad_uuid FROM orders WHERE id=?',[$s['order_id']])['squad_uuid']??'';
        $providerAccount=$this->db->one('SELECT provider_account FROM orders WHERE id=?',[$s['order_id']])['provider_account']??'';
        // Respect global purchases pause
        $purchases=$this->db->one("SELECT value FROM app_settings WHERE name='PURCHASES_ENABLED'");
        if ($purchases && $purchases['value']!=='1') return;
        $this->db->transaction(function() use ($s,$plan,$user,$email,$orderId,$key,$provider,$provisionDriver,$squadUuid,$providerAccount,$id) {
            $fresh=$this->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->db->lock(),[$id]);
            if (!$fresh || (int)$fresh['auto_renew']!==1 || $fresh['renew_order_id']!==null || (int)$fresh['expires_at']!==(int)$s['expires_at']) return;
            $this->db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'pending',?,?)",[$orderId,$s['user_id'],$plan['id'],$key,$plan['price_minor'],$plan['currency'],$plan['name'],$plan['duration_days'],$plan['traffic_bytes'],$plan['devices'],$provider,time()]);
            $this->db->execute('UPDATE orders SET provision_driver=?,squad_uuid=?,provider_account=?,receipt_email=?,client_ip=? WHERE id=?',[$provisionDriver,$squadUuid,$providerAccount,$email,'8.8.8.8',$orderId]);
            $this->db->execute('UPDATE orders SET return_url=? WHERE id=?',[preg_replace('~/orders/[^/]+$~','/orders/'.$orderId,(string)($this->db->one('SELECT return_url FROM orders WHERE id=?',[$s['order_id']])['return_url']??'')),$orderId]);
            $original=$this->db->one('SELECT receipt_enabled,vat_code,tax_system FROM orders WHERE id=?',[$s['order_id']]);
            if ($original) $this->db->execute('UPDATE orders SET receipt_enabled=?,vat_code=?,tax_system=? WHERE id=?',[$original['receipt_enabled'],$original['vat_code'],$original['tax_system'],$orderId]);
            $this->db->execute('UPDATE orders SET duration_months=?,renewal_subscription_id=? WHERE id=?',[(int)$plan['duration_months'],$id,$orderId]);
            $this->db->execute('UPDATE subscriptions SET renew_order_id=?,renew_at=NULL WHERE id=?',[$orderId,$id]);
            $this->outbox->enqueue('payment.create','checkout:'.$orderId,['order_id'=>$orderId]);
        });
    }
    private function send(array $payload): void
    {
        if (!$this->botToken) throw new \RuntimeException('Telegram is not configured');
        try {
            $resp = $this->http->request('POST', rtrim($this->telegramApiBase, '/').'/bot'.$this->botToken.'/sendMessage', ['json' => $payload, 'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 0]);
            // Inspect the body without throwing on HTTP status: a blocked chat
            // (403/400) is a permanent denial and must not be retried. Only
            // truly transient failures (429/5xx/network/timeout) should retry.
            $status = $resp->getStatusCode();
            $result = $resp->toArray(false);
        } catch (TransportExceptionInterface $e) {
            // Network-level failure (timeout, connection reset, DNS): transient.
            throw new \RuntimeException('Telegram network failure', 0, $e);
        }
        if (!($result['ok'] ?? false)) {
            $code = (int)($result['error_code'] ?? $status);
            if (in_array($code, [400, 403, 404], true)) {
                // Bot blocked / chat not found / user deactivated / can't
                // initiate conversation: permanent, terminal. Skip retries.
                throw new JobPermanentFailure('Telegram permanently refused ('.$code.'): '.($result['description'] ?? ''));
            }
            // 429 rate-limit, 5xx, or unexpected: transient, keep backoff.
            throw new \RuntimeException('Telegram rejected message ('.$code.')');
        }
        $user = $this->db->one('SELECT id FROM users WHERE telegram_id=?', [(string)($payload['chat_id'] ?? '')]);
        if ($user) $this->timeline?->record($user['id'], 'telegram.notification_delivered', []);
    }
    private function answer(array $payload): void
    {
        if (!$this->botToken) throw new \RuntimeException('Telegram is not configured');
        try {
            $resp=$this->http->request('POST',rtrim($this->telegramApiBase,'/').'/bot'.$this->botToken.'/answerCallbackQuery',['json'=>$payload,'timeout'=>10,'max_duration'=>20,'max_redirects'=>0]);
            // Inspect the body without throwing on HTTP status: a callback
            // query that is too old, already answered, or from a chat that
            // blocked the bot is a permanent denial that retrying can never
            // fix. Only truly transient failures (429/5xx/network) should
            // retry. Keep this symmetric with send() so these resolve to done
            // instead of piling up retries and dead-letters.
            $status=$resp->getStatusCode();
            $result=$resp->toArray(false);
        } catch (TransportExceptionInterface $e) {
            // Network-level failure (timeout, connection reset, DNS): transient.
            throw new \RuntimeException('Telegram network failure', 0, $e);
        }
        if (!($result['ok'] ?? false)) {
            $code=(int)($result['error_code'] ?? $status);
            if (in_array($code, [400, 403, 404, 409, 410], true)) {
                // Query invalid/expired/already answered, bot blocked: permanent.
                throw new JobPermanentFailure('Telegram permanently refused answer ('.$code.'): '.($result['description'] ?? ''));
            }
            // 429 rate-limit, 5xx, or unexpected: transient, keep backoff.
            throw new \RuntimeException('Telegram rejected callback answer ('.$code.')');
        }
    }
}
