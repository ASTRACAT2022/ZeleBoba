<?php
$c = require '/app/bootstrap.php';
$cfg = $c->config;
$apiKey = $cfg['FREEKASSA_API_KEY'];
$shopId = $cfg['FREEKASSA_SHOP_ID'];

// Try huge nonces
$candidates = [
    time() * 1000000,
    time() * 1000000 + 1000000,
    PHP_INT_MAX - 1000,
];
foreach ($candidates as $nonce) {
    $data = ['shopId'=>(int)$shopId, 'nonce'=>$nonce];
    ksort($data);
    $data['signature'] = hash_hmac('sha256', implode('|', $data), $apiKey);
    $ch = curl_init('https://api.fk.life/v1/balance');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "nonce=$nonce HTTP $code: $resp\n";
    usleep(300000);
}
