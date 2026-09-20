<?php
declare(strict_types=1);
namespace App\Payments;
use App\Infrastructure\Database;

/** Database record of a checkout intent before any provider HTTP call. */
final class PaymentAttemptStore
{
    public function __construct(private Database $db) {}
    public function begin(string $type,array $entity): array
    {
        $key='checkout:'.$type.':'.$entity['id']; $now=time();
        return $this->db->transaction(function() use($type,$entity,$key,$now) {
            $existing=$this->db->one('SELECT * FROM payment_attempts WHERE idempotency_key=?'.$this->db->lock(),[$key]);
            if($existing) { $existing['created']=false; return $existing; }
            $id=Database::id(); $correlation='checkout:'.$type.':'.$entity['id'];
            $amount=(int)($type==='order'?$entity['price_minor']:$entity['amount_kopeks']);
            $this->db->execute("INSERT INTO payment_attempts(id,entity_type,entity_id,user_id,provider,amount_minor,currency,status,idempotency_key,created_at,updated_at,correlation_id) VALUES(?,?,?,?,?,?,?,'creating',?,?,?,?)",[
                $id,$type,$entity['id'],$entity['user_id'],$entity['provider'],$amount,$entity['currency'],$key,$now,$now,$correlation,
            ]);
            $created=$this->db->one('SELECT * FROM payment_attempts WHERE id=?',[$id]);
            $created['created']=true;
            return $created;
        });
    }
    public function attached(string $id,string $paymentId,string $checkoutUrl): void
    {
        $this->db->execute("UPDATE payment_attempts SET provider_payment_id=?,checkout_url=?,status='pending',updated_at=?,last_error=NULL WHERE id=? AND status IN ('creating','unknown','pending')",[$paymentId,$checkoutUrl,time(),$id]);
    }
    public function unknown(string $id,\Throwable $e): void
    {
        $this->db->execute("UPDATE payment_attempts SET status='unknown',last_error=?,updated_at=? WHERE id=? AND status='creating'",[get_class($e),time(),$id]);
    }
    public function completed(string $provider,string $paymentId,string $status): void
    {
        $terminal=$status==='paid'?'succeeded':($status==='canceled'?'cancelled':$status);
        $this->db->execute("UPDATE payment_attempts SET status=?,completed_at=?,updated_at=? WHERE provider=? AND provider_payment_id=? AND status NOT IN ('succeeded','cancelled','failed','expired')",[$terminal,time(),time(),$provider,$paymentId]);
    }

    /**
     * A mutating checkout call may time out after the provider accepted it.
     * Retrying it would risk a second charge, but retaining `unknown` forever
     * hides a customer-impacting problem. Escalate it to a visible case.
     */
    public function escalateUnknown(int $olderThan=900,int $limit=100): int
    {
        $cutoff=time()-max(1,$olderThan);
        $rows=$this->db->all("SELECT * FROM payment_attempts WHERE status='unknown' AND updated_at<=? ORDER BY updated_at LIMIT ?",[$cutoff,max(1,$limit)]);
        $escalated=0;
        foreach($rows as $row) {
            $escalated += $this->db->transaction(function() use($row) {
                $current=$this->db->one('SELECT * FROM payment_attempts WHERE id=?'.$this->db->lock(),[$row['id']]);
                if (!$current || $current['status']!=='unknown') return 0;
                $now=time();
                $changed=$this->db->execute("UPDATE payment_attempts SET status='failed',last_error='checkout_outcome_unknown',updated_at=? WHERE id=? AND status='unknown'",[$now,$current['id']]);
                if (!$changed) return 0;
                $code='CHECKOUT-'.substr($current['id'],0,20);
                $this->db->execute("INSERT INTO operational_cases(id,code,severity,status,title,user_id,details,created_at) VALUES(?,?,?,'open',?,?,?,?) ON CONFLICT(code) DO NOTHING",[
                    Database::id(),$code,'high','Checkout outcome needs reconciliation',$current['user_id'],'Provider checkout result was unknown for '.$current['entity_type'].' '.$current['entity_id'].'.',$now,
                ]);
                $this->db->execute('INSERT INTO audit_events(id,entity_type,entity_id,event_type,old_state,new_state,actor_type,reason,metadata,correlation_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[
                    Database::id(),$current['entity_type'],$current['entity_id'],'checkout.escalated','unknown','failed','system','checkout_outcome_unknown','{}',$current['correlation_id'],$now,
                ]);
                return 1;
            });
        }
        return $escalated;
    }
}
