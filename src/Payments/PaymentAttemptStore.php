<?php
declare(strict_types=1);
namespace App\Payments;
use App\Infrastructure\Database;

/** Database record of a checkout intent before any provider HTTP call. */
final class PaymentAttemptStore
{
    public function __construct(private Database $db,private ?\App\Billing\AnalyticsService $analytics=null) {}
    public function begin(string $type,array $entity): array
    {
        $key='checkout:'.$type.':'.$entity['id']; $now=time();
        $attempt=$this->db->transaction(function() use($type,$entity,$key,$now) {
            $existing=$this->db->one('SELECT * FROM payment_attempts WHERE idempotency_key=?'.$this->db->lock(),[$key]);
            // A terminal failed attempt (provider timed out, outcome unknown) can
            // never resolve itself and must not block a customer from paying. Its
            // idempotency key is retired and a fresh attempt with a new key is
            // created so the checkout can be reissued safely. In-flight states
            // (creating/unknown/pending) still return the original intent.
            if($existing && $existing['status']==='failed') {
                $this->db->execute("UPDATE payment_attempts SET idempotency_key=? WHERE id=? AND status='failed'",[$key.':retired:'.substr(Database::id(),0,8),$existing['id']]);
                $existing=null;
            }
            if($existing) { $existing['created']=false; return $existing; }
            $id=Database::id(); $correlation='checkout:'.$type.':'.$entity['id'];
            $amount=(int)($type==='order'?$entity['price_minor']:$entity['amount_kopeks']);
            $this->db->execute("INSERT INTO payment_attempts(id,entity_type,entity_id,user_id,provider,amount_minor,currency,status,idempotency_key,created_at,updated_at,correlation_id,plan_id) VALUES(?,?,?,?,?,?,?,'creating',?,?,?,?,?)",[
                $id,$type,$entity['id'],$entity['user_id'],$entity['provider'],$amount,$entity['currency'],$key,$now,$now,$correlation,$type==='order'?($entity['plan_id']??null):null,
            ]);
            $created=$this->db->one('SELECT * FROM payment_attempts WHERE id=?',[$id]);
            $created['created']=true;
            return $created;
        });
        if(($attempt['created']??false) && $this->analytics)$this->analytics->trackAttempt($attempt,'payment_created',['entity_type'=>$type,'order_id'=>$type==='order'?$entity['id']:null]);
        return $attempt;
    }
    public function attached(string $id,string $paymentId,string $checkoutUrl): void
    {
        $this->db->execute("UPDATE payment_attempts SET provider_payment_id=?,checkout_url=?,status='pending',updated_at=?,last_error=NULL WHERE id=? AND status IN ('creating','unknown','pending')",[$paymentId,$checkoutUrl,time(),$id]);
    }
    public function unknown(string $id,\Throwable $e): void
    {
        $this->db->execute("UPDATE payment_attempts SET status='unknown',last_error=?,updated_at=? WHERE id=? AND status='creating'",[get_class($e),time(),$id]);
    }
    public function completed(string $provider,string $paymentId,string $status,array $providerResult=[]): void
    {
        $raw=strtoupper((string)($providerResult['provider_status']??$providerResult['raw_status']??''));
        $terminal=$status==='paid'?'succeeded':($raw==='EXPIRED'?'expired':(in_array($raw,['FAILED','DECLINED'],true)?'failed':($status==='canceled'?'cancelled':$status)));
        if(!in_array($terminal,['succeeded','cancelled','failed','expired'],true))return;
        $now=time();$message=trim((string)($providerResult['provider_error_message']??$providerResult['error_message']??''));
        // Provider text is untrusted and may echo user input or request data.
        // Keep a short, plain diagnostic while dropping URLs, addresses and
        // long numeric sequences that could contain credentials or payment data.
        if($message!==''){
            $message=preg_replace('~https?://\S+~iu','[ссылка удалена]',$message)??'';
            $message=preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u','[адрес удалён]',$message)??'';
            $message=preg_replace('/\b\d{7,}\b/','[номер удалён]',$message)??'';
            if(preg_match('/\b(?:token|secret|password|authorization|bearer|card|cvv|api[_ -]?key)\b/i',$message))$message='Чувствительные данные исключены из сообщения провайдера.';
            $message=mb_substr($message,0,500);
        }else $message=null;
        $this->db->execute("UPDATE payment_attempts SET status=?,completed_at=?,updated_at=?,provider_status=?,provider_error_code=?,provider_error_message=COALESCE(?,provider_error_message),payment_method=COALESCE(?,payment_method),succeeded_at=CASE WHEN ?='succeeded' THEN COALESCE(succeeded_at,?) ELSE succeeded_at END,cancelled_at=CASE WHEN ?='cancelled' THEN COALESCE(cancelled_at,?) ELSE cancelled_at END,expired_at=CASE WHEN ?='expired' THEN COALESCE(expired_at,?) ELSE expired_at END WHERE provider=? AND provider_payment_id=? AND status NOT IN ('succeeded','cancelled','failed','expired')",[$terminal,$now,$now,$raw?:null,$providerResult['provider_error_code']??null,$message,$providerResult['payment_method']??null,$terminal,$now,$terminal,$now,$terminal,$now,$provider,$paymentId]);
        $attempt=$this->db->one('SELECT * FROM payment_attempts WHERE provider=? AND provider_payment_id=?',[$provider,$paymentId]);
        if($attempt&&$terminal!=='succeeded'){$event=match($terminal){'cancelled'=>'payment_cancelled','expired'=>'payment_expired',default=>'payment_failed'};$this->analytics?->trackAttempt($attempt,$event,['provider_status'=>$raw?:null,'provider_error_code'=>$providerResult['provider_error_code']??null,'provider_error_message'=>$message]);}
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
