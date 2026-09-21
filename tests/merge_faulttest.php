<?php
// Fault-test: SubscriptionMergeService::merge() — resource transfer + source
// cancellation. Asserts:
//  - traffic limit + purchased traffic summed into target
//  - remaining days of source added to target expiry
//  - device limits summed; unlimited (0) wins
//  - source transitioned cancelled/disabled, provisioning rows detached
//  - rejects: same sub, foreign sub, non-active sub
//  - idempotency: second merge attempt on the now-cancelled source is rejected
// Runs against the dev Container / prod DB with synthetic entities only
// (no real money, no provisioning). NO panel calls in dev (provisioner null).
declare(strict_types=1);
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;
use App\Infrastructure\Database;

$now = time();

// ---- helpers ----
function mruid(){ return 'u'.substr(bin2hex(random_bytes(6)),0,12); }
function mrmk(){ return Database::id(); }

$TID = 'merge-'.substr(bin2hex(random_bytes(4)),0,8);
$fail = '';
$createdSubs = [];

function mkUser($db,$uid,$email){ $db->execute('INSERT INTO users(id,email,balance_kopeks,disabled,created_at) VALUES(?,?,0,0,?)',[$uid,$email,time()]); }
function mkSub($db,$uid,$trafficGb,$purchasedGb,$devices,$daysLeft,$usedGb,$autoRenew=0){
    $sid=Database::id();
    $cnow=time();
    $bytes = ((int)$trafficGb==0) ? 0 : (((int)$trafficGb+(int)$purchasedGb)*1073741824);
    // 18 columns: id,order_id,user_id,status,expires_at,created_at,plan_id,
    // traffic_limit_gb,purchased_traffic_gb,device_limit,is_trial,start_date,
    // updated_at,lifecycle_status,traffic_used_gb,auto_renew,traffic_limit_bytes,version
    $db->execute(
      "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,purchased_traffic_gb,device_limit,is_trial,start_date,updated_at,lifecycle_status,traffic_used_gb,auto_renew,traffic_limit_bytes,version) VALUES(?,NULL,?,'active',?,?,NULL,?,?,?,0,?,?,'active',?,?,?,0)",
      [$sid,$uid,(int)$cnow+(int)$daysLeft*86400,(int)$cnow,(int)$trafficGb,(int)$purchasedGb,(int)$devices,(int)$cnow,(int)$cnow,(float)$usedGb,(int)$autoRenew,$bytes]
    );
    return $sid;
}

// ============ 1. happy path: resource transfer ============
$uid = mruid(); mkUser($db,$uid,$TID.'-a@test.local');
$srcTraffic=50; $srcPurchased=5; $srcDev=2; $srcDays=10; $srcUsed=3.5;
$tgtTraffic=20; $tgtPurchased=2; $tgtDev=1; $tgtDays=5;  $tgtUsed=1.0;
$src = mkSub($db,$uid,$srcTraffic,$srcPurchased,$srcDev,$srcDays,$srcUsed);
$tgt = mkSub($db,$uid,$tgtTraffic,$tgtPurchased,$tgtDev,$tgtDays,$tgtUsed);
$createdSubs=[$src,$tgt];

