<?php
// Fault-test for wallet-backed daily auto-renewal (commit 516b146).
// Verifies the money path invariant: ONE wallet debit, order created+settled
// (paid), subscription extended, idempotency (2nd call = no double debit),
// and insufficient-funds = recoverable false (no debit, retry kept).
// Cleanup removes all synthetic rows.
declare(strict_types=1);
use App\Infrastructure\Database;
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$TID = 'faulttest-ar-' . substr(bin2hex(random_bytes(4)),0,8);
$uid = Database::id();
$TID_PLAN = 'ft-plan-' . substr($TID,-6);
$now = time();

// --- synthetic user with funds ---
$planPrice = 19900; // 199.00 RUB in kopeks
$bal = $planPrice + 100; // enough
$db->execute('INSERT INTO users(id,email,balance_kopeks,disabled,created_at) VALUES(?,?,?,0,?)',[$uid,$TID.'@test.local',$bal,$now]);
// --- synthetic plan ---
$db->execute('INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES(?,?,?,?,?,?,?,1)',
  [$TID_PLAN,'FT AutoRenew',$planPrice,'RUB',30,0,3]);
// --- original order (subscription's source order) ---
$origOrderId=Database::id();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
  [$origOrderId,$uid,$TID_PLAN,'ft-orig-'.$TID,$planPrice,'RUB','FT Plan',30,0,3,'fulfilled','ft-prov','ft-prov-'.$origOrderId,time()-86400*40,time()-86400*40]);
// --- original subscription now due ---
$subId=Database::id();
$db->execute("INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,device_limit,lifecycle_status,starts_at,traffic_limit_bytes,auto_renew,renew_plan_id,renew_price_minor,renew_at,renew_fail_count,updated_at) VALUES(?,?,?,?,'active',?,?,?,?,'active',?,?,1,?,?,?,0,?)",
  [$subId,$origOrderId,$uid,$TID_PLAN,$now+7200,$now-86400*10,10,3,$now-86400*10,0,$TID_PLAN,$planPrice,$now-50,$now]);

echo "synthetic: user=$uid plan=$TID_PLAN sub=$subId bal_before=$bal\n";
$balNow=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
$before=$db->one('SELECT expires_at,renew_order_id FROM subscriptions WHERE id=?',[$subId]);

$r1=$c->billing->autoRenewFromBalance($subId);
$balAfter1=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
$after1=$db->one('SELECT expires_at,renew_order_id,renew_fail_count FROM subscriptions WHERE id=?',[$subId]);
$paidOrders=$db->all("SELECT id,status,provider_payment_id FROM orders WHERE user_id=? AND id<>? AND idempotency_key LIKE 'autorenew-balance%'",[$uid,$origOrderId]);
$debited=$db->all("SELECT amount_kopeks,payment_method FROM transactions WHERE user_id=? AND type='subscription_renewal' AND payment_method='balance'",[$uid]);
// wallet debit also writes a transaction; plus settle() skips its own tx for balance_
$walletDebits=$db->all("SELECT amount_kopeks FROM transactions WHERE user_id=? AND external_id IN (SELECT CAST(id AS TEXT) FROM orders WHERE user_id=? AND idempotency_key LIKE 'autorenew-balance%')",[$uid,$uid]);

echo "run1: returned=".($r1?'TRUE':'FALSE')."\n";
echo "balance: $bal -> $balAfter1 (diff ".(int)$bal-(int)$balAfter1.", expect $planPrice)\n";
echo "sub: expires ".$before['expires_at']." -> ".$after1['expires_at']." (extended) renew_order_id=".var_export($after1['renew_order_id'],true)."\n";
echo "paid renewal orders count: ".count($paidOrders)." (expect 1)\n";
if($paidOrders){ echo "  order status=".$paidOrders[0]['status']." payment=".$paidOrders[0]['provider_payment_id']."\n"; }
echo "wallet renewal transactions: ".count($walletDebits)." (expect 1, no double debit)\n";

// --- idempotency: 2nd call must not double-debit ---
$r2=$c->billing->autoRenewFromBalance($subId);
$balAfter2=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
echo "run2: returned=".($r2?'TRUE':'FALSE')." balance_after2=$balAfter2 (diff from run1: ".(int)$balAfter1-(int)$balAfter2.")\n";

$fail="";
if(!$r1)$fail.="FAIL run1 returned false (should be true)\n";
if((int)$balAfter1!==(int)$bal-$planPrice)$fail.="FAIL balance diff != $planPrice (got ".(int)$bal-(int)$balAfter1.")\n";
if(count($paidOrders)!==1)$fail.="FAIL renewal order count=".count($paidOrders)."\n";
if(!$paidOrders || $paidOrders[0]['status']!=='paid')$fail.="FAIL renewal order not paid\n";
if((int)$after1['expires_at']<=(int)$before['expires_at'])$fail.="FAIL subscription not extended\n";
if((int)$balAfter2!==(int)$balAfter1)$fail.="FAIL double debit on 2nd call (balance changed)\n";
if($r2===true)$fail.="FAIL 2nd call returned true (idempotency broken)\n";

