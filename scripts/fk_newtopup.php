<?php
$c = require '/app/bootstrap.php';
// Create a fresh topup and generate its checkout URL
try {
    $topup = $c->topups->create('95c6cdd5bbde1d2ce3e87cd189065fcc', 10000, 'fk-test-'.time(), 'freekassa');
    echo "Topup created: " . $topup['id'] . "\n";
    $result = $c->paymentService->createTopup($topup['id']);
    echo "OK: " . json_encode($result) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
