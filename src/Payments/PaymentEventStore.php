<?php
declare(strict_types=1);
namespace App\Payments;
use App\Infrastructure\Database;
use App\Observability\OperationsService;

/** Durable inbox: a webhook is acknowledged only after this insert succeeds. */
final class PaymentEventStore
{
    public function __construct(private Database $db) {}
    public function receive(string $provider,string $eventId,?string $paymentId,array $payload,bool $signatureValid,array $headers=[]): string
    {
        return $this->db->transaction(function() use($provider,$eventId,$paymentId,$payload,$signatureValid,$headers) {
            $id=Database::id(); $rawId=Database::id(); $now=time();
            $json=json_encode($payload,JSON_THROW_ON_ERROR);
            $correlation='cor_'.substr(hash('sha256',$provider.':'.($paymentId??$eventId)),0,40);
            $order=$paymentId===null?null:$this->db->one('SELECT id,user_id FROM orders WHERE provider=? AND provider_payment_id=?',[$provider,$paymentId]);
            $operation=(new OperationsService($this->db))->start('payment.webhook',['user_id'=>$order['user_id']??null,'order_id'=>$order['id']??null,'metadata'=>['provider'=>$provider,'provider_payment_id'=>$paymentId]],$correlation);
            // Keep a raw durable record as well as the normalized payment event.
            // The endpoint may ACK only after this transaction commits.
            $this->db->execute('INSERT INTO incoming_webhooks(id,provider,provider_event_id,headers,payload,received_at,status,correlation_id) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(provider,provider_event_id) DO NOTHING',[
                $rawId,$provider,$eventId,json_encode($this->safeHeaders($headers),JSON_THROW_ON_ERROR),$json,$now,$signatureValid?'pending':'dead',$correlation,
            ]);
            $this->db->execute('INSERT INTO payment_events(id,provider,provider_event_id,payment_id,payload,signature_valid,received_at,status,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(provider,provider_event_id) DO NOTHING',[
                // Invalid signatures are terminal evidence, not work waiting to
                // be retried. Keeping the normalized and raw inboxes aligned
                // prevents misleading "pending" webhook alarms.
                $id,$provider,$eventId,$paymentId,$json,$signatureValid?1:0,$now,$signatureValid?'pending':'dead',$signatureValid?$now:null
            ]);
            if(!($operation['existing']??false))(new OperationsService($this->db))->event($operation['id'],'payment.received',$signatureValid?'success':'failed',$signatureValid?'Payment webhook received':'Webhook signature rejected',['metadata'=>['provider'=>$provider]]);
            return $this->db->one('SELECT id FROM payment_events WHERE provider=? AND provider_event_id=?',[$provider,$eventId])['id'];
        });
    }
    /** Atomically lease one event.  Returns null when it is already complete or leased. */
    public function claim(string $id): ?array
    {
        return $this->db->transaction(function() use($id) {
            $event=$this->db->one('SELECT * FROM payment_events WHERE id=?'.$this->db->lock(),[$id]);
            if (!$event || (int)$event['signature_valid']!==1 || $event['status']==='processed' || $event['status']==='dead') return null;
            $now=time();
            if ($event['status']==='processing' && (int)($event['locked_until']??0)>$now) return null;
            if (in_array($event['status'],['pending','retry'],true) && (int)($event['next_attempt_at']??0)>$now) return null;
            $token=Database::id();
            $changed=$this->db->execute("UPDATE payment_events SET status='processing',attempts=attempts+1,locked_until=?,lock_token=? WHERE id=? AND status IN ('pending','retry','processing')",[$now+120,$token,$id]);
            if (!$changed) return null;
            $event['attempts']=(int)$event['attempts']+1; $event['lock_token']=$token; $event['status']='processing';
            return $event;
        });
    }
    public function processed(string $id,string $token): void {
        $this->db->transaction(function() use($id,$token) {
            $event=$this->db->one('SELECT provider,provider_event_id FROM payment_events WHERE id=?'.$this->db->lock(),[$id]);
            if (!$event) return;
            $now=time();
            $changed=$this->db->execute("UPDATE payment_events SET status='processed',processed_at=?,processing_error=NULL,next_attempt_at=NULL,locked_until=NULL,lock_token=NULL WHERE id=? AND status='processing' AND lock_token=?",[$now,$id,$token]);
            // Do not let a stale worker rewrite raw delivery evidence after a
            // newer lease has taken ownership of this event.
            if ($changed) $this->db->execute("UPDATE incoming_webhooks SET status='processed',processed_at=?,last_error=NULL WHERE provider=? AND provider_event_id=?",[$now,$event['provider'],$event['provider_event_id']]);
        });
    }
    public function failed(string $id,string $token,\Throwable $e): void
    {
        $this->db->transaction(function() use($id,$token,$e) {
            $event=$this->db->one('SELECT provider,provider_event_id,attempts FROM payment_events WHERE id=? AND status=\'processing\' AND lock_token=?'.$this->db->lock(),[$id,$token]);
            if (!$event) return;
            // A permanent failure (JobPermanentFailure) is a deterministic,
            // non-recoverable business error — dead-letter it immediately
            // instead of burning retries on something that can never succeed.
            $permanent=$e instanceof \App\Infrastructure\JobPermanentFailure;
            $attempts=(int)$event['attempts']; $dead=$permanent || $attempts>=8;
            $changed=$this->db->execute("UPDATE payment_events SET status=?,processing_error=?,next_attempt_at=?,locked_until=NULL,lock_token=NULL WHERE id=? AND status='processing' AND lock_token=?",[$dead?'dead':'retry',get_class($e),$dead?null:time()+min(3600,2**$attempts)+random_int(0,5),$id,$token]);
            if ($changed) $this->db->execute('UPDATE incoming_webhooks SET status=?,attempts=?,last_error=? WHERE provider=? AND provider_event_id=?',[$dead?'dead':'retry',$attempts,get_class($e),$event['provider'],$event['provider_event_id']]);
        });
    }

    /** Persist evidence useful for verification, never credentials or cookies. */
    private function safeHeaders(array $headers): array
    {
        $allowed=['content-type'=>true,'x-request-id'=>true,'x-signature'=>true,'x-signature-sha256'=>true,'x-webhook-id'=>true];
        $result=[];
        foreach($headers as $name=>$value) {
            $key=strtolower((string)$name);
            if(!isset($allowed[$key])) continue;
            $result[$key]=is_array($value)?array_map('strval',$value):[(string)$value];
        }
        return $result;
    }
}