$tgtBefore = $db->one('SELECT * FROM subscriptions WHERE id=?',[$tgt]);
$merged = null;
try {
    $merged = $c->merger->merge($uid,$src,$tgt);
    echo "merge 1: OK\n";
} catch (\Throwable $e) {
    $fail .= "FAIL happy-path merge: ".get_class($e).': '.$e->getMessage()."\n";
}
if ($merged) {
    $m = $merged;
    $expLimit = ($srcTraffic===0||$tgtTraffic===0) ? 0 : ($tgtTraffic+$srcTraffic);
    $expPurch  = ($srcTraffic===0||$tgtTraffic===0) ? 0 : ($tgtPurchased+$srcPurchased);
    $expDev    = ($srcDev===0||$tgtDev===0) ? 0 : ($tgtDev+$srcDev);
    $expUsed   = $tgtUsed + $srcUsed;
    if ((int)$m['traffic_limit_gb'] !== $expLimit) $fail .= "FAIL traffic_limit_gb=".$m['traffic_limit_gb']." expect $expLimit\n";
    if ((int)$m['purchased_traffic_gb'] !== $expPurch) $fail .= "FAIL purchased=".$m['purchased_traffic_gb']." expect $expPurch\n";
    if ((int)$m['device_limit'] !== $expDev) $fail .= "FAIL devices=".$m['device_limit']." expect $expDev\n";
    if (abs((float)$m['traffic_used_gb'] - $expUsed) > 0.001) $fail .= "FAIL used=".$m['traffic_used_gb']." expect $expUsed\n";
    $expExpiry = $tgtBefore['expires_at'] + $srcDays*86400;
    if ((int)$m['expires_at'] !== $expExpiry) $fail .= "FAIL expiry=".$m['expires_at']." expect $expExpiry\n";
    echo "merged target: traffic={$m['traffic_limit_gb']} purch={$m['purchased_traffic_gb']} dev={$m['device_limit']} used={$m['traffic_used_gb']} expiry={$m['expires_at']}\n";
}
// source must be cancelled/disabled
$srcAfter = $db->one('SELECT status,lifecycle_status,auto_renew FROM subscriptions WHERE id=?',[$src]);
if (($srcAfter['status']??'') !== 'disabled') $fail .= "FAIL source status=".($srcAfter['status']??'?')." expect disabled\n";
if (($srcAfter['lifecycle_status']??'') !== 'cancelled') $fail .= "FAIL source lifecycle=".($srcAfter['lifecycle_status']??'?')." expect cancelled\n";
if ((int)($srcAfter['auto_renew']??1) !== 0) $fail .= "FAIL source auto_renew not disabled\n";
echo "source after: status={$srcAfter['status']} lifecycle={$srcAfter['lifecycle_status']} auto_renew={$srcAfter['auto_renew']}\n";

// ============ 2. reject: merge sub into itself ============
$uid2=mruid(); mkUser($db,$uid2,$TID.'-self@test.local');
$s = mkSub($db,$uid2,10,0,1,5,0);
$createdSubs[]=$s;
try { $c->merger->merge($uid2,$s,$s); $fail.="FAIL self-merge not rejected\n"; echo "self merge: NOT rejected (BUG)\n"; }
catch (\Throwable $e) { echo "self merge rejected: ".get_class($e)."\n"; if (!str_contains($e->getMessage(),'самой собой')) $fail.="FAIL self-merge wrong msg\n"; }

// ============ 3. reject: foreign sub ============
$uidO=mruid(); mkUser($db,$uidO,$TID.'-other@test.local');
$osrc = mkSub($db,$uidO,10,0,1,5,0);
$tgtMine = mkSub($db,$uid2,10,0,1,5,0);
$createdSubs[]=$osrc; $createdSubs[]=$tgtMine;
try { $c->merger->merge($uid2,$osrc,$tgtMine); $fail.="FAIL foreign source not rejected\n"; echo "foreign merge: NOT rejected (BUG)\n"; }
catch (\Throwable $e) { echo "foreign source rejected: ".get_class($e)."\n"; if (!str_contains($e->getMessage(),'другому аккаунту')) $fail.="FAIL foreign wrong msg\n"; }

// ============ 4. reject: non-active source/target ============
$uid3=mruid(); mkUser($db,$uid3,$TID.'-na@test.local');
// source cancelled (already removed flow)
$naSrc = mkSub($db,$uid3,10,0,1,5,0);
$db->execute("UPDATE subscriptions SET lifecycle_status='cancelled',status='disabled' WHERE id=?",[$naSrc]);
$naTgt = mkSub($db,$uid3,10,0,1,5,0);
$createdSubs[]=$naSrc; $createdSubs[]=$naTgt;
try { $c->merger->merge($uid3,$naSrc,$naTgt); $fail.="FAIL cancelled source accepted\n"; echo "cancelled source: NOT rejected (BUG)\n"; }
catch (\Throwable $e) { echo "cancelled source rejected: ".get_class($e)."\n"; }
// expired target
$uid4=mruid(); mkUser($db,$uid4,$TID.'-exp@test.local');
$okSrc = mkSub($db,$uid4,10,0,1,5,0);
$expTgt = mkSub($db,$uid4,10,0,1,-2,0); // -2 days => expired
$createdSubs[]=$okSrc; $createdSubs[]=$expTgt;
try { $c->merger->merge($uid4,$okSrc,$expTgt); $fail.="FAIL expired target accepted\n"; echo "expired target: NOT rejected (BUG)\n"; }
catch (\Throwable $e) { echo "expired target rejected: ".get_class($e)."\n"; if(!str_contains($e->getMessage(),'истекла')) $fail.="FAIL expired wrong msg\n"; }

