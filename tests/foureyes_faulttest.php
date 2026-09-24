<?php
// Fault-test: FourEyes solo-admin simplification (ТЗ). 1 admin -> guard() returns
// null (proceeds, no deadlock). 2+ admins -> guard() returns a pending queue id
// (strict four-eyes). Cleanup removes created approval_queue rows.
declare(strict_types=1);
$c = require __DIR__ . '/../bootstrap.php';
$db = $c->db;
$fourEyes = new \App\Infrastructure\FourEyes($db);

$pass=0;$fail=0;
function check(bool $cond,string $label):void{global $pass,$fail;if($cond){$pass++;echo "PASS $label\n";}else{$fail++;echo "FAIL $label\n";}}

$adminsBefore = $db->one("SELECT count(*) c FROM users WHERE role='admin'")['c']??0;
$baseAdmin = $db->one("SELECT id FROM users WHERE role='admin' ORDER BY created_at LIMIT 1");
$actor = $baseAdmin['id'];

$created = [];
try {
    // 1 admin (current live state) -> guard must NOT deadlock
    $r1 = $fourEyes->guard('kill_switch.toggle', $actor, ['switch'=>'freeze'], 'faulttest');
    check($r1 === null, '1 admin -> guard returns null (proceeds, no deadlock): '.($r1??'null'));
    check(!$fourEyes->requiresApproval('not_sensitive'), 'non-sensitive action -> not required');

    // Simulate a 2nd admin: grant a temporary admin, then re-check -> strict gate
    $db->execute("INSERT INTO users(id,email,created_at,disabled) VALUES(?,?,?,0) ON CONFLICT DO NOTHING",
        [\App\Infrastructure\Database::id(), 'faulttest-admin2@test.local', time()]);
    $a2 = $db->one("SELECT id FROM users WHERE email='faulttest-admin2@test.local'")['id'];
    $db->execute("UPDATE users SET role='admin' WHERE id=?", [$a2]);
    $adminsAfter = $db->one("SELECT count(*) c FROM users WHERE role='admin'")['c']??0;
    check((int)$adminsAfter === 2, '2 admins present: '.$adminsAfter);

    $r2 = $fourEyes->guard('backup.restore', $actor, ['snapshot'=>'x'], 'faulttest');
    check($r2 !== null && is_string($r2), '2 admins -> guard returns pending id (strict gate): '.($r2??'null'));
    if (is_string($r2)) {
        $created[] = $r2;
        $row = $fourEyes->get($r2);
        check($row && $row['status']==='pending' && $row['action']==='backup.restore', 'queue row pending, action backup.restore');

        // requester == reviewer must still be rejected (approve() guard intact)
        $threw=false;
        try { $fourEyes->approve($r2, $actor); } catch (\App\Billing\BillingError $e) { $threw=true; }
        check($threw, 'approve by requester rejected (actor==reviewer guard intact)');

        // second admin CAN approve
        $fourEyes->approve($r2, $a2);
        $row2 = $fourEyes->get($r2);
        check($row2['status']==='approved' && $row2['decided_by']===$a2, '2nd admin approves OK');
    }
} finally {
    // cleanup: approval_queue rows then the temp admin user
    foreach ($created as $id) $db->execute('DELETE FROM approval_queue WHERE id=?', [$id]);
    $db->execute("DELETE FROM users WHERE email='faulttest-admin2@test.local'");
    // restore admin role count to original (already restored by deleting a2)
    echo "cleanup: created=".count($created).", residual approvals=".
        ($db->one("SELECT count(*) c FROM approval_queue WHERE actor=? OR payload LIKE ?", [$actor,'%faulttest%'])['c']??0)."\n";
}
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail===0?0:1);
