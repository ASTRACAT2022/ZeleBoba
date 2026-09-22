<?php
// Fault-test: per-tariff autorenew_days_before override in BillingService::setAutoRenew.
// Uses the real "3 месяца" plan (duration 90). Temporarily sets its override to
// 7 days, enables auto-renew on a synthetic subscription, asserts renew_at is
// scheduled 7 days before expiry (not the global 3-day default), then resets to NULL.
// Cleanup removes synthetic rows and restores the plan override.
declare(strict_types=1);
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$plan = $db->one("SELECT * FROM plans WHERE name='3 месяца' AND active=1");
if (!$plan){ fwrite(STDERR,"FAIL: тариф 3 месяца не найден\n"); exit(2); }
$planId=$plan['id'];

$TID='arpd-'.substr(bin2hex(random_bytes(4)),0,8);
$uid=\App\Infrastructure\Database::id();
$now=time();
$price=(int)$plan['price_minor'];
$db->execute('INSERT INTO users(id,email,balance_kopeks,disabled,created_at) VALUES(?,?,?,0,?)',[$uid,$TID.'@test.local',500000,$now]);
$origOrderId=\App\Infrastructure\Database::id();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
  [$origOrderId,$uid,$planId,'arpd-orig-'.$TID,$price,'RUB',$plan['name'],(int)$plan['duration_days'],$plan['traffic_bytes'],$plan['devices'],'fulfilled','ft-prov','arpd-'.$origOrderId,$now-86400*100,$now-86400*100]);
$subId=\App\Infrastructure\Database::id();
// active, expires in future, auto_renew currently off
$expires=$now+86400*40;
$db->execute("INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,device_limit,lifecycle_status,starts_at,traffic_limit_bytes,auto_renew,renew_fail_count,updated_at) VALUES(?,?,?,?,'active',?,?,?,?,'active',?,?,0,0,?)",
  [$subId,$origOrderId,$uid,$planId,$expires,$now,30,3,$now,$plan['traffic_bytes'],$now]);

$fail="";
// --- set override to 7 days ---
$db->execute('UPDATE plans SET autorenew_days_before=7 WHERE id=?',[$planId]);
$db->execute('UPDATE plans SET autorenew_max_fails=7 WHERE id=?',[$planId]);
try {
    $c->billing->setAutoRenew($uid,$subId,true);
    $sub=$db->one('SELECT renew_at,auto_renew FROM subscriptions WHERE id=?',[$subId]);
    $lead=$expires-(int)$sub['renew_at'];
    $leadH=round($lead/86400,1);
    echo "override=7д: renew_at lead = {$lead}с (~{$leadH}д); global would be 3д.\n";
    if($lead<6*86400||$lead>8*86400)$fail.="FAIL lead=$leadH д, ожидалось ~7д (override не применён)\n";
    if((int)$sub['auto_renew']!==1)$fail.="FAIL auto_renew не включился\n";
} catch (\Throwable $e){
    $fail.="FAIL setAutoRenew: ".$e->getMessage()."\n";
}
// --- reset override to NULL (global default), re-run on a fresh sub ---
$db->execute('UPDATE plans SET autorenew_days_before=NULL,autorenew_max_fails=NULL WHERE id=?',[$planId]);
$subId2=\App\Infrastructure\Database::id();
$origOrderId2=\App\Infrastructure\Database::id();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
  [$origOrderId2,$uid,$planId,'arpd-orig2-'.$TID,$price,'RUB',$plan['name'],(int)$plan['duration_days'],$plan['traffic_bytes'],$plan['devices'],'fulfilled','ft-prov','arpd2-'.$origOrderId2,$now-86400*100,$now-86400*100]);
$expires2=$now+86400*40;
$db->execute("INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,device_limit,lifecycle_status,starts_at,traffic_limit_bytes,auto_renew,renew_fail_count,updated_at) VALUES(?,?,?,?,'active',?,?,?,?,'active',?,?,0,0,?)",
  [$subId2,$origOrderId2,$uid,$planId,$expires2,$now,30,3,$now,$plan['traffic_bytes'],$now]);
try {
    $c->billing->setAutoRenew($uid,$subId2,true);
    $sub2=$db->one('SELECT renew_at,auto_renew FROM subscriptions WHERE id=?',[$subId2]);
    $lead2=$expires2-(int)$sub2['renew_at'];
    echo "override=NULL: renew_at lead = {$lead2}с (~".round($lead2/86400,1)."д); ожидается глобальный 3д.\n";
    if($lead2<2*86400||$lead2>4*86400)$fail.="FAIL lead2=".round($lead2/86400,1)."д, ожидалось ~3д (глобальный не применён)\n";
} catch (\Throwable $e){
    $fail.="FAIL setAutoRenew(no override): ".$e->getMessage()."\n";
}

if($fail){echo "=== RESULT: FAIL ===\n$fail"; $db->execute('UPDATE plans SET autorenew_days_before=NULL,autorenew_max_fails=NULL WHERE id=?',[$planId]); exit(2);}
echo "=== RESULT: PASS — per-tariff override работает (7д), NULL использует глобальный (3д) ===\n";

// cleanup
$db->execute('DELETE FROM provisioning_operations WHERE subscription_id IN (?,?)',[$subId,$subId2]);
$db->execute('DELETE FROM provisioning_accounts WHERE subscription_id IN (?,?)',[$subId,$subId2]);
foreach([$subId,$subId2] as $sid){
  $db->execute('DELETE FROM operation_events WHERE operation_id IN (SELECT id FROM operations WHERE user_id=? OR subscription_id=?)',[$uid,$sid]);
  $db->execute('DELETE FROM operations WHERE user_id=? OR subscription_id=?',[$uid,$sid]);
}
$db->execute('DELETE FROM customer_timeline WHERE user_id=?',[$uid]);
$db->execute('DELETE FROM order_items WHERE order_id IN (?,?) OR subscription_id IN (?,?)',[$origOrderId,$origOrderId2,$subId,$subId2]);
$db->execute('UPDATE orders SET renewal_subscription_id=NULL WHERE id IN (?,?) OR user_id=?',[$origOrderId,$origOrderId2,$uid]);
$db->execute('DELETE FROM ledger_entries WHERE order_id IN (?,?)',[$origOrderId,$origOrderId2]);
$db->execute('DELETE FROM payment_receipts WHERE order_id IN (?,?)',[$origOrderId,$origOrderId2]);
$db->execute('DELETE FROM payments WHERE order_id IN (?,?)',[$origOrderId,$origOrderId2]);
$db->execute('DELETE FROM wallet_ledger_entries WHERE transaction_id IN (SELECT id FROM transactions WHERE user_id=?)',[$uid]);
$db->execute('DELETE FROM transactions WHERE user_id=?',[$uid]);
$db->execute('DELETE FROM subscriptions WHERE id IN (?,?) OR user_id=?',[$subId,$subId2,$uid]);
$db->execute('DELETE FROM orders WHERE id IN (?,?) OR user_id=?',[$origOrderId,$origOrderId2,$uid]);
$db->execute('DELETE FROM users WHERE id=?',[$uid]);
$db->execute('UPDATE plans SET autorenew_days_before=NULL,autorenew_max_fails=NULL WHERE id=?',[$planId]);
echo "cleanup done\n";
