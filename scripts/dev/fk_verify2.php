<?php
$c = require '/app/bootstrap.php';
try {
    $result = $c->paymentService->verify('330392811');
    echo "Verify OK: " . json_encode($result) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
