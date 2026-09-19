<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;

/** Durable, secret-safe business timeline used by support and admin UI. */
final class OperationsService
{
    public function __construct(private Database $db) {}
    public function start(string $type,array $refs=[],?string $correlationId=null): array
    {
        $operationId='op_'.Database::id(); $correlationId??='cor_'.Database::id();
        $existing=$this->db->one('SELECT id,correlation_id,trace_id FROM operations WHERE correlation_id=?',[$correlationId]);
        if($existing){$existing['existing']=true;return $existing;}
        $traceId=bin2hex(random_bytes(16)); $now=time();
        $this->db->execute('INSERT INTO operations(id,correlation_id,trace_id,type,status,user_id,subscription_id,order_id,payment_id,started_at,metadata) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[
            $operationId,$correlationId,$traceId,$type,'processing',$refs['user_id']??null,$refs['subscription_id']??null,$refs['order_id']??null,$refs['payment_id']??null,$now,$this->json($refs['metadata']??[])
        ]);
        $this->event($operationId,$type.'.started','processing','Operation started',$refs);
        return ['id'=>$operationId,'correlation_id'=>$correlationId,'trace_id'=>$traceId,'existing'=>false];
    }
    public function event(string $operationId,string $type,string $status,string $message,array $refs=[]): void
    {
        $op=$this->db->one('SELECT correlation_id,trace_id,user_id,subscription_id,order_id,payment_id FROM operations WHERE id=?',[$operationId]);
        if (!$op) return; $now=time();
        $this->db->execute('INSERT INTO operation_events(id,operation_id,correlation_id,trace_id,span_id,user_id,subscription_id,order_id,payment_id,type,status,message,metadata,occurred_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            Database::id(),$operationId,$op['correlation_id'],$op['trace_id'],$refs['span_id']??null,$refs['user_id']??$op['user_id'],$refs['subscription_id']??$op['subscription_id'],$refs['order_id']??$op['order_id'],$refs['payment_id']??$op['payment_id'],$type,$status,$message,$this->json($refs['metadata']??[]),$now,$now
        ]);
    }
    public function complete(string $operationId,string $status='success',?string $message=null,array $metadata=[]): void
    {
        $this->db->execute('UPDATE operations SET status=?,completed_at=?,metadata=? WHERE id=?',[$status,time(),$this->json($metadata),$operationId]);
        $this->event($operationId,'operation.completed',$status,$message??'Operation completed',['metadata'=>$metadata]);
    }
    public function fail(string $operationId,\Throwable $e,array $metadata=[]): void
    {
        $this->db->execute('UPDATE operations SET status=\'failed\',completed_at=?,last_error=? WHERE id=?',[time(),get_class($e),$operationId]);
        $this->event($operationId,'operation.failed','failed','Application error',['metadata'=>$metadata+['error_class'=>get_class($e)]]);
    }
    public function search(string $query='',string $type='',string $status='',int $since=0): array
    {
        $where=[];$params=[];
        if($type!==''){$where[]='o.type=?';$params[]=$type;}
        if($status!==''){$where[]='o.status=?';$params[]=$status;}
        if($since>0){$where[]='o.started_at>=?';$params[]=$since;}
        if($query!==''){
            $like='%'.str_replace(['%','_'],['\\%','\\_'],$query).'%';
            $where[]='(o.id LIKE ? OR o.correlation_id LIKE ? OR o.trace_id LIKE ? OR o.user_id LIKE ? OR o.subscription_id LIKE ? OR o.order_id LIKE ? OR p.provider_payment_id LIKE ? OR ui.external_id LIKE ?)';
            array_push($params,$like,$like,$like,$like,$like,$like,$like,$like);
        }
        $sql='SELECT o.*,u.email,u.telegram_id,p.provider,p.provider_payment_id,p.amount_minor,p.currency FROM operations o LEFT JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.id=o.payment_id LEFT JOIN user_identities ui ON ui.user_id=o.user_id AND ui.type=\'telegram\' '.($where?'WHERE '.implode(' AND ',$where):'').' ORDER BY o.started_at DESC LIMIT 100';
        return $this->db->all($sql,$params);
    }
    public function detail(string $id): ?array
    {
        $operation=$this->db->one('SELECT o.*,u.email,u.telegram_id,p.provider,p.provider_payment_id,p.amount_minor,p.currency FROM operations o LEFT JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.id=o.payment_id WHERE o.id=?',[$id]);
        if(!$operation)return null;
        $operation['events']=$this->db->all('SELECT * FROM operation_events WHERE operation_id=? ORDER BY occurred_at,id',[$id]);
        $operation['outbox']=$this->db->all('SELECT id,topic,status,attempts,available_at,last_error,created_at FROM outbox WHERE correlation_id=? ORDER BY created_at',[$operation['correlation_id']]);
        $operation['provisioning']=$operation['subscription_id']?$this->db->one('SELECT * FROM provisioning_accounts WHERE subscription_id=?',[$operation['subscription_id']]):null;
        return $operation;
    }
    private function json(array $metadata): string { return json_encode($this->redact($metadata),JSON_THROW_ON_ERROR); }
    private function redact(array $value): array
    {
        $out=[];foreach($value as $key=>$item){$key=(string)$key;
            if(preg_match('/(authorization|token|secret|password|cookie|subscription.*url|credential|bank)/i',$key)){ $out[$key]='[REDACTED]'; continue; }
            $out[$key]=is_array($item)?$this->redact($item):(is_string($item)&&preg_match('~^https?://~',$item)?preg_replace('/(.{12}).*(.{4})$/','$1********$2',$item):$item);
        }return $out;
    }
}
