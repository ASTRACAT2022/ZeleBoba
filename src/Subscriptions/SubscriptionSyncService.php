<?php
declare(strict_types=1);
namespace App\Subscriptions;

use App\Infrastructure\Database;
use App\Integration\{Provisioner,RemnawaveProvisioner};

/** Pushes PostgreSQL subscription state to the configured provisioning adapter. */
final class SubscriptionSyncService
{
    public function __construct(
        private Database $db,
        private Provisioner $provisioner,
        private string $defaultDriver='demo',
        private string $secretToRedact='',
    ) {}

    /** Sync pending rows, oldest updates first. Errors stay visible and retry next run. */
    public function run(int $limit=100): array
    {
        $limit=max(1,min(1000,$limit));
        $started=microtime(true);
        $rows=$this->db->all(
            "SELECT id FROM subscriptions
              WHERE sync_status IN ('pending','error') AND status IN ('provisioning','active','trial')
              ORDER BY COALESCE(updated_at,created_at),created_at
              LIMIT ?",
            [$limit]
        );
        $result=['checked'=>0,'synced'=>0,'errors'=>0,'pending'=>0];
        foreach($rows as $row){
            // Keep the scheduler available for payment reconciliation even if
            // many panel calls are slow. The remaining rows stay in PostgreSQL.
            if($result['checked']>0 && microtime(true)-$started>=45)break;
            $result['checked']++;
            $status=$this->syncOne((string)$row['id']);
            if($status==='synced')$result['synced']++;
            elseif($status==='error')$result['errors']++;
            else $result['pending']++;
        }
        return $result;
    }

