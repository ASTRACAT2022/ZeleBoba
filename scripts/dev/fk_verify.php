<?php
$c = require '/app/bootstrap.php';
// Verify a payment via the orders endpoint
try {
    $result = $c->paymentService->verify('330392996');
    echo "Verify OK: " . json_encode($result) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
