<?php
// Fault-test: clean daily auto-charge (BillingService::dailyChargeFromBalance)
// on the REAL "Сутки 4₽" tariff. Asserts:
//  - first run: ONE debit of exactly 400, expires extended by ~1 day, last_charge set
//  - second run within the period: no charge, no extend (idempotent / dedup)
//  - insufficient balance: false, no debit, no cancel (grace)
//  - cancel (auto_renew=0): stops charging; re-enable + period elapsed charges again
// Cleanup removes all synthetic rows.
declare(strict_types=1);
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$plan = $db->one("SELECT * FROM plans WHERE name='Сутки 4₽' AND active=1");
if (!$plan){ fwrite(STDERR,"FAIL: тариф Сутки 4₽ не найден\n"); exit(2); }
if ((int)$plan['duration_days']!==1){ fwrite(STDERR,"FAIL: период != 1 день\n"); exit(2); }
if ((int)$plan['price_minor']!==400){ fwrite(STDERR,"FAIL: цена != 400\n"); exit(2); }

$TID='daily-'.substr(bin2hex(random_bytes(4)),0,8);
$uid=\App\Infrastructure\Database::id();
$now=time(); $price=400; $bal=5000;
$db->execute('INSERT INTO users(id,email,balance_kopeks,disabled,created_at) VALUES(?,?,?,0,?)',[$uid,$TID.'@test.local',$bal,$now]);
$oid=\App\Infrastructure\Database::id();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
  [$oid,$uid,$plan['id'],'daily-orig-'.$TID,$price,'RUB','Сутки 4₽',1,$plan['traffic_bytes'],0,'fulfilled','ft-prov','daily-'.$oid,$now,$now]);
$sid=\App\Infrastructure\Database::id();
$expires=$now+86400; // paid for 24h already
$db->execute("INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,device_limit,lifecycle_status,starts_at,traffic_limit_bytes,auto_renew,renew_plan_id,renew_price_minor,last_daily_charge_at,renew_fail_count,updated_at) VALUES(?,?,?,?,'active',?,?,?,?,'active',?,?,1,?,?,NULL,0,?)",
  [$sid,$oid,$uid,$plan['id'],$expires,$now,10,0,$now,0,$plan['id'],$price,$now]);

$fail="";
// 1) first daily charge
$r1=$c->billing->dailyChargeFromBalance($sid);
$bal1=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
$sub1=$db->one('SELECT expires_at,last_daily_charge_at,status FROM subscriptions WHERE id=?',[$sid]);
$txs=$db->all("SELECT * FROM transactions WHERE user_id=? AND type='subscription_daily'",[$uid]);
echo "run1: returned=".($r1?'TRUE':'FALSE')." balance=$bal1 expect ".($bal-$price).", extends to ".$sub1['expires_at']."\n";
if($r1!==true)$fail.="FAIL run1 not true\n";
if((int)$bal1!==(int)$bal-$price)$fail.="FAIL balance=".$bal1." expect ".($bal-$price)."\n";
if(count($txs)!==1)$fail.="FAIL daily tx count=".count($txs)." expect 1 (no double debit)\n";
if((int)$sub1['expires_at']-$expires<86000||(int)$sub1['expires_at']-$expires>86600)$fail.="FAIL extends by ".(int)$sub1['expires_at']-$expires."s expect ~86400\n";
if(!$sub1['last_daily_charge_at'])$fail.="FAIL last_daily_charge_at not set\n";

// 2) second run within period -> no charge, no extend (idempotent)
$r2=$c->billing->dailyChargeFromBalance($sid);
$bal2=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
$sub2=$db->one('SELECT expires_at,last_daily_charge_at FROM subscriptions WHERE id=?',[$sid]);
echo "run2(в период): returned=".($r2?'TRUE':'FALSE')." balance=$bal2 (unchanged?), expires=".$sub2['expires_at']."\n";
if($r2!==false)$fail.="FAIL run2 should be false (not due)\n";
if((int)$bal2!==(int)$bal1)$fail.="FAIL run2 changed balance (double debit)\n";
if((int)$sub2['expires_at']!==(int)$sub1['expires_at'])$fail.="FAIL run2 extended again\n";

