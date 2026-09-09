<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Container;
final class Reconciler
{
    public function __construct(private Container $app) {}
    public function run():int
    {
        $db=$this->app->db;$token=Database::id();
        $taken=$db->transaction(function()use($db,$token){
            $db->execute("INSERT INTO advisory_leases VALUES('reconcile',?,0) ON CONFLICT(name) DO NOTHING",[$token]);
            $row=$db->one("SELECT * FROM advisory_leases WHERE name='reconcile'".$db->lock());
            if((int)$row['expires_at']>time())return false;
            $db->execute("UPDATE advisory_leases SET token=?,expires_at=? WHERE name='reconcile'",[$token,time()+120]);return true;
        });
        if(!$taken)return 0;
        try{
            $after='';$bucket=intdiv(time(),300);
            do{
                $rows=$db->all("SELECT id,provider,provider_payment_id,freekassa_intid FROM orders WHERE id>? AND status='pending' AND provider IN ('yookassa','freekassa') AND provider_payment_id IS NOT NULL ORDER BY id LIMIT 100",[$after]);
                $db->transaction(function()use($rows,$bucket){foreach($rows as $row){$pid=$row['provider']==='freekassa'&&!empty($row['freekassa_intid'])?$row['freekassa_intid']:$row['provider_payment_id'];$this->app->outbox->enqueue('payment.verify','reconcile:'.$row['id'].':'.$bucket,['payment_id'=>$pid]);}});
                if($rows)$after=end($rows)['id'];
                $db->execute("UPDATE advisory_leases SET expires_at=? WHERE name='reconcile' AND token=?",[time()+120,$token]);
            }while(count($rows)===100);
            // Auto-renew: enqueue renew jobs for subscriptions near expiry
            $autoEnabled=$db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_ENABLED'");
            if (!$autoEnabled || $autoEnabled['value']==='1') {
                $maxFails=(int)($db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_MAX_FAILS'")['value']??3);
                $rows=$db->all("SELECT id FROM subscriptions WHERE auto_renew=1 AND status='active' AND expires_at>? AND renew_at IS NOT NULL AND renew_at<=? AND (renew_order_id IS NULL OR renew_order_id='') AND renew_fail_count<? LIMIT 100",[time(),time(),$maxFails]);
                $db->transaction(function()use($rows){foreach($rows as $r)$this->app->outbox->enqueue('subscription.renew','renew:'.$r['id'],['subscription_id'=>$r['id']]);});
                // Handle failed renewal orders: if renew_order is canceled/expired, schedule retry
                $failed=$db->all("SELECT s.id,s.renew_order_id,s.renew_fail_count FROM subscriptions s JOIN orders o ON o.id=s.renew_order_id WHERE s.auto_renew=1 AND s.status='active' AND o.status='canceled' LIMIT 100");
                foreach($failed as $f){
                    $db->transaction(function()use($f,$maxFails){
                        $fresh=$this->app->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->app->db->lock(),[$f['id']]);
                        if(!$fresh || $fresh['renew_order_id']!==$f['renew_order_id']) return;
                        if((int)$fresh['renew_fail_count']>=$maxFails) return;
                        $this->app->db->execute('UPDATE subscriptions SET renew_order_id=NULL,renew_failed_at=?,renew_fail_count=renew_fail_count+1,renew_at=? WHERE id=?',[time(),time()+3600,$f['id']]);
                    });
                }
                // Expired renew window: if subscription expired and auto_renew still on, disable after max fails
                $db->execute("UPDATE subscriptions SET auto_renew=0 WHERE auto_renew=1 AND status='expired'");
            }
            $db->execute("UPDATE subscriptions SET status='expired' WHERE status='active' AND expires_at<=?",[time()]);
            foreach(['sessions','telegram_links','login_challenges','mfa_enrollments','rate_limits'] as $table)$db->execute("DELETE FROM $table WHERE expires_at<=?",[time()]);
            $db->execute("DELETE FROM outbox WHERE status='done' AND created_at<?",[time()-30*86400]);
            $db->execute('DELETE FROM telegram_updates WHERE created_at<?',[time()-7*86400]);
            self::heartbeat($db,'scheduler');return 0;
        }finally{$db->execute("DELETE FROM advisory_leases WHERE name='reconcile' AND token=?",[$token]);}
    }
    public static function heartbeat(Database $db,string $name):void{$db->execute('INSERT INTO runtime_heartbeats VALUES(?,?) ON CONFLICT(name) DO UPDATE SET seen_at=excluded.seen_at',[$name,time()]);}
}
