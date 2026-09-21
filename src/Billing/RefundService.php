<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;

/** Refunds a successful payment back to the customer's internal balance. */
final class RefundService
{
    public function __construct(private Database $db, private ?Wallet $wallet = null) {}

    /**
     * Execute a refund atomically: insert the auditable refund_requests row and
     * credit the customer's internal balance. Idempotent (same payment + reason
     * + amount returns the already-created row); serialized per payment so two
     * concurrent refunds can never together exceed the payment amount.
     */
    public function request(string $paymentId,int $amount,string $reason,string $actor='system'): array
    {
        if ($amount<=0) throw new BillingError('Сумма возврата должна быть положительной.');
        return $this->db->transaction(function() use($paymentId,$amount,$reason,$actor) {
            // Serialize refund aggregation for one payment so two concurrent
            // refund requests can never both pass the < remaining check and
            // together exceed the payment amount.
            if ($this->db->postgres()) $this->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['refund:'.$paymentId]);
            $payment=$this->db->one("SELECT * FROM payments WHERE id=? AND status='succeeded'".$this->db->lock(),[$paymentId]);
            if(!$payment) throw new BillingError('Успешный платёж не найден.');
            $key='refund:'.$paymentId.':'.hash('sha256',$reason.':'.$amount);
            $existing=$this->db->one('SELECT * FROM refund_requests WHERE idempotency_key=?',[$key]);
            if ($existing!==null) return $existing; // idempotent replay
            $refunded=(int)($this->db->one("SELECT COALESCE(SUM(amount_minor),0) total FROM refund_requests WHERE payment_id=? AND status IN ('pending','processing','unknown','succeeded')",[$paymentId])['total']??0);
            if ($amount>(int)$payment['amount_minor']-$refunded) throw new BillingError('Сумма возвратов превышает платёж.');
            $now=time();$id=Database::id();$correlation='refund:'.substr(hash('sha256',$paymentId.':'.$id),0,40);
            $this->db->execute(
                "INSERT INTO refund_requests(id,payment_id,order_id,user_id,amount_minor,currency,reason,status,idempotency_key,provider_refund_id,correlation_id,created_at,updated_at,completed_at) VALUES(?,?,?,?,?,?,?,'succeeded',?,?,?,?,?,?)",
                [$id,$paymentId,$payment['order_id'],$payment['user_id'],$amount,$payment['currency'],$reason,$key,$id,$correlation,$now,$now,$now]
            );
            // Credit the customer's internal balance. Wallet::TYPES allows the
            // 'refund' type, so this writes the authoritative wallet transaction
            // (and the wallet history). Provider adapters are not involved: this
            // is an internal-balance return, not a card/cassa chargeback.
            if ($this->wallet) {
                $this->wallet->credit($payment['user_id'],$amount,'refund','Возврат по платежу '.$paymentId.': '.$reason,'internal_balance',$id,true);
            }
            $this->db->execute('INSERT INTO audit_events(id,entity_type,entity_id,event_type,actor_type,actor_id,reason,correlation_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[Database::id(),'refund',$id,'refund.completed','user',$actor,$reason,$correlation,$now]);
            return $this->db->one('SELECT * FROM refund_requests WHERE id=?',[$id]);
        });
    }

    /** List recent refund requests (operator visibility). */
    public function history(int $limit=50): array
    {
        return $this->db->all('SELECT * FROM refund_requests ORDER BY created_at DESC LIMIT ?',[$limit]);
    }
}
