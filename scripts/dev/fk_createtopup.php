<?php
$c = require '/app/bootstrap.php';
$id = '26262e5f25b54ee1def4812e6c333719';
try {
    $result = $c->paymentService->createTopup($id);
    echo "OK: " . json_encode($result) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
