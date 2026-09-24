<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
use App\Billing\BillingError;

/** A durable support workspace; notes never change billing state. */
final class InvestigationService
{
    public function __construct(private Database $db) {}
    public function start(string $type,string $id,string $title,string $actor): array
    {
        if(!in_array($type,['user','subscription','payment','incident'],true)||$id==='')throw new BillingError('Некорректный объект расследования.');
        $existing=$this->db->one("SELECT * FROM investigations WHERE subject_type=? AND subject_id=? AND status='open'",[$type,$id]); if($existing)return $existing;
        $now=time();$caseId=Database::id();$code='INV-'.date('Y').'-'.random_int(10000,99999);
        $this->db->execute("INSERT INTO investigations(id,code,status,subject_type,subject_id,title,created_by,created_at) VALUES(?,?, 'open',?,?,?,?,?)",[$caseId,$code,$type,$id,mb_substr(trim($title)?:'Расследование',0,200),$actor,$now]);
        return $this->db->one('SELECT * FROM investigations WHERE id=?',[$caseId]);
    }
    public function detail(string $id): ?array
    {
        $case=$this->db->one('SELECT * FROM investigations WHERE id=?',[$id]);if(!$case)return null;
        $case['notes']=$this->db->all('SELECT n.*,u.email,u.telegram_id FROM investigation_notes n LEFT JOIN users u ON u.id=n.author_id WHERE n.investigation_id=? ORDER BY n.created_at',[$id]);
        $case['context']=$this->context($case['subject_type'],$case['subject_id']);return $case;
    }
    public function note(string $caseId,string $body,string $actor): void
    {
        $body=trim($body);if(mb_strlen($body)<2||mb_strlen($body)>2000)throw new BillingError('Заметка должна содержать от 2 до 2000 символов.');
        if(!$this->db->one("SELECT id FROM investigations WHERE id=? AND status='open'",[$caseId]))throw new BillingError('Расследование закрыто или не найдено.');
        $this->db->execute('INSERT INTO investigation_notes(id,investigation_id,author_id,body,created_at) VALUES(?,?,?,?,?)',[Database::id(),$caseId,$actor,$body,time()]);
    }
    public function resolve(string $caseId,string $actor): void
    {
        $this->db->execute("UPDATE investigations SET status='resolved',resolved_at=? WHERE id=? AND status='open'",[time(),$caseId]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$actor,'investigation.resolved',$caseId,time()]);
    }
    public function list(): array {return $this->db->all('SELECT * FROM investigations ORDER BY CASE status WHEN \'open\' THEN 0 ELSE 1 END,created_at DESC LIMIT 100');}
    public function expectedActual(string $subscriptionId): ?array
    {
        $s=$this->db->one('SELECT s.*,COALESCE(p.name,o.plan_name) plan_name,pa.state,pa.last_synced_at,pa.last_error,pa.external_user_id FROM subscriptions s LEFT JOIN plans p ON p.id=s.plan_id LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN provisioning_accounts pa ON pa.subscription_id=s.id WHERE s.id=?',[$subscriptionId]);if(!$s)return null;
        $expected=['Статус подписки'=>$s['lifecycle_status']??$s['status'],'Срок действия'=>gmdate('d.m.Y H:i',(int)$s['expires_at']),'Лимит трафика'=>((int)($s['traffic_limit_gb']??0)===0?'Безлимит':$s['traffic_limit_gb'].' ГБ'),'Выдача VPN'=>'active'];
        $actual=['Статус подписки'=>$s['status'],'Срок действия'=>gmdate('d.m.Y H:i',(int)$s['expires_at']),'Лимит трафика'=>((int)($s['traffic_limit_gb']??0)===0?'Безлимит':$s['traffic_limit_gb'].' ГБ'),'Выдача VPN'=>$s['state']??'не создана'];
        $rows=[];foreach($expected as $name=>$want)$rows[]=['name'=>$name,'expected'=>$want,'actual'=>$actual[$name],'ok'=>$want===$actual[$name]||($name==='Выдача VPN'&&$actual[$name]==='active')];
        return ['subscription'=>$s,'rows'=>$rows,'freshness'=>$s['last_synced_at']?max(0,time()-(int)$s['last_synced_at']):null];
    }
    public function whyNotRenewed(string $subscriptionId): array
    {
        $s=$this->db->one('SELECT s.*,u.balance_kopeks,COALESCE(p.price_minor,o.price_minor) price_minor FROM subscriptions s JOIN users u ON u.id=s.user_id LEFT JOIN plans p ON p.id=COALESCE(s.renew_plan_id,s.plan_id) LEFT JOIN orders o ON o.id=s.order_id WHERE s.id=?',[$subscriptionId]);if(!$s)throw new BillingError('Подписка не найдена.');
        $reasons=[];$now=time();
        if(!(int)($s['auto_renew']??0))$reasons[]=['ok'=>false,'text'=>'Автопродление выключено пользователем или оператором.'];
        elseif((int)($s['expires_at']??0)>$now)$reasons[]=['ok'=>false,'text'=>'Срок продления ещё не наступил.'];
        elseif((int)$s['balance_kopeks']<(int)$s['price_minor'])$reasons[]=['ok'=>false,'text'=>'Недостаточно средств: доступно '.number_format((int)$s['balance_kopeks']/100,2,',',' ').' ₽, требуется '.number_format((int)$s['price_minor']/100,2,',',' ').' ₽.'];
        elseif($s['renew_order_id'])$reasons[]=['ok'=>true,'text'=>'Заказ на продление уже создан и ожидает обработку.'];
        else $reasons[]=['ok'=>false,'text'=>'Не найдено автоматического заказа: требуется проверка планировщика.'];
        return ['subscription'=>$s,'reasons'=>$reasons];
    }
    public function smartQueues(): array
    {
        return ['need_attention'=>(int)($this->db->one("SELECT COUNT(*) c FROM provisioning_accounts WHERE state IN ('retry','failed')")['c']??0),'payment_issues'=>(int)($this->db->one("SELECT COUNT(*) c FROM payments WHERE status IN ('pending','failed')")['c']??0),'provisioning'=>(int)($this->db->one("SELECT COUNT(*) c FROM provisioning_accounts WHERE state IN ('pending','processing','retry')")['c']??0),'manual_review'=>(int)($this->db->one("SELECT COUNT(*) c FROM operational_cases WHERE status='open'")['c']??0)];
    }
    private function context(string $type,string $id): array
    {
        return match($type){'subscription'=>$this->expectedActual($id)??[],'user'=>['user'=>$this->db->one('SELECT id,email,telegram_id FROM users WHERE id=?',[$id])],'payment'=>['payment'=>$this->db->one('SELECT * FROM payments WHERE id=?',[$id])],'incident'=>['incident'=>$this->db->one('SELECT * FROM incidents WHERE id=?',[$id])],default=>[]};
    }
}
