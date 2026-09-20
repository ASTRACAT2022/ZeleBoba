<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;

/** Creates auditable refund intent; provider adapters may safely consume it later. */
final class RefundService
{
    public function __construct(private Database $db) {}
    public function request(string $paymentId,int $amount,string $reason,string $actor='system'): array
    {
        if($amount<=0) throw new BillingError('Сумма возврата должна быть положительной.');
        return $this->db->transaction(function()use($paymentId,$amount,$reason,$actor) {
            $payment=$this->db->one("SELECT * FROM payments WHERE id=? AND status='succeeded'".$this->db->lock(),[$paymentId]);
            if(!$payment) throw new BillingError('Успешный платёж не найден.');
            $refunded=(int)($this->db->one("SELECT COALESCE(SUM(amount_minor),0) total FROM refund_requests WHERE payment_id=? AND status IN ('pending','processing','unknown','succeeded')",[$paymentId])['total']??0);
            if($refunded+$amount>(int)$payment['amount_minor']) throw new BillingError('Сумма возвратов превышает платёж.');
            $key='refund:'.$paymentId.':'.hash('sha256',$reason.':'.$amount);
            $existing=$this->db->one('SELECT * FROM refund_requests WHERE idempotency_key=?',[$key]); if($existing)return $existing;
            $now=time();$id=Database::id();$correlation='refund:'.$paymentId.':'.$id;
            $this->db->execute("INSERT INTO refund_requests(id,payment_id,order_id,user_id,amount_minor,currency,reason,status,idempotency_key,correlation_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'pending',?,?,?,?)",[$id,$paymentId,$payment['order_id'],$payment['user_id'],$amount,$payment['currency'],$reason,$key,$correlation,$now,$now]);
            $this->db->execute('INSERT INTO audit_events(id,entity_type,entity_id,event_type,actor_type,actor_id,reason,correlation_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[Database::id(),'refund',$id,'refund.requested','user',$actor,$reason,$correlation,$now]);
            return $this->db->one('SELECT * FROM refund_requests WHERE id=?',[$id]);
        });
    }
}
