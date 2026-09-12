<?php
$c = require '/app/bootstrap.php';
$cfg = $c->config;
$apiKey = $cfg['FREEKASSA_API_KEY'];
$shopId = $cfg['FREEKASSA_SHOP_ID'];

// Test: does FreeKassa reset nonce? Try time() in seconds now
$nonce = time();
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
echo "nonce=time()=$nonce HTTP $code: $resp\n";
