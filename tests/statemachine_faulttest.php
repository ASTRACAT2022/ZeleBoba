<?php
// Standalone fault-test for StateMachine (§6) — no PHPUnit dependency.
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use App\Infrastructure\StateMachine;
use App\Billing\BillingError;

$pass=0; $fail=0;
function check(bool $cond, string $label): void { global $pass,$fail; if ($cond) { $pass++; echo "PASS $label\n"; } else { $fail++; echo "FAIL $label\n"; } }

// ORDER
check(StateMachine::can('order','pending','paid'),'order pending->paid');
check(StateMachine::can('order','pending','canceled'),'order pending->canceled');
check(StateMachine::can('order','paid','fulfilled'),'order paid->fulfilled');
check(!StateMachine::can('order','paid','paid'),'order paid->paid blocked (no double-settle)');
check(!StateMachine::can('order','canceled','paid'),'order canceled->paid blocked (no zombie revive)');
check(!StateMachine::can('order','fulfilled','paid'),'order fulfilled->paid blocked');

// TOPUP
check(StateMachine::can('topup','pending','paid'),'topup pending->paid');
check(StateMachine::can('topup','pending','canceled'),'topup pending->canceled');
check(!StateMachine::can('topup','paid','paid'),'topup paid->paid blocked');

// PAYMENT
check(StateMachine::can('payment','pending','succeeded'),'payment pending->succeeded');
check(StateMachine::can('payment','pending','failed'),'payment pending->failed');
check(!StateMachine::can('payment','succeeded','failed'),'payment succeeded->failed blocked');

// SUBSCRIPTION lifecycle
check(StateMachine::can('subscription','pending','active'),'sub pending->active');
check(StateMachine::can('subscription','provisioning','active'),'sub provisioning->active');
check(StateMachine::can('subscription','active','expired'),'sub active->expired');
check(StateMachine::can('subscription','grace','expired'),'sub grace->expired');
check(StateMachine::can('subscription','grace','active'),'sub grace->active');
check(!StateMachine::can('subscription','expired','active'),'sub expired->active blocked');
check(!StateMachine::can('subscription','active','pending'),'sub active->pending blocked');

// assertCanTransition throws BillingError on illegal, passes on legal
try { StateMachine::assertCanTransition('order','paid','paid'); check(false,'assert throws on illegal'); }
catch (BillingError $e) { check(str_contains($e->getMessage(),'Недопустимый переход'),'assert throws BillingError on illegal'); }
catch (\Throwable $e) { check(false,'assert throws BillingError (got '.get_class($e).')'); }
try { StateMachine::assertCanTransition('topup','pending','paid'); check(true,'assert passes on legal'); }
catch (\Throwable $e) { check(false,'assert passes on legal (threw '.get_class($e).')'); }
try { StateMachine::allowed('nope','x'); check(false,'unknown entity throws'); }
catch (\InvalidArgumentException $e) { check(true,'unknown entity throws InvalidArgumentException'); }
catch (\Throwable $e) { check(false,'unknown entity throws InvalidArgumentException (got '.get_class($e).')'); }

// Wire-level equivalence: settle() guard semantics preserved
$fromPending = StateMachine::can('order','pending','paid');                       // OLD: status!=='pending' -> proceed; NEW: can('order',status,'paid')
check($fromPending === true, 'settle guard: pending proceeds (was !==pending)');
$fromCanceled = StateMachine::can('order','canceled','paid');                      // OLD: canceled !== pending -> throw; NEW: can -> false
check($fromCanceled === false, 'settle guard: canceled throws (was !==pending)');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail===0?0:1);
