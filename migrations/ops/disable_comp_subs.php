<?php
declare(strict_types=1);
/**
 * Disable the ~5544 compensation subscriptions (created by the 2026-09-24 grant runs).
 * Panel first (Remnawave DISABLED), then local status='disabled'.
 * Usage: php scripts/disable_comp_subs.php [--apply] [--limit=N]
 * No flag = dry run (no writes, no panel calls).
 */
$app = require __DIR__ . '/../bootstrap.php';

$apply = in_array('--apply', $argv, true);
$limit = 0;
foreach ($argv as $a) { if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1]; }

$cfg = $app->config;
$rw = new \App\Integration\RemnawaveProvisioner(
    \Symfony\Component\HttpClient\HttpClient::create(),
    $cfg['REMNAWAVE_URL'], $cfg['REMNAWAVE_TOKEN'], $cfg['REMNAWAVE_SQUAD_UUID'],
    $app->circuitBreaker
);

$sql = "SELECT id, user_id, remote_id, remnawave_id
          FROM subscriptions
         WHERE status='active' AND expires_at>extract(epoch from now())
           AND created_at>=1790260625
         ORDER BY id" . ($limit > 0 ? " LIMIT $limit" : "");
$rows = $app->db->all($sql);
fwrite(STDOUT, "targets=" . count($rows) . " mode=" . ($apply ? 'APPLY' : 'DRY') . "\n");

$ok = 0; $fail = 0; $errors = []; $batch = 0;
foreach ($rows as $r) {
    $panelId = (int)($r['remote_id'] ?: $r['remnawave_id'] ?: 0);
    if ($panelId <= 0) { $fail++; if (count($errors) < 25) $errors[] = $r['id'] . " no panel id"; continue; }
    try {
        if ($apply) {
            $rw->disableById($panelId);
            $app->db->execute(
                "UPDATE subscriptions SET status='disabled', updated_at=? WHERE id=? AND status='active'",
                [time(), $r['id']]
            );
        }
        $ok++;
    } catch (\Throwable $e) {
        $fail++;
        if (count($errors) < 25) $errors[] = $r['id'] . " panel=$panelId " . $e->getMessage();
    }
    if (++$batch % 200 === 0) fwrite(STDOUT, "  ...processed $batch (ok=$ok fail=$fail)\n");
}
fwrite(STDOUT, json_encode(['ok'=>$ok,'fail'=>$fail,'errors'=>$errors], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
