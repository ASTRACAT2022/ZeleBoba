<?php
// Concurrency fault-test: the daily auto-charge must debit exactly ONCE even
// when several worker processes race on the same due subscription.
// Spawns N parallel CLI processes (mode=race) each calling dailyChargeFromBalance
// on the same "Сутки 4₽" subscription (last_daily_charge_at in the past).
// Asserts: balance dropped by exactly 400 once (NOT N*400), exactly 1
// subscription_daily transaction, expires extended exactly once.
// Cleanup removes all synthetic rows.
declare(strict_types=1);
$c = require __DIR__.'/../bootstrap.php';
$db = $c->db;

$plan = $db->one("SELECT * FROM plans WHERE name='Сутки 4₽' AND active=1");
if (!$plan){ fwrite(STDERR,"FAIL: тариф не найден\n"); exit(2); }
$price=(int)$plan['price_minor']; $bal=50000;
$TID='race-'.substr(bin2hex(random_bytes(4)),0,8);
$uid=\App\Infrastructure\Database::id();
$now=time();
$db->execute('INSERT INTO users(id,email,balance_kopeks,disabled,created_at) VALUES(?,?,?,0,?)',[$uid,$TID.'@test.local',$bal,$now]);
$oid=\App\Infrastructure\Database::id();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
  [$oid,$uid,$plan['id'],'race-orig-'.$TID,$price,'RUB','Сутки 4₽',1,$plan['traffic_bytes'],0,'fulfilled','ft-prov','race-'.$oid,$now,$now]);
$sid=\App\Infrastructure\Database::id();
$db->execute("INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,device_limit,lifecycle_status,starts_at,traffic_limit_bytes,auto_renew,renew_plan_id,renew_price_minor,last_daily_charge_at,renew_fail_count,updated_at) VALUES(?,?,?,?,'active',?,?,?,?,'active',?,?,1,?,?,?,0,?)",
  [$sid,$oid,$uid,$plan['id'],$now+86400*2,$now,10,0,$now,0,$plan['id'],$price,$now-90000,$now]);

$N=8;
// Children signal success purely by exit code (0 = charged, 7 = not due); no
// fragile pipe/file plumbing. The authoritative assertion is the DB state.
$inline='$c=require "bootstrap.php"; $r=$c->billing->dailyChargeFromBalance(\''.$sid.'\'); exit($r===true?0:7);';
$cmd='cd /app && '.PHP_BINARY.' -r '.escapeshellarg($inline);
$N=(int)$N;
$handles=[];
for($i=0;$i<$N;$i++){
    $proc=proc_open($cmd,[0=>['pipe','w'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['APP_ENV'=>'prod']);
    fclose($pipes[0]); fclose($pipes[1]);
    $handles[]=['proc'=>$proc,'err'=>$pipes[2]];
}
$errs=[];
foreach($handles as $i=>$h){ $errs[$i]=stream_get_contents($h['err']); fclose($h['err']); }
$codes=[];
foreach($handles as $i=>$h){ $codes[$i]=proc_close($h['proc']); }
$charged=count(array_filter($codes,fn($c)=>$c===0));

$balAfter=$db->one('SELECT balance_kopeks FROM users WHERE id=?',[$uid])['balance_kopeks'];
$txs=$db->all("SELECT * FROM transactions WHERE user_id=? AND type='subscription_daily'",[$uid]);
$sub=$db->one('SELECT expires_at,last_daily_charge_at FROM subscriptions WHERE id=?',[$sid]);

$fail="";
$expectDebit=$price; $expectBal=$bal-$price;
if($charged!==1)$fail.="FAIL charged=".$charged." (expect 1 of $N races)\n";
if((int)$balAfter!==$expectBal)$fail.="FAIL balance=".$balAfter." expect ".$expectBal." (double debit under race)\n";
if(count($txs)!==1)$fail.="FAIL daily tx count=".count($txs)." (expect 1)\n";
if((int)$sub['expires_at']<=(int)$sub['last_daily_charge_at']+86300)$fail.="FAIL not extended by ~1d\n";

echo "races=$N charged_once=".($charged==1?'TRUE':'NO')." balance=$balAfter expect $expectBal txs=".count($txs)."\n";
if($fail){echo "=== RESULT: FAIL ===\n$fail"; }
else { echo "=== RESULT: PASS — под гонкой N параллельных воркеров списано ровно 1 раз, без двойного ===\n"; }

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
