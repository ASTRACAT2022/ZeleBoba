<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Observability\OperationsService;
final class Outbox
{
    public function __construct(private Database $db, private ?\App\Settings\Vault $vault=null) {}
    /** Higher priority = drained first. Personal Telegram replies/answers outrank mass broadcasts. */
    private const PRIORITY = [
        'payment.verify' => 100,
        'payment.event.process' => 100,
        'payment.create' => 90,
        'topup.create' => 90,
        'topup.after' => 90,
        'referral.topup' => 90,
        'subscription.provision' => 80,
        'subscription.extend' => 80,
        'subscription.renew' => 80,
        'subscription.traffic' => 80,
        'subscription.devices' => 80,
        'gift.create' => 70,
        'telegram.send' => 60,
        'telegram.answer' => 60,
        'compensation.run' => 50,
        'compensation.grant' => 50,
        'broadcast.send' => 10,
        'broadcast.run' => 5,
    ];

    public function priority(string $topic): int
    {
        return self::PRIORITY[$topic] ?? 30;
    }

    public function enqueue(string $topic, string $key, array $payload, int $delay = 0): void
    {
        $json=json_encode($payload, JSON_THROW_ON_ERROR);
        if($this->vault)$json='enc:'.$this->vault->seal('outbox:'.$topic,$json);
        $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING', [Database::id(),$topic,$key,$json,$this->priority($topic),time()+$delay,time()]);
    }
    public function runOne(callable $handler): bool
    {
        $job = $this->db->transaction(function () {
            $lock = $this->db->postgres() ? ' FOR UPDATE SKIP LOCKED' : '';
            $job = $this->db->one("SELECT * FROM outbox WHERE (status='pending' AND available_at<=?) OR (status='processing' AND locked_until<=?) ORDER BY priority DESC, created_at,id LIMIT 1".$lock, [time(),time()]);
            if (!$job) return null;
            $job['lock_token'] = Database::id();
            $this->db->execute("UPDATE outbox SET status='processing', attempts=attempts+1, locked_until=?, lock_token=? WHERE id=?", [time()+120,$job['lock_token'],$job['id']]);
            return $job;
        });
        if (!$job) return false;
        $payload=[];
        $operations=new OperationsService($this->db);
        $operation=$job['correlation_id'] ? $this->db->one('SELECT id FROM operations WHERE correlation_id=?',[$job['correlation_id']]) : null;
        if($operation)$operations->event($operation['id'],'outbox.started','processing','Outbox job started',['metadata'=>['topic'=>$job['topic'],'attempt'=>(int)$job['attempts']+1]]);
        try {
            $json=$job['payload'];
            if(str_starts_with($json,'enc:'))$json=$this->vault?->open('outbox:'.$job['topic'],substr($json,4))??throw new \RuntimeException('Outbox decryption unavailable');
            $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            $handler($job['topic'], $payload);
            $this->db->execute("UPDATE outbox SET status='done',locked_until=NULL,last_error=NULL,payload='{}' WHERE id=? AND lock_token=?", [$job['id'],$job['lock_token']]);
            if($operation)$operations->event($operation['id'],'outbox.completed','success','Outbox job completed',['metadata'=>['topic'=>$job['topic']]]);
        } catch (JobDeferred $e) {
            // Maintenance and global safety mode are expected operational
            // states, not failures. Do not exhaust attempts or dead-letter a
            // valid money/provisioning command while an integration is paused.
            $this->db->execute("UPDATE outbox SET status='pending',attempts=CASE WHEN attempts>0 THEN attempts-1 ELSE 0 END,available_at=?,locked_until=NULL,lock_token=NULL,last_error=NULL WHERE id=? AND lock_token=?",[
                time()+max(1,$e->delaySeconds),$job['id'],$job['lock_token']
            ]);
            if($operation)$operations->event($operation['id'],'outbox.deferred','processing','Outbox job deferred by operational control',['metadata'=>['topic'=>$job['topic']]]);
        } catch (JobPermanentFailure $e) {
            // Terminal, non-recoverable failure (e.g. Telegram permanently
            // refuses a chat). Dead-letter now without spending retry budget so
            // a mass broadcast of undeliverable recipients never clogs the
            // queue or stalls the worker. This is an expected outcome (blocked
            // chat), not an anomaly worth a job.failed alert.
            $this->db->execute("UPDATE outbox SET status='dead',available_at=?,locked_until=NULL,last_error=? WHERE id=? AND lock_token=?", [time(), 'permanent:'.get_class($e), $job['id'], $job['lock_token']]);
            if ($operation) $operations->event($operation['id'], 'outbox.dead', 'warning', 'Outbox job permanently failed', ['metadata' => ['topic' => $job['topic'], 'error_class' => get_class($e)]]);
        } catch (\Throwable $e) {
            $attempt = (int)$job['attempts']+1;
            // Never persist raw HTTP errors: they may contain tokens or subscription URLs.
            $this->db->execute('UPDATE outbox SET status=?, available_at=?, locked_until=NULL,last_error=? WHERE id=? AND lock_token=?', [$attempt>=8?'dead':'pending',time()+min(3600,2**$attempt)+random_int(0,5),get_class($e),$job['id'],$job['lock_token']]);
            if (in_array($job['topic'],['subscription.provision','subscription.extend'],true) && isset($payload['subscription_id'])) {
                $state=$attempt>=8?'failed':'retry';
                $this->db->execute('UPDATE provisioning_accounts SET state=?,last_error=?,updated_at=? WHERE subscription_id=? AND state<>\'active\'',[
                    $state,get_class($e),time(),(string)$payload['subscription_id']
                ]);
            }
            if($operation)$operations->event($operation['id'],'outbox.retry','warning','Outbox retry scheduled',['metadata'=>['topic'=>$job['topic'],'attempt'=>$attempt,'error_class'=>get_class($e)]]);
            error_log(json_encode(['event'=>'job.failed','job_id'=>$job['id'],'type'=>get_class($e),'attempt'=>$attempt]));
        }
        return true;
    }
}
