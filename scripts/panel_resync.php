<?php
declare(strict_types=1);
// Full Remnawave panel resync for active subscriptions, throttled.
// Uses the fixed RemnawaveSync (no SQL integer overflow). Loops in batches,
// pausing between batches to avoid overwhelming the panel / reopening breaker.
$c = require dirname(__DIR__) . '/bootstrap.php';

$http = \Symfony\Component\HttpClient\HttpClient::create();
// No breaker here: CLI/script sync must not be throttled by the outbox breaker
// (which trips on chronic subscriptions). We rely on small batches + pause
// instead. Provisioner without breaker.
$sync = new \App\Integration\RemnawaveSync(
    $c->db,
    new \App\Integration\RemnawaveProvisioner(
        $http,
        $c->config['REMNAWAVE_URL'],
        $c->config['REMNAWAVE_TOKEN'],
        $c->config['REMNAWAVE_SQUAD_UUID']
    )
);

$batch = (int)($_SERVER['BATCH'] ?? 40);
$pauseMs = (int)($_SERVER['PAUSE_MS'] ?? 900); // between batches
$maxBatches = 0; // unlimited (script controlled externally)
$totals = ['checked'=>0,'fixed'=>0,'reprovisioned'=>0,'disabled'=>0,'missing'=>0,'errors'=>0];
$emptyRuns = 0;
$batchNo = 0;

fwrite(STDERR, "[" . gmdate('H:i:s') . "] starting resync batch={$batch} pause={$pauseMs}ms\n");

while (true) {
    $batchNo++;
    $report = $sync->run($batch, true);
    foreach (['checked','fixed','reprovisioned','disabled','missing','errors'] as $k) $totals[$k] += $report[$k];
    $emptyRuns = ($report['checked'] === 0) ? $emptyRuns+1 : 0;
    fwrite(STDERR, sprintf(
        "[%s] b=%d checked=%d fixed=%d repro=%d disabled=%d missing=%d errors=%d | TOT checked=%d repro=%d err=%d\n",
        gmdate('H:i:s'), $batchNo, $report['checked'], $report['fixed'],
        $report['reprovisioned'], $report['disabled'], $report['missing'],
        $report['errors'], $totals['checked'], $totals['reprovisioned'], $totals['errors']
    ));

    if ($report['checked'] === 0 && $report['errors'] === 0) { $emptyRuns++; if ($emptyRuns >= 3) { fwrite(STDERR,"nothing left, done\n"); break; } }
    if ($lastErrors = $report['errors']) { /* continue, errors counted */ }

    if ($pauseMs > 0) usleep($pauseMs * 1000);
}

echo json_encode($totals) . "\n";
fwrite(STDERR, "[" . gmdate('H:i:s') . "] FINISHED: " . json_encode($totals) . "\n");
