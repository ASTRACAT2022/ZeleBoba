<?php
// End-to-end: a real payment.event.process OUTBOX job must complete as 'done'
// via the worker loop (Outbox::runOne + Worker::handle). Regression for the
// ??-void dead-letter bug where the job was always marked failed/retried-8x.
declare(strict_types=1);
use App\Infrastructure\Database;
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;

$TID = 'faulttest-cyc-' . substr(bin2hex(random_bytes(4)),0,8);
$provider = 'faulttest';
$paymentId = $TID . ':paid';
$evId = Database::id();
$db->execute(
  'INSERT INTO payment_events(id,provider,provider_event_id,payment_id,payload,signature_valid,received_at,status,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?)',
  [$evId,$provider,'faulttest-'.$TID,$paymentId,'{"status":"paid","metadata":{"order_id":"","topup_id":""}}',1,time(),'pending',time()]
);
// Enqueue the outbox job just like PaymentService::processEvent() does.
$jobId = Database::id();
$db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at,correlation_id) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING',[
  $jobId,'payment.event.process','ft-'.$evId,json_encode(['event_id'=>$evId]),100,time(),time(),$evId
]);

echo "job $jobId -> event $evId\n";
$before = $db->one('SELECT status,attempts FROM outbox WHERE id=?',[$jobId]);
echo "before: status={$before['status']} attempts={$before['attempts']}\n";

// Run the real worker loop once (claims the job, dispatches to handle, marks done/failed).
$didWork = $c->outbox->runOne($c->worker->handle(...));
$after = $db->one('SELECT status,attempts,last_error FROM outbox WHERE id=?',[$jobId]);
echo "runOne worked: " . ($didWork?'yes':'no') . "\n";
echo "after: status={$after['status']} attempts={$after['attempts']} err={$after['last_error']}\n";
echo "event state: " . json_encode($db->one('SELECT status,attempts FROM payment_events WHERE id=?',[$evId])) . "\n";

if ($after['status'] !== 'done') { echo "FAIL: outbox job not 'done' (got {$after['status']})\n"; exit(2); }
if ($after['attempts'] != 1)     { echo "FAIL: attempts={$after['attempts']} (expect 1)\n"; exit(2); }
echo "PASS: payment.event.process outbox job completed as 'done' in one attempt\n";

// Cleanup
$db->execute('DELETE FROM outbox WHERE id=?',[$jobId]);
$db->execute('DELETE FROM payment_events WHERE id=?',[$evId]);
echo "cleanup done; residual job=0 residual event=0\n";