if($fail){echo "=== RESULT: FAIL ===\n$fail";exit(2);}
echo "=== RESULT: PASS (single debit, order paid, extended, idempotent) ===\n";

// --- insufficient funds path ---
$uid2=Database::id();
$db->execute('INSERT INTO users(id,email,balance_kopeks,disabled,created_at) VALUES(?,?,100,0,?)',[$uid2,$TID.'-b2@test.local',$now]);
$origOrderId2=Database::id();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
  [$origOrderId2,$uid2,$TID_PLAN,'ft-orig2-'.$TID,$planPrice,'RUB','FT Plan',30,0,3,'fulfilled','ft-prov','ft-prov2-'.$origOrderId2,time()-86400*40,time()-86400*40]);
$subId2=Database::id();
$db->execute("INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,device_limit,lifecycle_status,starts_at,traffic_limit_bytes,auto_renew,renew_plan_id,renew_price_minor,renew_at,renew_fail_count,updated_at) VALUES(?,?,?,?,'active',?,?,?,?,'active',?,?,1,?,?,?,0,?)",
  [$subId2,$origOrderId2,$uid2,$TID_PLAN,$now+7200,$now-86400*10,10,3,$now-86400*10,0,$TID_PLAN,$planPrice,$now-50,$now]);
$r3=$c->billing->autoRenewFromBalance($subId2);
$sub2=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid2]);
$b2=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid2])['balance_kopeks'];
$r3Orders=$db->all("SELECT id FROM orders WHERE user_id=? AND idempotency_key LIKE 'autorenew-balance%'",[$uid2]);
echo "insufficient-funds: returned=".($r3?'TRUE':'FALSE')." balance=$b2 (unchanged?) orders=".count($r3Orders)."\n";
if($r3===true || (int)$b2!==100 || count($r3Orders)!==0){ echo "=== RESULT: FAIL (insufficient funds mishandled) ===\n"; exit(2); }
echo "=== RESULT: PASS (insufficient funds -> false, no debit, no order) ===\n";

// --- cleanup ---
$oid=$paidOrders[0]['id'];
$db->execute('DELETE FROM provisioning_operations WHERE subscription_id IN (?,?)',[$subId,$subId2]);
$db->execute('DELETE FROM provisioning_accounts WHERE subscription_id IN (?,?)',[$subId,$subId2]);
$db->execute('DELETE FROM operation_events WHERE operation_id IN (SELECT id FROM operations WHERE order_id=? OR subscription_id=? OR correlation_id LIKE ?)',[$oid,$subId,'%'.$origOrderId.'%']);
$db->execute('DELETE FROM operations WHERE order_id=? OR subscription_id=?',[$oid,$subId]);
$db->execute('DELETE FROM operation_events WHERE operation_id IN (SELECT id FROM operations WHERE order_id IN (?,?,?) OR user_id IN (?,?))',[$oid,$origOrderId,$origOrderId2,$uid,$uid2]);
$db->execute('DELETE FROM operations WHERE order_id IN (?,?,?) OR user_id IN (?,?)',[$oid,$origOrderId,$origOrderId2,$uid,$uid2]);
$db->execute('DELETE FROM customer_timeline WHERE user_id IN (?,?)',[$uid,$uid2]);
$db->execute('DELETE FROM order_items WHERE order_id IN (?,?,?) OR subscription_id IN (?,?)',[$oid,$origOrderId,$origOrderId2,$subId,$subId2]);
$db->execute('UPDATE orders SET renewal_subscription_id=NULL WHERE id IN (?,?,?) OR user_id IN (?,?)',[$oid,$origOrderId,$origOrderId2,$uid,$uid2]);
$db->execute('DELETE FROM ledger_entries WHERE order_id IN (?,?,?)',[$oid,$origOrderId,$origOrderId2]);
$db->execute('DELETE FROM payment_receipts WHERE order_id IN (?,?,?)',[$oid,$origOrderId,$origOrderId2]);
$db->execute('DELETE FROM payments WHERE order_id IN (?,?,?)',[$oid,$origOrderId,$origOrderId2]);
$db->execute('DELETE FROM wallet_ledger_entries WHERE transaction_id IN (SELECT id FROM transactions WHERE user_id IN (?,?))',[$uid,$uid2]);
$db->execute('DELETE FROM transactions WHERE user_id IN (?,?)',[$uid,$uid2]);
$db->execute('DELETE FROM subscriptions WHERE id IN (?,?) OR order_id IN (?,?)',[$subId,$subId2,$oid,$origOrderId2]);
$db->execute('DELETE FROM orders WHERE id IN (?,?,?) OR user_id IN (?,?)',[$oid,$origOrderId,$origOrderId2,$uid,$uid2]);
$db->execute('DELETE FROM plans WHERE id=?',[$TID_PLAN]);
$db->execute('DELETE FROM users WHERE id IN (?,?)',[$uid,$uid2]);
echo "cleanup done\n";
