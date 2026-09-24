<?php
declare(strict_types=1);
namespace App\Infrastructure;

/**
 * The database-owned fulfillment state machine.  It deliberately performs no
 * I/O: a worker leases an operation here, makes the remote call outside the
 * transaction, then stores its observed result here.
 */
final class DurableWorkflow
{
    public function __construct(private Database $db, private Outbox $outbox) {}

    /** Must be called from the payment settlement transaction. */
    public function startFulfillment(string $orderId, string $subscriptionId, array $desired, string $correlationId): void
    {
        $now=time();
        $workflowId=Database::id();
        $operationId=Database::id();
        $json=json_encode($desired,JSON_THROW_ON_ERROR);
        $this->db->execute("INSERT INTO workflows(id,workflow_type,entity_type,entity_id,state,desired_state,next_attempt_at,correlation_id,created_at,updated_at) VALUES(?,?,? ,?,'fulfillment_required',?,?,?,?,?) ON CONFLICT(workflow_type,entity_type,entity_id) DO NOTHING",[
            $workflowId,'subscription_fulfillment','order',$orderId,$json,$now,$correlationId,$now,$now,
        ]);
        $this->db->execute("INSERT INTO provisioning_operations(id,subscription_id,order_id,operation_type,status,desired_state,idempotency_key,next_attempt_at,correlation_id,created_at,updated_at) VALUES(?,?,?,'activate','pending',?,?,?,?,?,?) ON CONFLICT(subscription_id,operation_type,order_id) DO NOTHING",[
            $operationId,$subscriptionId,$orderId,$json,'subscription_activation:'.$orderId,$now,$correlationId,$now,$now,
        ]);
        $this->outbox->enqueue('subscription.provision','provision:'.$subscriptionId,['subscription_id'=>$subscriptionId]);
        $this->audit('order',$orderId,'workflow.started',null,'fulfillment_required','system',$correlationId,['subscription_id'=>$subscriptionId]);
    }

    /** Lease one activation. Null means another worker owns it or it is done. */
    public function claimActivation(string $subscriptionId, string $worker): ?array
    {
        return $this->db->transaction(function() use($subscriptionId,$worker) {
            $op=$this->db->one("SELECT * FROM provisioning_operations WHERE subscription_id=? AND operation_type='activate' ORDER BY created_at DESC LIMIT 1".$this->db->lock(),[$subscriptionId]);
            if(!$op || in_array($op['status'],['succeeded','cancelled','failed_needs_attention'],true)) return null;
            $now=time();
            if(in_array($op['status'],['running','verifying'],true) && (int)($op['lease_until']??0)>$now) return null;
            if(in_array($op['status'],['pending','retry','unknown'],true) && (int)($op['next_attempt_at']??0)>$now) return null;
            $attempts=(int)$op['attempts']+1;
            if($attempts>(int)$op['max_attempts']) {
                $this->db->execute("UPDATE provisioning_operations SET status='failed_needs_attention',last_error='attempt_limit',updated_at=? WHERE id=?",[$now,$op['id']]);
                $this->db->execute("UPDATE workflows SET state='failed_needs_attention',last_error='attempt_limit',lease_until=NULL,updated_at=? WHERE workflow_type='subscription_fulfillment' AND entity_id=? AND state<>'completed'",[$now,$op['order_id']]);
                // A terminal retry limit must be visible to people, not just a
                // dormant row in the workflow table. The deterministic code
                // keeps crash/retry paths from opening duplicate cases.
                $caseCode='FULFILL-'.substr($op['id'],0,20);
                $user=$this->db->one('SELECT user_id FROM subscriptions WHERE id=?',[$subscriptionId]);
                $payment=$this->db->one('SELECT id FROM payments WHERE order_id=? ORDER BY created_at DESC LIMIT 1',[$op['order_id']]);
                $this->db->execute("INSERT INTO operational_cases(id,code,severity,status,title,user_id,payment_id,subscription_id,details,created_at) VALUES(?,?,?,'open',?,?,?,?,?,?) ON CONFLICT(code) DO NOTHING",[
                    Database::id(),$caseCode,'high','Provisioning retry limit reached',$user['user_id']??null,$payment['id']??null,$subscriptionId,'Automatic recovery exhausted for activation operation '.$op['id'].'.',$now,
                ]);
                $this->audit('subscription',$subscriptionId,'provisioning.escalated',$op['status'],'failed_needs_attention','system',$op['correlation_id'],['operation_id'=>$op['id'],'attempts'=>$attempts]);
                return null;
            }
            $this->db->execute("UPDATE provisioning_operations SET status=?,attempts=?,leased_by=?,lease_until=?,started_at=COALESCE(started_at,?),updated_at=? WHERE id=?",[
                $op['status']==='unknown'?'verifying':'running',$attempts,$worker,$now+120,$now,$now,$op['id'],
            ]);
            $op['status']=$op['status']==='unknown'?'verifying':'running'; $op['attempts']=$attempts;
            return $op;
        });
    }

