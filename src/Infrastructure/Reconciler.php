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
            $db->execute("UPDATE subscriptions SET status='expired' WHERE status='active' AND expires_at<=?",[time()]);
            foreach(['sessions','telegram_links','login_challenges','mfa_enrollments','rate_limits'] as $table)$db->execute("DELETE FROM $table WHERE expires_at<=?",[time()]);
            $db->execute("DELETE FROM outbox WHERE status='done' AND created_at<?",[time()-30*86400]);
            $db->execute('DELETE FROM telegram_updates WHERE created_at<?',[time()-7*86400]);
            self::heartbeat($db,'scheduler');return 0;
        }finally{$db->execute("DELETE FROM advisory_leases WHERE name='reconcile' AND token=?",[$token]);}
    }
    public static function heartbeat(Database $db,string $name):void{$db->execute('INSERT INTO runtime_heartbeats VALUES(?,?) ON CONFLICT(name) DO UPDATE SET seen_at=excluded.seen_at',[$name,time()]);}
}
