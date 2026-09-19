<?php
declare(strict_types=1);
namespace App\Payments;
use App\Infrastructure\Database;
use App\Observability\OperationsService;

/** Durable inbox: a webhook is acknowledged only after this insert succeeds. */
final class PaymentEventStore
{
    public function __construct(private Database $db) {}
    public function receive(string $provider,string $eventId,?string $paymentId,array $payload,bool $signatureValid): string
    {
        $id=Database::id(); $now=time();
        $order=$paymentId===null?null:$this->db->one('SELECT id,user_id FROM orders WHERE provider=? AND provider_payment_id=?',[$provider,$paymentId]);
        $operation=(new OperationsService($this->db))->start('payment.webhook',['user_id'=>$order['user_id']??null,'order_id'=>$order['id']??null,'metadata'=>['provider'=>$provider,'provider_payment_id'=>$paymentId]],'cor_'.substr(hash('sha256',$provider.':'.($paymentId??$eventId)),0,40));
        $this->db->execute('INSERT INTO payment_events(id,provider,provider_event_id,payment_id,payload,signature_valid,received_at,status,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(provider,provider_event_id) DO NOTHING',[
            $id,$provider,$eventId,$paymentId,json_encode($payload,JSON_THROW_ON_ERROR),$signatureValid?1:0,$now,'pending',$now
        ]);
        if(!($operation['existing']??false))(new OperationsService($this->db))->event($operation['id'],'payment.received',$signatureValid?'success':'failed',$signatureValid?'Payment webhook received':'Webhook signature rejected',['metadata'=>['provider'=>$provider]]);
        return $this->db->one('SELECT id FROM payment_events WHERE provider=? AND provider_event_id=?',[$provider,$eventId])['id'];
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
    public function processed(string $id,string $token): void { $this->db->execute("UPDATE payment_events SET status='processed',processed_at=?,processing_error=NULL,next_attempt_at=NULL,locked_until=NULL,lock_token=NULL WHERE id=? AND status='processing' AND lock_token=?",[time(),$id,$token]); }
    public function failed(string $id,string $token,\Throwable $e): void
    {
        $event=$this->db->one('SELECT attempts FROM payment_events WHERE id=? AND status=\'processing\' AND lock_token=?',[$id,$token]);
        if (!$event) return;
        $attempts=(int)$event['attempts']; $dead=$attempts>=8;
        $this->db->execute("UPDATE payment_events SET status=?,processing_error=?,next_attempt_at=?,locked_until=NULL,lock_token=NULL WHERE id=? AND status='processing' AND lock_token=?",[$dead?'dead':'retry',get_class($e),$dead?null:time()+min(3600,2**$attempts)+random_int(0,5),$id,$token]);
    }
}