// ============ 5. idempotency: re-merge on cancelled source rejected ============
$uid5=mruid(); mkUser($db,$uid5,$TID.'-idem@test.local');
$isrc = mkSub($db,$uid5,10,0,1,3,0);
$itgt = mkSub($db,$uid5,10,0,1,3,0);
$createdSubs[]=$isrc; $createdSubs[]=$itgt;
$c->merger->merge($uid5,$isrc,$itgt); // first merge succeeds
try {
    $c->merger->merge($uid5,$isrc,$itgt); // source now cancelled -> must reject
    $fail.="FAIL second merge on cancelled source succeeded\n";
    echo "idempotency(second merge): NOT rejected (BUG)\n";
} catch (\Throwable $e) {
    echo "idempotency rejected: ".get_class($e).": ".substr($e->getMessage(),0,60)."\n";
}
// and the target must NOT have been double-credited
$it = $db->one('SELECT traffic_limit_gb FROM subscriptions WHERE id=?',[$itgt]);
if ((int)$it['traffic_limit_gb'] !== 20) $fail.="FAIL idempotency double-credited target, traffic=".$it['traffic_limit_gb']." expect 20\n";

echo ($fail ? "=== RESULT: FAIL ===\n$fail" : "=== RESULT: PASS — merge transfers resources, cancels source, rejects illegal cases, idempotent ===\n");

// ---- cleanup (FK-correct): delete ops per-user, then subs, then users ----
$allTestEmails = [$TID.'-a@test.local',$TID.'-self@test.local',$TID.'-other@test.local',$TID.'-na@test.local',$TID.'-exp@test.local',$TID.'-idem@test.local'];
$cleanedUsers = [];
foreach ($allTestEmails as $em) { $u=$db->one('SELECT id FROM users WHERE email=?',[$em]); if ($u) $cleanedUsers[]=$u['id']; }
if ($cleanedUsers) {
    $ph=implode(',',array_fill(0,count($cleanedUsers),'?'));
    $db->execute("DELETE FROM operation_events WHERE operation_id IN (SELECT id FROM operations WHERE user_id IN ($ph))",$cleanedUsers);
    $db->execute("DELETE FROM operations WHERE user_id IN ($ph)",$cleanedUsers);
    $db->execute("DELETE FROM operation_events WHERE operation_id IN (SELECT id FROM operations WHERE subscription_id IN (SELECT id FROM subscriptions WHERE user_id IN ($ph)))",$cleanedUsers);
    $db->execute("DELETE FROM operations WHERE subscription_id IN (SELECT id FROM subscriptions WHERE user_id IN ($ph))",$cleanedUsers);
}
foreach ($createdSubs as $sid) {
    $uid0=$db->one('SELECT user_id FROM subscriptions WHERE id=?',[$sid])['user_id']??null;
    $db->execute('DELETE FROM provisioning_accounts WHERE subscription_id=?',[$sid]);
    $db->execute('DELETE FROM provisioning_operations WHERE subscription_id=?',[$sid]);
    $db->execute('DELETE FROM audit_log WHERE subject=?',[$sid]);
    $db->execute('DELETE FROM subscriptions WHERE id=?',[$sid]);
    if ($uid0 && !in_array($uid0,$cleanedUsers,true)) $cleanedUsers[]=$uid0;
}
if ($cleanedUsers) {
    $ph=implode(',',array_fill(0,count($cleanedUsers),'?'));
    $db->execute("DELETE FROM customer_timeline WHERE user_id IN ($ph)",$cleanedUsers);
    $db->execute("DELETE FROM users WHERE id IN ($ph)",$cleanedUsers);
}
echo "\ncleanup done\n";
