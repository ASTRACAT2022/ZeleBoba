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
        // NOTE: do NOT compute (traffic_limit_gb+purchased_traffic_gb)*1073741824 in SQL:
        // big plans overflow PostgreSQL int4 (e.g. 268200 GB -> integer out of range).
        // Compute traffic bytes in PHP below (same approach as Worker::subscription()).
        $subs=$this->db->all(
            "SELECT s.*,
                    COALESCE(o.squad_uuid,p.squad_uuid,'') AS squad_uuid
               FROM subscriptions s
               LEFT JOIN orders o ON o.id=s.order_id
               LEFT JOIN plans p ON p.id=s.plan_id
              WHERE s.status IN ('active','expired')
              ORDER BY s.created_at DESC
              LIMIT ?",
            [$limit]
        );
        foreach($subs as &$s){
            // Current subscription limits include purchased add-ons and merges;
            // the immutable order snapshot does not.
            $s['devices']=(int)($s['device_limit'] ?? 1);
            if ($s['devices']<0) $s['devices']=1;
            $s['traffic_bytes']=(int)$s['traffic_limit_gb']===0
                ? 0
                : ((int)$s['traffic_limit_gb']+(int)$s['purchased_traffic_gb'])*1073741824;
        }
        unset($s);
        foreach($subs as $s){
            $report['checked']++;
            $username='zb_'.$s['id'];
            $panelId=(int)($s['remnawave_id']??0);
            if($panelId<=0 && ctype_digit((string)($s['remote_id']??'')))$panelId=(int)$s['remote_id'];
            $hasKnownRemote=$panelId>0 || !empty($s['remote_id']);
            try {
                $remote=$panelId>0?$this->provisioner->fetchById($panelId):$this->provisioner->resolve($s);
                if(!$remote && $panelId>0){
                    $canonical='zb_'.$s['id'];
                    $candidate=$this->provisioner->fetch($canonical);
                    if($candidate && ($candidate['username']??null)===$canonical)$remote=$candidate;
                }
                if($s['status']==='expired'){
                    if($remote && ($remote['status']??'')==='ACTIVE'){
                        if($fix){ $this->provisioner->disableById((int)$remote['id']); $report['disabled']++; $report['details'][]="$username: disabled expired"; }
                        else { $report['details'][]="$username: would disable expired"; }
                    }
                    continue;
                }
                // active
                if(!$remote){
                    $report['missing']++;
                    if($hasKnownRemote){$report['errors']++;$report['details'][]="$username: known panel account missing; manual review required";continue;}
                    if($fix){
                        $result=$this->provisioner->provision($s);
                        $this->db->execute('UPDATE subscriptions SET remote_id=?,subscription_url=? WHERE id=?',[$result['id'],$result['url'],$s['id']]);
                        $this->markActive($s['id'],(string)$result['id']);
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
                        $remoteId=(int)($remote['id']??0);
                        if($remoteId<=0)throw new \RuntimeException('Remnawave user id missing');
                        $this->provisioner->updateById($remoteId,(int)$s['traffic_bytes'],(int)$s['devices'],(int)$s['expires_at']);
                        $verified=$this->provisioner->fetchById($remoteId);
                        $verifiedExpiry=is_array($verified)?strtotime((string)($verified['expireAt']??'')):false;
                        if(!$verifiedExpiry || abs($verifiedExpiry-(int)$s['expires_at'])>60 || ($verified['status']??null)!=='ACTIVE')throw new \RuntimeException('Remnawave sync readback failed');
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
                if($fix && (int)($remote['id']??0)>0 && $panelId>0 && $panelId!==(int)$remote['id']){
                    $this->db->execute('UPDATE subscriptions SET remote_id=?,remnawave_id=? WHERE id=?',[(string)$remote['id'],(int)$remote['id'],$s['id']]);
                }
                if ($fix) $this->markActive($s['id'],(string)($remote['id']??$s['remote_id']));
            } catch (\Throwable $e) {
                $report['errors']++; $report['details'][]="$username: error ".get_class($e);
            }
        }
        return $report;
    }
    private function markActive(string $subscriptionId,string $externalId): void
    {
        $this->db->execute("UPDATE provisioning_accounts SET state='active',external_user_id=?,last_synced_at=?,last_error=NULL,updated_at=? WHERE subscription_id=?",[$externalId,time(),time(),$subscriptionId]);
    }

    /**
     * Reverse import: subscriptions that exist on the Remnawave panel (ACTIVE,
     * with a telegram_id) but are missing locally in ZeleBoba get created and
     * attached to the local account that owns that telegram. This fixes the
     * "в ЛК видна только одна подписка" class of bug for legacy/secondary subs
     * that never had an order_id and so were skipped by the forward sync.
     * Only subs whose telegram_id resolves to a local user are imported (the
     * ones with no local account are skipped, not created).
     */
    public function importMissing(int $limit = 200, bool $fix = true): array
    {
        $known = [];
        foreach ($this->db->all('SELECT remnawave_id,remote_id FROM subscriptions WHERE remnawave_id IS NOT NULL OR remote_id IS NOT NULL') as $r) {
            $panelId = (int)($r['remnawave_id'] ?? 0);
            if ($panelId > 0) $known[$panelId] = true;
            $remoteId = (string)($r['remote_id'] ?? '');
            if (ctype_digit($remoteId) && (int)$remoteId > 0) $known[(int)$remoteId] = true;
        }
        $byTg = [];
        foreach ($this->db->all('SELECT id,telegram_id FROM users WHERE telegram_id IS NOT NULL') as $u) {
            $byTg[(string)$u['telegram_id']] = $u['id'];
        }
        $report = ['scanned' => 0, 'imported' => 0, 'matched_tg' => 0, 'skipped_no_account' => 0, 'errors' => 0, 'details' => []];
        foreach ($this->provisioner->listActiveUsers($limit) as $u) {
            $report['scanned']++;
            $rnId = (int)($u['id'] ?? 0);
            $tg = (string)($u['telegramId'] ?? $u['telegram_id'] ?? $u['telegram'] ?? '');
            if ($rnId <= 0) { $report['errors']++; continue; }
            if (isset($known[$rnId])) continue; // already present
            if ($tg === '' || !isset($byTg[$tg])) { $report['skipped_no_account']++; continue; }
            $userId = $byTg[$tg];
            $report['matched_tg']++;
            if (!$fix) { $report['details'][] = "id=$rnId tg=$tg would import"; continue; }
            try {
                $exp = strtotime((string)($u['expireAt'] ?? ''));
                if (!$exp || $exp <= 0) { $report['errors']++; continue; }
                $tb = (int)($u['trafficLimitBytes'] ?? 0);
                $dev = (int)($u['hwidDeviceLimit'] ?? 1); if ($dev < 0) $dev = 1;
                $now = time();
                $id = Database::id();
                $su = (string)($u['shortUuid'] ?? '');
                $url = (string)($u['subscriptionUrl'] ?? '');
                $vu = (string)($u['vlessUuid'] ?? '');
                $this->db->transaction(function() use ($id,$userId,$exp,$now,$tb,$dev,$rnId,$vu,$su,$url) {
                    $this->db->execute(
                        "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,purchased_traffic_gb,device_limit,is_trial,autopay_enabled,is_daily_paused,modem_enabled,traffic_used_gb,auto_renew,renew_fail_count,lifecycle_status,version,remote_id,remnawave_id,remnawave_uuid,remnawave_short_uuid,subscription_url,start_date,traffic_limit_bytes) VALUES(?,NULL,?,'active',?,?,?,0,?,0,0,0,0,0,0,0,'active',0,?,?,?,?,?,?,?)",
                        [$id, $userId, $exp, $now, intdiv($tb,1073741824), $dev, (string)$rnId, $rnId, $vu, $su, $url, $now, $tb]
                    );
                    $this->db->execute("INSERT INTO provisioning_accounts(id,subscription_id,provider,external_user_id,state,created_at,updated_at) VALUES(?,?,'remnawave',?,'active',?,?)", [Database::id(), $id, (string)$rnId, $now, $now]);
                });
                $known[$rnId] = true;
                $report['imported']++;
                $report['details'][] = "id=$rnId imported";
            } catch (\Throwable $e) {
                $report['errors']++;
                $report['details'][] = "id=$rnId error " . get_class($e);
            }
        }
        return $report;
    }
}
