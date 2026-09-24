<?php
// Fault-test for Reconciler::reconcilePaidNoSubscription (§7.2):
// synthetic order paid + succeeded payment + NO subscription must produce a
// deduplicated 'payment.paid_no_subscription' (failed) operation; running the
// reconciler twice must NOT duplicate the op (dedup via correlation).
// Cleanup removes op events -> op -> payment -> order.
declare(strict_types=1);
use App\Infrastructure\Database;
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$r = new ReflectionClass(\App\Infrastructure\Reconciler::class);
$m = $r->getMethod('reconcilePaidNoSubscription'); $m->setAccessible(true);
$recon = new \App\Infrastructure\Reconciler($c);

$TID = 'faulttest-pns-' . substr(bin2hex(random_bytes(4)),0,8);
$uid = $db->one("SELECT id FROM users ORDER BY created_at LIMIT 1")['id']
    ?? (function() use($db,$TID){ $db->execute("INSERT INTO users(id,email,created_at,disabled) VALUES(?,?,?,0)",[Database::id(),$TID.'@test.local',time()]); return $db->one("SELECT id FROM users WHERE email=?",[$TID.'@test.local'])['id']; })();
$db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$TID,$uid,'basic','faulttest-'.$TID,19900,'RUB','Basic',30,0,3,'paid','faulttest','faulttest-'.$TID,time(),time()]);
$db->execute("INSERT INTO payments(id,order_id,user_id,provider,provider_payment_id,amount_minor,currency,status,created_at,paid_at,version) VALUES(?,?,?,?,?,?,?,?,?,?,?)",[Database::id(),$TID,$uid,'faulttest','faulttest-'.$TID,19900,'RUB','succeeded',time(),time(),1]);

echo "synthetic order: $TID (user $uid)\n";
$m->invoke($recon, $db);
$op = $db->one('SELECT * FROM operations WHERE correlation_id=?',['paid-no-subscription:'.$TID]);
echo 'op created: ' . ($op ? 'YES id='.$op['id'].' status='.$op['status'].' type='.$op['type'] : 'NO') . "\n";
$m->invoke($recon, $db); // reconciler runs every minute; second run must not duplicate
$evs = $db->all('SELECT type,status FROM operation_events WHERE operation_id=?', [$op['id']]);
echo "event types: " . json_encode(array_column($evs,'type')) . "\n";
echo "detected-events count: " . count(array_filter($evs, fn($e)=>$e['type']==='payment.paid_no_subscription.detected')) . " (expect 1, dedup)\n";

// cleanup events -> op -> payment -> order
$db->execute('DELETE FROM operation_events WHERE operation_id=?',[$op['id']]);
$db->execute('DELETE FROM operations WHERE id=?',[$op['id']]);
$db->execute('DELETE FROM payments WHERE provider_payment_id=?',['faulttest-'.$TID]);
$db->execute('DELETE FROM orders WHERE id=?',[$TID]);
echo "cleanup done\n";
echo "residual op: " . ($db->one('SELECT count(*) c FROM operations WHERE correlation_id=?',['paid-no-subscription:'.$TID])['c']??0) . "\n";
echo "residual order: " . ($db->one('SELECT count(*) c FROM orders WHERE id=?',[$TID])['c']??0) . "\n";
echo "residual payment: " . ($db->one('SELECT count(*) c FROM payments WHERE provider_payment_id=?',['faulttest-'.$TID])['c']??0) . "\n";
