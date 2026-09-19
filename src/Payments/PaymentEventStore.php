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
        $this->db->execute('INSERT INTO payment_events(id,provider,provider_event_id,payment_id,payload,signature_valid,received_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(provider,provider_event_id) DO NOTHING',[
            $id,$provider,$eventId,$paymentId,json_encode($payload,JSON_THROW_ON_ERROR),$signatureValid?1:0,$now
        ]);
        if(!($operation['existing']??false))(new OperationsService($this->db))->event($operation['id'],'payment.received',$signatureValid?'success':'failed',$signatureValid?'Payment webhook received':'Webhook signature rejected',['metadata'=>['provider'=>$provider]]);
        return $this->db->one('SELECT id FROM payment_events WHERE provider=? AND provider_event_id=?',[$provider,$eventId])['id'];
    }
    public function processed(string $id): void { $this->db->execute('UPDATE payment_events SET processed_at=?,processing_error=NULL WHERE id=?',[time(),$id]); }
    public function failed(string $id,\Throwable $e): void { $this->db->execute('UPDATE payment_events SET processing_error=? WHERE id=?',[get_class($e),$id]); }
}
