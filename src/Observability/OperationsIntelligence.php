<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
use App\Billing\BillingError;

/** Read models and safe control-plane actions for support investigations. */
final class OperationsIntelligence
{
    public function __construct(private Database $db, private ConsistencyChecker $consistency) {}
    public function timeTravel(string $userId, int $at): array
    {
        if ($at < 1 || $at > time()) throw new BillingError('Можно исследовать только прошедший момент времени.');
        $user=$this->db->one('SELECT id,email,telegram_id,created_at FROM users WHERE id=?',[$userId]);
        if(!$user) throw new BillingError('Пользователь не найден.');
        $balance=$this->db->one('SELECT COALESCE(SUM(amount_kopeks),0) amount FROM transactions WHERE user_id=? AND created_at<=?',[$userId,$at]);
        $subs=$this->db->all("SELECT s.*,COALESCE(pv.name,o.plan_name,p.name) plan_name,pv.version_number,pa.state provisioning_state,pa.last_synced_at,pa.last_error FROM subscriptions s LEFT JOIN plan_versions pv ON pv.id=s.plan_version_id LEFT JOIN plans p ON p.id=s.plan_id LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN provisioning_accounts pa ON pa.subscription_id=s.id WHERE s.user_id=? AND s.created_at<=? ORDER BY s.created_at DESC",[$userId,$at]);
        foreach($subs as &$sub){$sub['historical_status']=(int)$sub['expires_at']>$at ? ($sub['lifecycle_status']??$sub['status']) : 'expired';}
        $payments=$this->db->all('SELECT provider,provider_payment_id,amount_minor,status,paid_at,created_at FROM payments WHERE user_id=? AND created_at<=? ORDER BY created_at DESC LIMIT 10',[$userId,$at]);
        $events=$this->db->all('SELECT event_type,payload,occurred_at FROM customer_timeline WHERE user_id=? AND occurred_at<=? ORDER BY occurred_at DESC LIMIT 15',[$userId,$at]);
        $after=$this->db->all('SELECT event_type,payload,occurred_at FROM customer_timeline WHERE user_id=? AND occurred_at>? ORDER BY occurred_at ASC LIMIT 15',[$userId,$at]);
        return compact('user','at','balance','subs','payments','events','after');
    }
    public function simulate(string $userId, string $planId, int $promoPercent, string $action='renew'): array
    {
        $user=$this->db->one('SELECT balance_kopeks FROM users WHERE id=?',[$userId]); $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
        if(!$user||!$plan) throw new BillingError('Пользователь или активный тариф не найден.');
        $promo=max(0,min(99,$promoPercent)); $charge=(int)round((int)$plan['price_minor']*(100-$promo)/100);
        $sub=$this->db->one("SELECT * FROM subscriptions WHERE user_id=? ORDER BY expires_at DESC LIMIT 1",[$userId]);
        $base=max(time(),(int)($sub['expires_at']??time())); $after=$base+(int)$plan['duration_days']*86400;
        return ['action'=>$action,'plan'=>$plan,'promo_percent'=>$promo,'charge_minor'=>$charge,'balance_minor'=>(int)$user['balance_kopeks'],'balance_after_minor'=>(int)$user['balance_kopeks']-$charge,'sufficient'=>(int)$user['balance_kopeks']>=$charge,'subscription_before'=>$sub,'expires_after'=>$after,'will_change'=>(int)$user['balance_kopeks']>=$charge];
    }
    public function blastRadius(string $service): array
    {
        $where=$service==='remnawave' ? "p.provider='remnawave'" : '1=1';
        $affected=(int)($this->db->one("SELECT COUNT(DISTINCT s.user_id) c FROM provisioning_accounts p JOIN subscriptions s ON s.id=p.subscription_id WHERE $where AND p.state IN ('pending','processing','retry','failed')")['c']??0);
        $active=(int)($this->db->one("SELECT COUNT(*) c FROM provisioning_accounts p JOIN subscriptions s ON s.id=p.subscription_id WHERE $where AND s.lifecycle_status='active'")['c']??0);
        $delayed=(int)($this->db->one("SELECT COUNT(*) c FROM provisioning_accounts p WHERE $where AND p.state IN ('pending','processing','retry','failed')")['c']??0);
        $waiting=(int)($this->db->one("SELECT COUNT(*) c FROM payments WHERE status='pending'")['c']??0);
        return ['service'=>$service,'affected_users'=>$affected,'active_subscriptions'=>$active,'payments_waiting'=>$waiting,'money_at_risk_minor'=>0,'provisioning_delayed'=>$delayed];
    }
    public function graph(string $subscriptionId): ?array
    {
        $s=$this->db->one('SELECT s.*,u.email,u.telegram_id FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE s.id=?',[$subscriptionId]); if(!$s)return null;
        return ['user'=>['id'=>$s['user_id'],'label'=>$s['email']??('TG '.$s['telegram_id'])],'subscription'=>$s,'order'=>$s['order_id']?$this->db->one('SELECT * FROM orders WHERE id=?',[$s['order_id']]):null,'payment'=>$s['order_id']?$this->db->one('SELECT * FROM payments WHERE order_id=? ORDER BY created_at DESC LIMIT 1',[$s['order_id']]):null,'ledger'=>$s['order_id']?$this->db->all('SELECT * FROM ledger_entries WHERE order_id=?',[$s['order_id']]):[],'provisioning'=>$this->db->one('SELECT * FROM provisioning_accounts WHERE subscription_id=?',[$subscriptionId])];
    }
    public function overview(): array
    {
        $checks=$this->invariants(false); $cases=$this->db->all("SELECT * FROM operational_cases WHERE status='open' ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,created_at DESC LIMIT 20");
        $maintenance=$this->db->all('SELECT * FROM service_maintenance_windows WHERE ends_at>? ORDER BY starts_at LIMIT 20',[time()]);
        $providers=$this->db->all("SELECT provider,COUNT(*) total,SUM(CASE WHEN status='succeeded' THEN 1 ELSE 0 END) succeeded,SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed FROM payments WHERE created_at>? GROUP BY provider ORDER BY total DESC",[time()-86400]);
        $blast=$this->blastRadius('remnawave');
        $canary=$this->db->one('SELECT * FROM canary_runs ORDER BY created_at DESC LIMIT 1');
        $migrations=$this->db->all('SELECT * FROM migration_runs ORDER BY updated_at DESC LIMIT 10');
        return compact('checks','cases','maintenance','providers','blast','canary','migrations');
    }
    public function invariants(bool $createCases): array
    {
        $money=$this->consistency->run();
        $paidWithoutSub=$this->db->all("SELECT o.id,o.user_id FROM orders o LEFT JOIN subscriptions s ON s.order_id=o.id WHERE o.workflow_status IN ('paid','fulfilled') GROUP BY o.id,o.user_id HAVING COUNT(s.id)=0");
        $activeExpired=$this->db->all("SELECT id,user_id FROM subscriptions WHERE lifecycle_status='active' AND expires_at<=?",[time()]);
        $violations=['financial'=>$money['count'],'paid_without_fulfillment'=>count($paidWithoutSub),'active_expired'=>count($activeExpired)];
        if($createCases){foreach($paidWithoutSub as $row)$this->caseOnce('high','Оплата/заказ без выдачи подписки',$row['user_id'],null,null,['order_id'=>$row['id']]);foreach($activeExpired as $row)$this->caseOnce('medium','Активная подписка с истёкшим сроком',$row['user_id'],null,$row['id'],[]);}
        return ['checked'=>array_sum($violations)+1,'ok'=>array_sum($violations)===0,'violations'=>$violations];
    }
    public function setMaintenance(string $service,int $startsAt,int $endsAt,string $note,string $actor): void
    {
        if(!in_array($service,['remnawave','payments','worker'],true)||$startsAt<time()-3600||$endsAt<=$startsAt||mb_strlen($note)<3)throw new BillingError('Проверьте параметры технических работ.');
        $this->db->execute('INSERT INTO service_maintenance_windows(id,service,starts_at,ends_at,note,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[Database::id(),$service,$startsAt,$endsAt,$note,$actor,time()]);
    }
    public function setSafety(bool $enabled,string $actor): void
    {
        $this->db->execute('INSERT INTO app_settings(name,value,updated_at) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',['GLOBAL_SAFETY_MODE',$enabled?'1':'0',time()]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$actor,$enabled?'safety_mode.enabled':'safety_mode.disabled','global',time()]);
    }
    private function caseOnce(string $severity,string $title,?string $userId,?string $paymentId,?string $subscriptionId,array $details): void
    {
        $signature=hash('sha256',$title.'|'.$userId.'|'.$paymentId.'|'.$subscriptionId); if($this->db->one("SELECT id FROM operational_cases WHERE status='open' AND details LIKE ?",['%'.$signature.'%']))return;
        $this->db->execute('INSERT INTO operational_cases(id,code,severity,status,title,user_id,payment_id,subscription_id,details,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[Database::id(),'OPS-'.date('Y').'-'.random_int(10000,99999),$severity,'open',$title,$userId,$paymentId,$subscriptionId,json_encode($details+['signature'=>$signature],JSON_THROW_ON_ERROR),time()]);
    }
}
