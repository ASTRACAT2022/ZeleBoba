<?php
// Fault-test for Worker::handle() 'payment.event.process' arm.
// Regression: the arm used `$this->paymentService?->processEvent(...) ?? throw`
// and processEvent() returns VOID (null) on success, so the ?? throw ALWAYS
// fired -> every payment.event.process job retried 8x then went dead even
// though the event was really processed (see dead jobs 52580200/e16d4762/
// 007e4f45). Fixed to an explicit null-guard. This test drives a synthetic
// orphan event through the REAL Worker::handle() and asserts NO throw + the
// event reaches processed on attempt 1.
declare(strict_types=1);
use App\Infrastructure\Database;
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$TID = 'faulttest-wkr-' . substr(bin2hex(random_bytes(4)),0,8);
$provider = 'faulttest';
$paymentId = $TID . ':paid';
$evId = Database::id();
$db->execute(
  'INSERT INTO payment_events(id,provider,provider_event_id,payment_id,payload,signature_valid,received_at,status,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?)',
  [$evId,$provider,'faulttest-'.$TID,$paymentId,'{"status":"paid","metadata":{"order_id":"","topup_id":""}}',1,time(),'pending',time()]
);
echo "synthetic orphan event: $evId\n";

$thrown = null; $worked = null;
try {
  // Exact call the worker loop makes: outbox->runOne($worker->handle(...))
  // Here we invoke the handler directly with the topic/payload shape.
  $c->worker->handle('payment.event.process', ['event_id' => $evId]);
  $worked = 'handled';
} catch (\Throwable $e) {
  $thrown = get_class($e).':'.$e->getMessage();
  $worked = 'threw';
}

$row = $db->one('SELECT status,attempts FROM payment_events WHERE id=?',[$evId]);
echo "handler result: $worked\n";
echo 'thrown: ' . ($thrown ?? 'NONE') . "\n";
echo 'event status=' . ($row['status']??'?') . ' attempts=' . ($row['attempts']??'?') . "\n";
if ($thrown !== null)      { echo "FAIL: Worker::handle threw (old ??-void bug) -> $thrown\n"; exit(2); }
if ($worked !== 'handled') { echo "FAIL: handler did not run\n"; exit(2); }
if (($row['status']??'') !== 'processed') { echo "FAIL: event not processed ({$row['status']})\n"; exit(2); }
if ((int)($row['attempts']??0) !== 1) { echo "FAIL: attempts={$row['attempts']} (expect 1)\n"; exit(2); }
echo "PASS: Worker::handle processes payment.event.process and marks it done (no ??-void dead-letter)\n";

// Cleanup
$db->execute('DELETE FROM payment_events WHERE id=?',[$evId]);
$corr='cor_'.substr(hash('sha256',$provider.':'.$paymentId),0,40);
$ops=$db->all('SELECT id FROM operations WHERE correlation_id=?',[$corr]);
foreach($ops as $op){ $db->execute('DELETE FROM operation_events WHERE operation_id=?',[$op['id']]); }
foreach($ops as $op){ $db->execute('DELETE FROM operations WHERE id=?',[$op['id']]); }
echo "cleanup done; residual events=" . ($db->one('SELECT count(*) c FROM payment_events WHERE id=?',[$evId])['c']??0) . "\n";
