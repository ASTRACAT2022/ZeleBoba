<?php
// Cleanup script 2026-09-28: inspect + optionally act. Runs inside zeleboba-app-1.
// Usage: php /app/scripts/cleanup_junk_20260928.php inspect
//        php /app/scripts/cleanup_junk_20260928.php disable-subs
//        php /app/scripts/cleanup_junk_20260928.php delete-orders
declare(strict_types=1);

$app = require '/app/bootstrap.php';
$db  = $app->db;
$mode = $argv[1] ?? 'inspect';

$provisioner = new \App\Integration\RemnawaveProvisioner(
    \Symfony\Component\HttpClient\HttpClient::create(),
    $app->config['REMNAWAVE_URL'],
    $app->config['REMNAWAVE_TOKEN'],
    $app->config['REMNAWAVE_SQUAD_UUID'],
    $app->circuitBreaker
);

if ($mode === 'inspect') {
    $rows = $db->all("SELECT id,user_id,remote_id,remnawave_id,expires_at FROM subscriptions
        WHERE status='active' AND (plan_id IS NULL OR plan_id='')
          AND created_at>=1790260625 ORDER BY id LIMIT 25");
    $exists=0; $missing=0; $errs=0; $detail=[];
    foreach ($rows as $r) {
        try {
            $u = null;
            if (!empty($r['remnawave_id'])) $u = $provisioner->fetchById((int)$r['remnawave_id']);
            if (!$u && !empty($r['remote_id']) && !ctype_digit((string)$r['remote_id'])) $u = $provisioner->fetch((string)$r['remote_id']);
            if ($u) { $exists++; $detail[]=$r['id'].' panel='.($u['id']??'?').' status='.($u['status']??'?').' exp='.($u['expireAt']??'?'); }
            else { $missing++; $detail[]=$r['id'].' MISSING remote='.$r['remote_id']; }
        } catch (\Throwable $e) { $errs++; $detail[]=$r['id'].' ERR '.$e->getMessage(); }
    }
    echo json_encode(['sampled'=>count($rows),'exists'=>$exists,'missing'=>$missing,'errors'=>$errs,'detail'=>$detail], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), "\n";
    exit;
}

if ($mode === 'disable-subs') {
    // 1) mark the empty compensation subs disabled locally
    $now = time();
    $n = $db->execute("UPDATE subscriptions SET status='disabled',updated_at=?
        WHERE status='active' AND (plan_id IS NULL OR plan_id='')
          AND created_at>=1790260625", [$now]);
    echo "local disabled rows affected: ".$n."\n";

    // 2) disable in Remnawave panel (best effort, throttled, breaker-aware)
    $rows = $db->all("SELECT id,user_id,remote_id,remnawave_id FROM subscriptions
        WHERE status='disabled' AND (plan_id IS NULL OR plan_id='')
          AND created_at>=1790260625");
    $ok=0; $miss=0; $fail=0;
    foreach ($rows as $r) {
        $panelId = (int)($r['remnawave_id'] ?? 0);
        try {
            if ($panelId <= 0 && !empty($r['remote_id'])) {
                $u = $provisioner->fetch((string)$r['remote_id']);
                $panelId = (int)($u['id'] ?? 0);
            }
            if ($panelId > 0) { $provisioner->disableById($panelId); $ok++; }
            else { $miss++; }
        } catch (\Throwable $e) { $fail++; }
        usleep(250000); // 4/sec
        if (($ok+$miss+$fail) % 200 === 0) fwrite(STDERR, "progress: ".($ok+$miss+$fail)."/".count($rows)." ok=$ok miss=$miss fail=$fail\n");
    }
    echo json_encode(['total'=>count($rows),'panel_disabled'=>$ok,'panel_missing'=>$miss,'failed'=>$fail]), "\n";
    exit;
}

if ($mode === 'delete-orders') {
    // order_items first (FK), then orders. Only noncechk_*.
    $items = $db->execute("DELETE FROM order_items WHERE order_id LIKE 'noncechk%'");
    $orders = $db->execute("DELETE FROM orders WHERE id LIKE 'noncechk%'");
    echo json_encode(['order_items_deleted'=>$items,'orders_deleted'=>$orders]), "\n";
    exit;
}

echo "unknown mode\n";
