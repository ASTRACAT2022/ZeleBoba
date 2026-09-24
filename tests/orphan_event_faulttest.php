<?php
// Fault-test for orphan webhook-event guard in PaymentService::processEvent().
// A synthetic 'paid' event whose payment_id matches NO local order/topup and
// whose metadata carries no order_id/topup_id is an ORPHAN (no money to
// settle). The fix must ACK it immediately (status=processed, 1 attempt, no
// exception) instead of calling provider verify() -> RuntimeException -> 8
// retries -> dead (poisoning the worker queue, see dead job 52580200).
//
// Cleanup removes the synthetic event + any op rows it created.
declare(strict_types=1);
use App\Infrastructure\Database;
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$TID = 'faulttest-orphan-' . substr(bin2hex(random_bytes(4)),0,8);
$provider = 'faulttest';
$paymentId = $TID . ':paid'; // bogus suffix exactly like the real poisoned one

// 1) Insert the orphan event directly (signature_valid=1, pending).
$evId = \App\Infrastructure\Database::id();
$db->execute(
  'INSERT INTO payment_events(id,provider,provider_event_id,payment_id,payload,signature_valid,received_at,status,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?)',
  [$evId,$provider,'faulttest-'.$TID,$paymentId,'{"status":"paid","metadata":{"order_id":"","topup_id":""}}',1,time(),'pending',time()]
);
echo "synthetic orphan event: id=$evId payment=$paymentId\n";

// 2) Run the worker path exactly as Outbox::runOne does.
$outbox = $c->outbox;
$svc = $c->paymentService; // must have ->events wired (webhook replay guard)
if (!$svc) { echo "SKIP: no paymentService wired\n"; exit(0); }

$thrown = null;
try {
  $svc->processEvent($evId);
} catch (\Throwable $e) {
  $thrown = $e;
}

$row = $db->one('SELECT status,attempts,processing_error FROM payment_events WHERE id=?',[$evId]);
echo 'thrown: ' . ($thrown ? get_class($thrown).':'.$thrown->getMessage() : 'NONE') . "\n";
echo 'event status=' . ($row['status']??'?') . ' attempts=' . ($row['attempts']??'?') . ' err=' . ($row['processing_error']??'') . "\n";
if ($thrown !== null) { echo "FAIL: orphan event was NOT acked (threw)\n"; exit(2); }
if (($row['status']??'') !== 'processed') { echo "FAIL: orphan event not processed (got {$row['status']})\n"; exit(2); }
if ((int)($row['attempts']??0) > 1) { echo "FAIL: orphan event retried (attempts={$row['attempts']})\n"; exit(2); }
echo "PASS: orphan paidd event acked processed on attempt 1, no retries, no dead-letter\n";

// 3) Confirm it does NOT poison outbox (no pending payment.event.process spawned for it).
$out = $db->one("SELECT count(*) c FROM outbox WHERE payload LIKE '%{$evId}%' AND status IN ('pending','processing')");
echo 'poisoning outbox jobs for event: ' . ($out['c']??0) . " (expect 0)\n";

// Cleanup: event + any ops with its correlation.
$db->execute('DELETE FROM payment_events WHERE id=?',[$evId]);
$corr='cor_'.substr(hash('sha256',$provider.':'.$paymentId),0,40);
$ops=$db->all('SELECT id FROM operations WHERE correlation_id=?',[$corr]);
foreach($ops as $op){ $db->execute('DELETE FROM operation_events WHERE operation_id=?',[$op['id']]); }
foreach($ops as $op){ $db->execute('DELETE FROM operations WHERE id=?',[$op['id']]); }
echo "cleanup done\n";
echo 'residual events: ' . ($db->one('SELECT count(*) c FROM payment_events WHERE id=?',[$evId])['c']??0) . "\n";
echo 'residual ops: ' . count($ops) . "\n";
