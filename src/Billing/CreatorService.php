<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;

/** Financial boundary for creators. Balances are always derived from creator_ledger. */
final class CreatorService
{
    public function __construct(private Database $db) {}
    public function capture(string $code, ?string $campaign, ?string $ip): ?string
    {
        $creator=$this->db->one("SELECT id FROM creators WHERE code=? AND status='active'",[mb_strtoupper(trim($code))]);
        if (!$creator) return null;
        $token=bin2hex(random_bytes(24)); $now=time();
        $days=(int)($this->db->one('SELECT attribution_days FROM creators WHERE id=?',[$creator['id']])['attribution_days']??30);
        $this->db->execute('INSERT INTO creator_attributions(id,creator_id,visitor_token,campaign,clicked_at,expires_at,source_ip_hash) VALUES(?,?,?,?,?,?,?)',[Database::id(),$creator['id'],$token,$campaign!==null?mb_substr($campaign,0,120):null,$now,$now+$days*86400,$ip?hash('sha256',$ip):null]);
        return $token;
    }
    public function attachRegistration(string $userId, ?string $token): void
    {
        if (!$token || !preg_match('/^[a-f0-9]{48}$/D',$token)) return;
        $this->db->transaction(function()use($userId,$token){
            $row=$this->db->one('SELECT * FROM creator_attributions WHERE visitor_token=?'.$this->db->lock(),[$token]);
            if (!$row || $row['user_id']!==null || (int)$row['expires_at']<time()) return;
            $creator=$this->db->one('SELECT * FROM creators WHERE id=? AND status=\'active\'',[$row['creator_id']]);
            if (!$creator || $creator['user_id']===$userId) return;
            if ($this->db->execute('UPDATE creator_attributions SET user_id=?,attributed_at=? WHERE id=? AND user_id IS NULL',[$userId,time(),$row['id']])) $this->audit($userId,'referral.attributed',$creator['id']);
        });
    }
    /** Called in BillingService's successful payment transaction. */
    public function recordPayment(string $paymentId): void
    {
        $payment=$this->db->one("SELECT p.*,a.creator_id FROM payments p JOIN creator_attributions a ON a.user_id=p.user_id WHERE p.id=? AND p.status='succeeded'",[$paymentId]);
        if (!$payment || $this->db->one('SELECT id FROM creator_commissions WHERE payment_id=?',[$paymentId])) return;
        $creator=$this->db->one("SELECT * FROM creators WHERE id=? AND status='active'",[$payment['creator_id']]); if (!$creator) return;
        $prior=(int)($this->db->one("SELECT COUNT(*) c FROM creator_commissions WHERE creator_id=? AND customer_id=? AND status<>'reversed'",[$creator['id'],$payment['user_id']])['c']??0);
        if ($prior===0) {$percent=(int)$creator['first_percent'];$kind='first';}
        else { $first=$this->db->one("SELECT created_at FROM creator_commissions WHERE creator_id=? AND customer_id=? AND kind='first' ORDER BY created_at LIMIT 1",[$creator['id'],$payment['user_id']]); if (!$first || (int)$first['created_at']+(int)$creator['recurring_days']*86400<time()) return; $percent=(int)$creator['recurring_percent'];$kind='recurring'; }
        $commission=intdiv((int)$payment['amount_minor']*$percent,100); if($commission<=0)return;
        $now=time();$id=Database::id();
        try {$this->db->execute('INSERT INTO creator_commissions(id,creator_id,customer_id,payment_id,amount_minor,commission_minor,kind,status,available_at,created_at) VALUES(?,?,?,?,?,?,?,\'pending\',?,?)',[$id,$creator['id'],$payment['user_id'],$paymentId,(int)$payment['amount_minor'],$commission,$kind,$now+(int)$creator['hold_days']*86400,$now]);}
        catch(\PDOException $e){if(in_array($e->getCode(),['23000','23505'],true))return;throw $e;}
        $this->ledger($creator['id'],'commission',$commission,$id,null,['payment_id'=>$paymentId,'percent'=>$percent]); $this->audit('system','commission.created',$id); $this->awardMilestones($creator['id']);
    }
    public function releaseDue(): int { $rows=$this->db->all("SELECT id FROM creator_commissions WHERE status='pending' AND available_at<=?",[time()]); foreach($rows as $r)$this->db->transaction(function()use($r){if($this->db->execute("UPDATE creator_commissions SET status='available' WHERE id=? AND status='pending'",[$r['id']]))$this->audit('system','commission.available',$r['id']);}); return count($rows); }
    public function reversePayment(string $paymentId): void { $this->db->transaction(function()use($paymentId){$c=$this->db->one("SELECT * FROM creator_commissions WHERE payment_id=?".$this->db->lock(),[$paymentId]);if(!$c||in_array($c['status'],['reversed','paid'],true))return;$this->db->execute("UPDATE creator_commissions SET status='reversed',reversed_at=? WHERE id=?",[time(),$c['id']]);$this->ledger($c['creator_id'],'reversal',-(int)$c['commission_minor'],null,null,['commission_id'=>$c['id'],'payment_id'=>$paymentId]);$this->audit('system','commission.reversed',$c['id']);}); }
    /** Pending commissions are deliberately excluded from withdrawable funds. */
    public function balance(string $creatorId): array {
        $r=$this->db->one("SELECT
          COALESCE(SUM(l.amount_minor),0) total,
          COALESCE(SUM(CASE WHEN l.entry_type='commission' AND c.status<>'available' THEN l.amount_minor ELSE 0 END),0) pending
          FROM creator_ledger l LEFT JOIN creator_commissions c ON c.id=l.commission_id WHERE l.creator_id=?",[$creatorId]);
        $total=(int)$r['total']; $pending=(int)$r['pending']; return ['total'=>$total,'pending'=>$pending,'available'=>$total-$pending];
    }
    public function requestPayout(string $userId,int $amount,string $details): array
    {
        if($amount<=0||$amount>1000000000||mb_strlen(trim($details))<5||mb_strlen($details)>500) throw new BillingError('Проверьте сумму и реквизиты выплаты.');
        return $this->db->transaction(function()use($userId,$amount,$details){$c=$this->db->one("SELECT * FROM creators WHERE user_id=? AND status='active'".$this->db->lock(),[$userId]);if(!$c)throw new BillingError('Creator Mode недоступен.');$b=$this->balance($c['id']);if($amount>$b['available'])throw new BillingError('Недостаточно доступных средств.');$id=Database::id();$now=time();$this->db->execute("INSERT INTO creator_payouts(id,creator_id,amount_minor,details,status,requested_at) VALUES(?,?,?,?, 'requested',?)",[$id,$c['id'],$amount,trim($details),$now]);$this->ledger($c['id'],'payout_reserve',-$amount,null,$id,['status'=>'requested']);$this->audit($userId,'payout.requested',$id);return $this->db->one('SELECT * FROM creator_payouts WHERE id=?',[$id]);});
    }
    public function processPayout(string $id,string $status,string $adminId,?string $operationId=null,?string $comment=null): void
    {
        if(!in_array($status,['processing','paid','rejected','cancelled'],true))throw new BillingError('Некорректный статус выплаты.');
        $this->db->transaction(function()use($id,$status,$adminId,$operationId,$comment){$p=$this->db->one('SELECT * FROM creator_payouts WHERE id=?'.$this->db->lock(),[$id]);if(!$p)throw new BillingError('Выплата не найдена.');if($p['status']===$status)return;if($p['status']!=='requested'&& !($p['status']==='processing'&&$status==='paid'))throw new BillingError('Недопустимый переход статуса.');if(in_array($status,['rejected','cancelled'],true))$this->ledger($p['creator_id'],'payout_release',(int)$p['amount_minor'],null,$id,['reason'=>$status]);$this->db->execute('UPDATE creator_payouts SET status=?,processed_at=?,processed_by=?,operation_id=?,comment=? WHERE id=?',[$status,time(),$adminId,$operationId,$comment,$id]);$this->audit($adminId,'payout.'.$status,$id);});
    }
    public function reconcile(string $actor='scheduler'): array { $id=Database::id();$this->db->execute('INSERT INTO creator_reconciliation_runs(id,started_at,actor) VALUES(?,?,?)',[$id,time(),$actor]);$missing=$this->db->all("SELECT p.id FROM payments p JOIN creator_attributions a ON a.user_id=p.user_id JOIN creators c ON c.id=a.creator_id AND c.status='active' LEFT JOIN creator_commissions x ON x.payment_id=p.id WHERE p.status='succeeded' AND x.id IS NULL",[]);foreach($missing as $p)$this->db->transaction(fn()=>$this->recordPayment($p['id']));$inconsistent=(int)($this->db->one("SELECT COUNT(*) c FROM creator_commissions c LEFT JOIN payments p ON p.id=c.payment_id AND p.status='succeeded' WHERE p.id IS NULL")['c']??0);$this->db->execute('UPDATE creator_reconciliation_runs SET completed_at=?,missing_count=?,inconsistent_count=? WHERE id=?',[time(),count($missing),$inconsistent,$id]);return ['missing'=>count($missing),'inconsistent'=>$inconsistent]; }
    public function dashboard(string $userId): ?array { $c=$this->db->one("SELECT * FROM creators WHERE user_id=? AND status='active'",[$userId]);if(!$c)return null;$b=$this->balance($c['id']);$stats=$this->db->one('SELECT COUNT(*) payments,COALESCE(SUM(amount_minor),0) revenue FROM creator_commissions WHERE creator_id=? AND status<>\'reversed\'',[$c['id']]);$pending=(int)($this->db->one("SELECT COALESCE(SUM(commission_minor),0) v FROM creator_commissions WHERE creator_id=? AND status='pending'",[$c['id']])['v']??0);$clicks=(int)($this->db->one('SELECT COUNT(*) v FROM creator_attributions WHERE creator_id=?',[$c['id']])['v']??0);return ['creator'=>$c,'balance'=>$b,'pending'=>$pending,'stats'=>$stats,'clicks'=>$clicks,'commissions'=>$this->db->all('SELECT * FROM creator_commissions WHERE creator_id=? ORDER BY created_at DESC LIMIT 100',[$c['id']])]; }
    public function profile(string $userId): ?array { return $this->db->one('SELECT * FROM creators WHERE user_id=?',[$userId]); }
    public function activate(string $userId,array $input,string $adminId): array
    {
        $code=mb_strtoupper(trim((string)($input['code']??''))); if(!preg_match('/^CAT-[A-Z0-9]{5,24}$/D',$code)) throw new BillingError('Код Creator: CAT- и 5–24 латинских букв или цифр.');
        foreach(['first_percent'=>[0,100],'recurring_percent'=>[0,100],'recurring_days'=>[0,3650],'attribution_days'=>[1,365],'hold_days'=>[0,365]] as $key=>[$min,$max]) if(!isset($input[$key]) || filter_var($input[$key],FILTER_VALIDATE_INT)===false || (int)$input[$key]<$min || (int)$input[$key]>$max) throw new BillingError('Проверьте настройки Creator Program.');
        return $this->db->transaction(function()use($userId,$input,$adminId,$code){ if(!$this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$userId]))throw new BillingError('Пользователь не найден.');$old=$this->profile($userId);$now=time();$values=[$code,(int)$input['first_percent'],(int)$input['recurring_percent'],(int)$input['recurring_days'],(int)$input['hold_days'],(int)$input['attribution_days'],$now];if(!$old){$id=Database::id();$name=(string)($this->db->one('SELECT COALESCE(email,telegram_id,\'Creator\') v FROM users WHERE id=?',[$userId])['v']??'Creator');$this->db->execute("INSERT INTO creators(id,user_id,name,code,status,first_percent,recurring_percent,recurring_days,hold_days,attribution_days,created_at,updated_at) VALUES(?,?,?,'".$code."','active',?,?,?,?,?,?,?)",[$id,$userId,$name,(int)$input['first_percent'],(int)$input['recurring_percent'],(int)$input['recurring_days'],(int)$input['hold_days'],(int)$input['attribution_days'],$now,$now]);$action='creator.enabled';}else{$id=$old['id'];$this->db->execute("UPDATE creators SET code=?,status='active',first_percent=?,recurring_percent=?,recurring_days=?,hold_days=?,attribution_days=?,updated_at=? WHERE id=?",array_merge($values,[$id]));$action=$old['status']==='suspended'?'creator.reactivated':'creator.settings_updated';}$this->audit($adminId,$action,$id);return $this->profile($userId);});
    }
    public function suspend(string $userId,string $adminId): void {$this->db->transaction(function()use($userId,$adminId){$c=$this->profile($userId);if(!$c)return;$this->db->execute("UPDATE creators SET status='suspended',updated_at=? WHERE id=?",[time(),$c['id']]);$this->audit($adminId,'creator.suspended',$c['id']);});}
    private function awardMilestones(string $creatorId): void
    {
        $paid=(int)($this->db->one("SELECT COUNT(DISTINCT customer_id) c FROM creator_commissions WHERE creator_id=? AND status<>'reversed'",[$creatorId])['c']??0);
        foreach($this->db->all('SELECT * FROM creator_milestones WHERE active=1 AND threshold<=? ORDER BY threshold',[$paid]) as $m){
            try {
                // The ledger entry and the once-only award must be atomic. A
                // duplicate worker rolls back this savepoint, not just the
                // award INSERT, so no orphan milestone ledger entry remains.
                $this->db->transaction(function()use($creatorId,$m){
                    $lid=$this->ledger($creatorId,'milestone',(int)$m['bonus_minor'],null,null,['milestone_id'=>$m['id']]);
                    $this->db->execute('INSERT INTO creator_milestone_awards(id,creator_id,milestone_id,ledger_id,created_at) VALUES(?,?,?,?,?)',[Database::id(),$creatorId,$m['id'],$lid,time()]);
                    $this->audit('system','milestone.achieved',$m['id']);
                });
            } catch(\PDOException $e) { if(!in_array($e->getCode(),['23000','23505'],true))throw $e; }
        }
    }
    private function ledger(string $creatorId,string $type,int $amount,?string $commissionId,?string $payoutId,array $meta): string {$id=Database::id();$this->db->execute('INSERT INTO creator_ledger(id,creator_id,entry_type,amount_minor,commission_id,payout_id,metadata,created_at) VALUES(?,?,?,?,?,?,?,?)',[$id,$creatorId,$type,$amount,$commissionId,$payoutId,json_encode($meta,JSON_THROW_ON_ERROR),time()]);return $id;}
    private function audit(string $actor,string $action,string $subject):void{$this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$actor,$action,$subject,time()]);}
}
