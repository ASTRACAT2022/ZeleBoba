<?php
declare(strict_types=1);
namespace App\Integration;
use App\Infrastructure\Database;
final class RemnawaveSync
{
    public function __construct(private Database $db, private RemnawaveProvisioner $provisioner) {}
    /**
     * Sync local subscriptions with Remnawave panel.
     * Returns report: checked, fixed, disabled, missing, errors.
     * - For active local subs: ensure panel user exists and expireAt/traffic/devices match; fix via PATCH if drift > 60s.
     * - For expired local subs: disable panel user if still ACTIVE.
     * - Missing panel users for active subs are re-provisioned.
     */
    public function run(int $limit=100, bool $fix=true): array
    {
        $report=['checked'=>0,'fixed'=>0,'disabled'=>0,'reprovisioned'=>0,'missing'=>0,'errors'=>0,'details'=>[]];
        $subs=$this->db->all("SELECT s.*,o.traffic_bytes,o.devices,o.squad_uuid FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.status IN ('active','expired') ORDER BY s.created_at DESC LIMIT ?",[$limit]);
        foreach($subs as $s){
            $report['checked']++;
            $username='zb_'.$s['id'];
            try {
                $remote=$this->provisioner->fetch($username);
                if($s['status']==='expired'){
                    if($remote && ($remote['status']??'')==='ACTIVE'){
                        if($fix){ $this->provisioner->disable($username); $report['disabled']++; $report['details'][]="$username: disabled expired"; }
                        else { $report['details'][]="$username: would disable expired"; }
                    }
                    continue;
                }
                // active
                if(!$remote){
                    $report['missing']++;
                    if($fix){
                        $result=$this->provisioner->provision($s);
                        $this->db->execute('UPDATE subscriptions SET remote_id=?,subscription_url=? WHERE id=?',[$result['id'],$result['url'],$s['id']]);
                        $report['reprovisioned']++; $report['details'][]="$username: reprovisioned";
                    } else {
                        $report['details'][]="$username: missing on panel";
                    }
                    continue;
                }
                // Compare expireAt (panel is ISO8601), traffic, devices
                $panelExpire=strtotime($remote['expireAt']??'');
                $drift=$panelExpire ? abs($panelExpire - (int)$s['expires_at']) : 9999;
                $panelTraffic=(int)($remote['trafficLimitBytes']??-1);
                $panelDevices=(int)($remote['hwidDeviceLimit']??-1);
                $needsFix=$drift>60 || $panelTraffic!==(int)$s['traffic_bytes'] || $panelDevices!==(int)$s['devices'] || ($remote['status']??'')!=='ACTIVE';
                if($needsFix){
                    if($fix){
                        $this->provisioner->extend($s);
                        // Also ensure subscriptionUrl is stored
                        if(empty($s['subscription_url']) && !empty($remote['subscriptionUrl'])){
                            $this->db->execute('UPDATE subscriptions SET subscription_url=? WHERE id=?',[$remote['subscriptionUrl'],$s['id']]);
                        }
                        $report['fixed']++; $report['details'][]="$username: fixed drift={$drift}s";
                    } else {
                        $report['details'][]="$username: drift {$drift}s would fix";
                    }
                }
                // Ensure local remote_id/url are populated
                if($fix && (empty($s['remote_id']) || empty($s['subscription_url']))){
                    $this->db->execute('UPDATE subscriptions SET remote_id=?,subscription_url=? WHERE id=?',[(string)($remote['id']??$s['remote_id']),$remote['subscriptionUrl']??$s['subscription_url'],$s['id']]);
                }
            } catch (\Throwable $e) {
                $report['errors']++; $report['details'][]="$username: error ".get_class($e);
            }
        }
        return $report;
    }
}
