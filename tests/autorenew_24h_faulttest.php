<?php
// Fault-test: auto-renew against the REAL "Сутки 4₽" tariff (4₽/24h).
// Uses bootstrap container pattern (same as autorenew_faulttest.php).
// Asserts: ONE wallet debit of exactly 400 kopecks, subscription extended
// by ~1 day, renewal order paid, idempotent on 2nd call. Cleanup wipes all.
declare(strict_types=1);
use App\Infrastructure\Database;
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$plan = $db->one("SELECT * FROM plans WHERE name='Сутки 4₽' AND active=1");
if (!$plan){ fwrite(STDERR,"FAIL: тариф Сутки 4₽ не найден/не активен\n"); exit(2); }
if ((int)$plan['duration_days']!==1){ fwrite(STDERR,"FAIL: период != 1 день\n"); exit(2); }
if ((int)$plan['price_minor']!==400){ fwrite(STDERR,"FAIL: цена != 400 копеек\n"); exit(2); }
if (!$plan['squad_uuid']){ fwrite(STDERR,"FAIL: у тарифа нет squad_uuid\n"); exit(2); }

$TID='autorenew24h-'.substr(bin2hex(random_bytes(4)),0,8);
$uid=Database::id();
$now=time();
$price=400; $bal=5000;
$db->execute('INSERT INTO users(id,email,balance_kopeks,disabled,created_at) VALUES(?,?,?,0,?)',[$uid,$TID.'@test.local',$bal,$now]);
$origOrderId=Database::id();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
  [$origOrderId,$uid,$plan['id'],'ft24h-orig-'.$TID,$price,'RUB','Сутки 4₽',1,$plan['traffic_bytes'],0,'fulfilled','ft-prov','ft24h-'.$origOrderId,$now-86400*2,$now-86400*2]);
$subId=Database::id();
$db->execute("INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,device_limit,lifecycle_status,starts_at,traffic_limit_bytes,auto_renew,renew_plan_id,renew_price_minor,renew_at,renew_fail_count,updated_at) VALUES(?,?,?,?,'active',?,?,?,?,'active',?,?,1,?,?,?,0,?)",
  [$subId,$origOrderId,$uid,$plan['id'],$now+7200,$now-86400,10,0,$now-86400,0,$plan['id'],$price,$now-50,$now]);

echo "tariff: ".$plan['name']." price=".$plan['price_minor']." day=${price} period=".$plan['duration_days']."д squad=".$plan['squad_uuid']."\n";
$before=$db->one('SELECT expires_at,renew_order_id FROM subscriptions WHERE id=?',[$subId]);
$r1=$c->billing->autoRenewFromBalance($subId);
$balAfter1=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
$after1=$db->one('SELECT expires_at,renew_order_id FROM subscriptions WHERE id=?',[$subId]);
$paid=$db->all("SELECT id,status,price_minor,provider_payment_id FROM orders WHERE user_id=? AND id<>? AND idempotency_key LIKE 'autorenew-balance%'",[$uid,$origOrderId]);
$wallet=$db->all("SELECT amount_kopeks FROM transactions WHERE user_id=? AND external_id IN (SELECT id::text FROM orders WHERE user_id=? AND idempotency_key LIKE 'autorenew-balance%')",[$uid,$uid]);

$fail="";
if($r1!==true)$fail.="FAIL run1 false\n";
if((int)$balAfter1!==(int)$bal-$price)$fail.="FAIL balance diff != $price (got ".(int)$bal-(int)$balAfter1.")\n";
if(count($paid)!==1)$fail.="FAIL renewal orders=".count($paid)."\n";
if($paid && (int)$paid[0]['price_minor']!==$price)$fail.="FAIL renewal order price=".($paid[0]['price_minor']??'-')." != $price\n";
if(!$paid || $paid[0]['status']!=='paid')$fail.="FAIL renewal not paid\n";
if(count($wallet)!==1)$fail.="FAIL wallet debits=".count($wallet)."\n";
$delta=(int)$after1['expires_at']-(int)$before['expires_at'];
if($delta<86000 || $delta>86600)$fail.="FAIL extended by $delta s (expect ~86400)\n";
// idempotency
$r2=$c->billing->autoRenewFromBalance($subId);
$balAfter2=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
if($r2!==false)$fail.="FAIL 2nd call true\n";
if((int)$balAfter2!==(int)$balAfter1)$fail.="FAIL double debit on 2nd call\n";

if($fail){echo "=== RESULT: FAIL ===\n$fail";exit(2);}
echo "=== RESULT: PASS — баланс 5000→4600 (ровно 400к=4₽), 1 списание, заказ paid, продлён на ~1 день, идемпотентно ===\n";

// cleanup (FK order: children before parents; provisioning_* before operations)
$oid=$paid[0]['id'];
$db->execute('DELETE FROM provisioning_operations WHERE subscription_id=?',[$subId]);
$db->execute('DELETE FROM provisioning_accounts WHERE subscription_id=?',[$subId]);
$db->execute('DELETE FROM operation_events WHERE operation_id IN (SELECT id FROM operations WHERE order_id=? OR subscription_id=? OR user_id=?)',[$oid,$subId,$uid]);
$db->execute('DELETE FROM operations WHERE order_id=? OR subscription_id=? OR user_id=?',[$oid,$subId,$uid]);
$db->execute('DELETE FROM customer_timeline WHERE user_id=?',[$uid]);
$db->execute('DELETE FROM ledger_entries WHERE order_id IN (?,?)',[$oid,$origOrderId]);
$db->execute('DELETE FROM payment_receipts WHERE order_id IN (?,?)',[$oid,$origOrderId]);
$db->execute('DELETE FROM payments WHERE order_id IN (?,?)',[$oid,$origOrderId]);
$db->execute('DELETE FROM transactions WHERE user_id=?',[$uid]);
$db->execute('DELETE FROM subscriptions WHERE id=? OR order_id=?',[$subId,$oid]);
$db->execute('DELETE FROM orders WHERE id IN (?,?) OR user_id=?',[$oid,$origOrderId,$uid]);
$db->execute('DELETE FROM users WHERE id=?',[$uid]);
echo "cleanup done\n";