    /** One immediate attempt after commit, also used by the cron command. */
    public function syncOne(string $id): string
    {
        $now=time();
        $version=null;
        $locked=false;
        try{
            // A cron run and a webhook can reach the same subscription at once.
            // Serialize panel writes without holding a database transaction
            // across the network request.
            if($this->db->postgres()){
                $row=$this->db->one('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired',['subscription.sync:'.$id]);
                if(!in_array($row['acquired']??false,[true,1,'1','t'],true))return 'pending';
                $locked=true;
            }
            $subscription=$this->load($id);
            if(!$subscription || !in_array($subscription['status'],['provisioning','active','trial'],true) || $subscription['sync_status']==='synced')return 'synced';
            $version=(int)$subscription['version'];
            $provisioner=($subscription['provision_driver']??$this->defaultDriver)==='demo'
                ? new \App\Integration\DemoProvisioner()
                : $this->provisioner;
            // Legacy accounts can be known only by short UUID. Reuse them
            // instead of creating a second panel user for the same subscription.
            $needsProvision=(string)($subscription['remote_id']??'')===''
                && (int)($subscription['remnawave_id']??0)<=0
                && (string)($subscription['remnawave_short_uuid']??'')==='';
            if($needsProvision){
                $remote=$provisioner->provision($subscription);
                $subscription['remote_id']=(string)($remote['id']??$subscription['remote_id']??'');
                if(ctype_digit($subscription['remote_id']))$subscription['remnawave_id']=(int)$subscription['remote_id'];
                $subscription['subscription_url']=$remote['url']??$subscription['subscription_url']??null;
            }else{
                $provisioner->extend($subscription);
                $remote=['id'=>$subscription['remote_id']??null,'url'=>$subscription['subscription_url']??null];
            }

            $panel=$this->verifyOnPanel($provisioner,$subscription);
            $remoteId=(string)($panel['id']??$remote['id']??$subscription['remote_id']??'');
            $panelId=ctype_digit($remoteId)?(int)$remoteId:null;
            $shortUuid=$panel['shortUuid']??null;
            $uuid=$panel['vlessUuid']??null;
            $url=$panel['subscriptionUrl']??$remote['url']??$subscription['subscription_url']??null;
            $saved=$this->db->transaction(function() use($id,$version,$remoteId,$panelId,$shortUuid,$uuid,$url,$now): int {
                $changed=$this->db->execute(
                    "UPDATE subscriptions SET status=CASE WHEN status='provisioning' THEN 'active' ELSE status END,
                    lifecycle_status=CASE WHEN lifecycle_status='pending' THEN 'active' ELSE lifecycle_status END,
                    remote_id=COALESCE(NULLIF(?,''),remote_id),remnawave_id=COALESCE(?,remnawave_id),
                    remnawave_short_uuid=COALESCE(?,remnawave_short_uuid),remnawave_uuid=COALESCE(?,remnawave_uuid),
                    subscription_url=COALESCE(?,subscription_url),sync_status='synced',sync_error=NULL,
                    synced_at=?,updated_at=?,version=version+1
                    WHERE id=? AND version=? AND sync_status IN ('pending','error')
                      AND status IN ('provisioning','active','trial')",
                    [$remoteId,$panelId,$shortUuid,$uuid,$url,$now,$now,$id,$version]
                );
                if($changed)$this->db->execute("UPDATE orders SET status='fulfilled',workflow_status='fulfilled'
                    WHERE status='paid' AND (id=(SELECT order_id FROM subscriptions WHERE id=?) OR renewal_subscription_id=?)",[$id,$id]);
                return $changed;
            });
            if(!$saved)return 'pending'; // Terms changed during the panel call; cron will send the newer state.
            return 'synced';
        }catch(\Throwable $e){
            $detail=$e->getMessage();
            if($this->secretToRedact!=='')$detail=str_replace($this->secretToRedact,'[redacted]',$detail);
            $message=get_class($e).': '.$detail;
            $message=substr(preg_replace('/[\r\n\t]+/',' ',$message)??get_class($e),0,255);
            $changed=0;
            try{if($version!==null)$changed=$this->db->execute("UPDATE subscriptions SET sync_status='error',sync_error=?,updated_at=? WHERE id=? AND version=? AND sync_status IN ('pending','error')",[$message,$now,$id,$version]);}
            catch(\Throwable $dbError){error_log(json_encode(['event'=>'subscription.sync_state_failed','subscription_id'=>$id,'error_type'=>get_class($dbError)],JSON_UNESCAPED_SLASHES));}
            error_log(json_encode(['event'=>'subscription.sync_failed','subscription_id'=>$id,'error_type'=>get_class($e)],JSON_UNESCAPED_SLASHES));
            return $changed?'error':'pending';
        }finally{
            if($locked)try{$this->db->execute('SELECT pg_advisory_unlock(hashtextextended(?,0))',['subscription.sync:'.$id]);}
            catch(\Throwable $e){error_log(json_encode(['event'=>'subscription.sync_unlock_failed','subscription_id'=>$id,'error_type'=>get_class($e)],JSON_UNESCAPED_SLASHES));}
        }
    }

    private function load(string $id): ?array
    {
        $row=$this->db->one(
            'SELECT s.*,s.device_limit AS devices,COALESCE(o.provision_driver,?) AS provision_driver,
                    COALESCE(o.squad_uuid,p.squad_uuid,\'\') AS squad_uuid
               FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id
               LEFT JOIN plans p ON p.id=s.plan_id WHERE s.id=?',
            [$this->defaultDriver,$id]
        );
        if(!$row)return null;
        $row['traffic_bytes']=(int)$row['traffic_limit_gb']===0?0:((int)$row['traffic_limit_gb']+(int)$row['purchased_traffic_gb'])*1073741824;
        return $row;
    }

    private function verifyOnPanel(Provisioner $provisioner,array $subscription): ?array
    {
        if(!$provisioner instanceof RemnawaveProvisioner)return null;
        $remote=$provisioner->resolve($subscription);
        if(!$remote)throw new \RuntimeException('Remnawave user not found after sync');
        $expires=strtotime((string)($remote['expireAt']??''));
        if(!$expires || abs($expires-(int)$subscription['expires_at'])>60 || ($remote['status']??null)!=='ACTIVE')
            throw new \RuntimeException('Remnawave sync readback failed');
        if((int)($remote['trafficLimitBytes']??-1)!==(int)$subscription['traffic_bytes']
            || (int)($remote['hwidDeviceLimit']??-1)!==(int)$subscription['devices'])
            throw new \RuntimeException('Remnawave limits readback failed');
        return $remote;
    }
}
