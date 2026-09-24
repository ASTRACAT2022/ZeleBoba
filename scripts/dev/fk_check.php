<?php
/**
 * FreeKassa diagnostic: verify API key via balance request, then optionally
 * reset the dead topup.create outbox job so the worker retries it.
 *
 * Usage (inside app container):
 *   php /tmp/fk_check.php            # just check the key
 *   php /tmp/fk_check.php --reset    # check key + reset dead job
 */
declare(strict_types=1);
$c = require '/app/bootstrap.php';
$cfg = $c->config;
$apiKey = $cfg['FREEKASSA_API_KEY'];
$shopId = $cfg['FREEKASSA_SHOP_ID'];

// 1) Verify the API key with the simplest documented call: /v1/balance
// Use a monotonic microsecond nonce (FreeKassa rejects nonce <= previous).
$nonce = (int)(microtime(true) * 1000000);
$data = ['shopId' => (int)$shopId, 'nonce' => $nonce];
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
echo "balance HTTP $code: $resp\n";
if ($code !== 200) {
    echo "KEY INVALID — FreeKassa rejects the signature. Check the API key in merchant.freekassa.net → Настройки.\n";
    exit(1);
}
echo "KEY OK\n";

// 2) Optionally reset the dead topup.create job
if (in_array('--reset', $argv, true)) {
    $n = $c->db->execute(
        "UPDATE outbox SET status='pending', attempts=0, available_at=?, last_error=NULL
         WHERE topic='topup.create' AND status='dead'",
        [time()]
    );
    echo "Reset $n dead topup.create job(s) to pending.\n";
}