// 3) insufficient balance -> grace (no debit, no cancel); simulate time passed
$db->execute('UPDATE users SET balance_kopeks=100 WHERE id=?',[$uid]);
$db->execute('UPDATE subscriptions SET last_daily_charge_at=? WHERE id=?',[$now-90000,$sid]); // period elapsed
$r3=$c->billing->dailyChargeFromBalance($sid);
$bal3=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
$sub3=$db->one('SELECT status,auto_renew FROM subscriptions WHERE id=?',[$sid]);
echo "run3(нет средств): returned=".($r3?'TRUE':'FALSE')." balance=$bal3 (100 unchanged?), status=".$sub3['status']." auto_renew=".$sub3['auto_renew']."\n";
if($r3!==false)$fail.="FAIL run3 should be false (insufficient)\n";
if((int)$bal3!==100)$fail.="FAIL run3 debited with no money\n";
if($sub3['status']!=='active'||(int)$sub3['auto_renew']!==1)$fail.="FAIL run3 cancelled the sub (should stay in grace)\n";

// 4) cancel: auto_renew=0 stops charging even when due
$db->execute('UPDATE subscriptions SET auto_renew=0 WHERE id=?',[$sid]);
$db->execute('UPDATE users SET balance_kopeks=5000 WHERE id=?',[$uid]);
$db->execute('UPDATE subscriptions SET last_daily_charge_at=? WHERE id=?',[$now-90000,$sid]);
$r4=$c->billing->dailyChargeFromBalance($sid);
$bal4=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
echo "run4(отказ): returned=".($r4?'TRUE':'FALSE')." balance=$bal4 (5000 unchanged?)\n";
if($r4!==false)$fail.="FAIL cancelled sub still charged\n";
if((int)$bal4!==5000)$fail.="FAIL cancelled sub debited\n";

// 5) re-enable + period elapsed -> charges again (resume works)
$db->execute('UPDATE subscriptions SET auto_renew=1 WHERE id=?',[$sid]);
$r5=$c->billing->dailyChargeFromBalance($sid);
$bal5=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
echo "run5(включил снова): returned=".($r5?'TRUE':'FALSE')." balance=$bal5 expect 4600\n";
if($r5!==true)$fail.="FAIL re-enable not charged\n";
if((int)$bal5!==4600)$fail.="FAIL re-enable balance=".$bal5." expect 4600\n";

if($fail){echo "=== RESULT: FAIL ===\n$fail";}
else { echo "=== RESULT: PASS — daily loop: ровно 4₽/сутки, идемпотентно, grace при нехватке, отказ останавливает, включение возвращает ===\n"; }

// cleanup
$db->execute('DELETE FROM operation_events WHERE operation_id IN (SELECT id FROM operations WHERE user_id=? OR subscription_id=?)',[$uid,$sid]);
$db->execute('DELETE FROM operations WHERE user_id=? OR subscription_id=?',[$uid,$sid]);
$db->execute('DELETE FROM customer_timeline WHERE user_id=?',[$uid]);
$db->execute('DELETE FROM ledger_entries WHERE order_id=?',[$oid]);
$db->execute('DELETE FROM payment_receipts WHERE order_id=?',[$oid]);
$db->execute('DELETE FROM payments WHERE order_id=?',[$oid]);
$db->execute('DELETE FROM transactions WHERE user_id=?',[$uid]);
$db->execute('DELETE FROM subscriptions WHERE id=?',[$sid]);
$db->execute('DELETE FROM orders WHERE id=?',[$oid]);
$db->execute('DELETE FROM users WHERE id=?',[$uid]);
echo "cleanup done\n";