    public function succeeded(string $subscriptionId, array $actual): void
    {
        $now=time(); $json=json_encode($actual,JSON_THROW_ON_ERROR);
        $this->db->transaction(function() use($subscriptionId,$now,$json) {
            $this->db->execute("UPDATE provisioning_operations SET status='succeeded',actual_state=?,completed_at=?,lease_until=NULL,last_error=NULL,updated_at=? WHERE subscription_id=? AND operation_type='activate' AND status NOT IN ('succeeded','cancelled')",[$json,$now,$now,$subscriptionId]);
            $this->db->execute("UPDATE workflows SET state='completed',completed_at=?,lease_until=NULL,last_error=NULL,updated_at=? WHERE workflow_type='subscription_fulfillment' AND entity_id=(SELECT order_id FROM subscriptions WHERE id=?) AND state<>'completed'",[$now,$now,$subscriptionId]);
        });
    }

    /** A request may have reached the upstream; do not label it as failure. */
    public function unknown(string $subscriptionId, \Throwable $error): void
    {
        $now=time();
        $this->db->execute("UPDATE provisioning_operations SET status='unknown',lease_until=NULL,last_error=?,next_attempt_at=?,updated_at=? WHERE subscription_id=? AND operation_type='activate' AND status IN ('running','verifying')",[get_class($error),$now+10,$now,$subscriptionId]);
        $this->db->execute("UPDATE workflows SET state='verifying',last_error=?,next_attempt_at=?,updated_at=? WHERE workflow_type='subscription_fulfillment' AND entity_id=(SELECT order_id FROM subscriptions WHERE id=?) AND state<>'completed'",[get_class($error),$now+10,$now,$subscriptionId]);
    }

    /** Recreates only disposable delivery commands; intent stays in PostgreSQL. */
    public function recover(int $limit=100): int
    {
        $now=time();
        $rows=$this->db->all("SELECT subscription_id FROM provisioning_operations WHERE operation_type='activate' AND status IN ('pending','retry','unknown','running','verifying') AND ((status IN ('pending','retry','unknown') AND COALESCE(next_attempt_at,0)<=?) OR (status IN ('running','verifying') AND COALESCE(lease_until,0)<=?)) ORDER BY updated_at LIMIT ?",[$now,$now,$limit]);
        foreach($rows as $row) $this->outbox->enqueue('subscription.provision','workflow-recover:'.$row['subscription_id'].':'.intdiv($now,60),['subscription_id'=>$row['subscription_id']]);
        return count($rows);
    }

    private function audit(string $entityType,string $entityId,string $type,?string $old,?string $new,string $actor,string $correlation,array $metadata): void
    {
        $this->db->execute('INSERT INTO audit_events(id,entity_type,entity_id,event_type,old_state,new_state,actor_type,metadata,correlation_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[Database::id(),$entityType,$entityId,$type,$old,$new,$actor,json_encode($metadata,JSON_THROW_ON_ERROR),$correlation,time()]);
    }
}
