<?php
declare(strict_types=1);
namespace App\Subscriptions;

use App\Billing\BillingError;
use App\Infrastructure\{Database,Outbox};
use App\Observability\OperationsService;

/**
 * Merge two of a user's subscriptions into one: every resource of the source
 * subscription is transferred to the target, the source is disabled/detached,
 * and the target is re-provisioned to the merged limits. Money-safe: the whole
 * local mutation happens in ONE transaction under row locks, so a crash can
 * never leave "resources gone from both" or "double-credited" states.
 *
 * Resource semantics:
 *   days       -> remaining full days of the source, added to target expiry
 *                 (computed from source.expires_at; an expired source adds 0)
 *   traffic    -> traffic_limit_gb + purchased_traffic_gb summed into target;
 *                 unlimited (0 limit) on either side keeps the target unlimited
 *   devices    -> summed; unlimited (0) on either side keeps unlimited
 *   used       -> traffic_used_gb summed (remaining = limit+purchased-used)
 *   auto_renew -> target keeps its own auto-renew settings (source's is dropped
 *                 since the source is removed)
 *
 * The source subscription is HARD-deleted: its row is removed from
 * `subscriptions` within the same transaction. FK on `subscriptions` are
 * repointed by migration 035 so runtime provisioning rows CASCADE away and
 * financial history rows (operations/order_items/orders/operation_events)
 * are kept with their subscription link set to NULL — the money trail is
 * never destroyed, while the subscription itself fully disappears.
 */
final class SubscriptionMergeService
{
    public function __construct(
        private Database $db,
        private Outbox $outbox,
        private ?\App\Integration\Provisioner $provisioner = null,
        private ?\App\Billing\CustomerTimeline $timeline = null,
    ) {}

    /**
     * Merge $sourceId into $targetId for $userId.
     *
     * @return array the (merged) target subscription row
     * @throws BillingError on any precondition violation
     */
    public function merge(string $userId, string $sourceId, string $targetId): array
    {
        if ($sourceId === '' || $targetId === '' || strlen($sourceId) > 32 || strlen($targetId) > 32) {
            throw new BillingError('Некорректный идентификатор подписки.');
        }
        if ($sourceId === $targetId) {
            throw new BillingError('Нельзя объединить подписку с самой собой.');
        }

        $ops = new OperationsService($this->db);
        $corr = 'merge:'.substr(hash('sha256', $userId.':'.$sourceId.':'.$targetId), 0, 42);
        $op = $ops->start('subscription.merge', [
            'user_id' => $userId,
            'metadata' => ['source_id' => $sourceId, 'target_id' => $targetId],
        ], $corr);

        try {
            $local = $this->db->transaction(function () use ($ops, $op, $userId, $sourceId, $targetId): array {
            // Fixed lock order (by id) avoids deadlocks when two merges race.
            $a = min($sourceId, $targetId);
            $b = max($sourceId, $targetId);
            $rowA = $this->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->db->lock(), [$a]);
            $rowB = $this->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->db->lock(), [$b]);
            // Map locked rows back to source/target by their real id.
            if (!$rowA || !$rowB) {
                throw new BillingError('Подписка не найдена.');
            }
            $source = $rowA['id'] === $sourceId ? $rowA : $rowB;
            $target = $rowA['id'] === $targetId ? $rowA : $rowB;
            $sourcePanelId = (int)($source['remnawave_id'] ?? 0);

            $this->assertMergeable($source, $sourceId, $userId, 'исходная');
            $this->assertMergeable($target, $targetId, $userId, 'целевая');
            if ($target['id'] !== $targetId || $source['id'] !== $sourceId) {
                throw new BillingError('Подписка не найдена.');
            }

            $now = time();
            // ---- days ----
            $remainingDays = (int)max(0, ((int)$source['expires_at'] - $now + 86399) / 86400);
            $newExpiry = (int)$target['expires_at'];
            if ($remainingDays > 0) {
                $newExpiry = (new SubscriptionService($this->db, $this->outbox))
                    ->expiryAfter((int)max($now, (int)$target['expires_at']), $remainingDays);
            }
            $srcLimit = (int)$source['traffic_limit_gb'];
            $tgtLimit = (int)$target['traffic_limit_gb'];
            $srcPurchased = (int)$source['purchased_traffic_gb'];
            $tgtPurchased = (int)$target['purchased_traffic_gb'];

            // unlimited wins: 0 (unlimited) on either side -> target unlimited
            if ($srcLimit === 0 || $tgtLimit === 0) {
                $newLimit = 0;
                $newPurchased = 0;
            } else {
                $newLimit = $tgtLimit + $srcLimit;
                $newPurchased = $tgtPurchased + $srcPurchased;
            }
            // Devices: 0 (unlimited) wins.
            $srcDev = (int)($source['device_limit'] ?? 0);
            $tgtDev = (int)($target['device_limit'] ?? 1);
            $newDevices = ($srcDev === 0 || $tgtDev === 0) ? 0 : ($tgtDev + $srcDev);
            $newUsed = (float)$target['traffic_used_gb'] + (float)$source['traffic_used_gb'];

            // Persist the merged target.
            $this->db->execute(
                'UPDATE subscriptions SET expires_at=?,traffic_limit_gb=?,purchased_traffic_gb=?,device_limit=?,traffic_used_gb=?,traffic_limit_bytes=?,status=?,lifecycle_status=?,updated_at=?,version=version+1 WHERE id=?',
                [$newExpiry, $newLimit, $newPurchased, $newDevices, $newUsed,
                 $newLimit === 0 ? 0 : ($newLimit + $newPurchased) * 1073741824,
                 'active', 'active', $now, $targetId]
            );

            // HARD-delete the source subscription. FK are repointed (migration
            // 035): provisioning rows CASCADE, financial history SET NULL —
            // the audit trail survives with a cleared link, the subscription
            // itself is gone for the customer and the panel.
            $this->db->execute('DELETE FROM subscriptions WHERE id=?', [$sourceId]);
            // Drop pending scheduling/provisioning work for the source.
            $this->db->execute(
                "DELETE FROM outbox WHERE topic IN ('subscription.provision','subscription.extend','subscription.renew','subscription.daily') AND payload LIKE ?",
                ['%'.$sourceId.'%']
            );
            // Runtime provisioning rows are removed by the CASCADE FK; nothing
            // else references the source subscription.

            // Audit + timeline.
            $this->audit($userId, 'subscription.merged', $sourceId,
                ['target_id' => $targetId, 'days' => $remainingDays,
                 'traffic_gb' => $newLimit === 0 ? 0 : ($newLimit - $tgtLimit),
                 'purchased_gb' => $newLimit === 0 ? 0 : $newPurchased,
                 'devices' => $newDevices, 'new_expiry' => $newExpiry]);
            if ($this->timeline) {
                $this->timeline->record($userId, 'subscription.merged', [
                    'source_id' => $sourceId, 'target_id' => $targetId,
                    'days_transferred' => $remainingDays, 'merged_expiry' => $newExpiry,
                ], $now);
            }
            $ops->event($op['id'], 'subscription.merged', 'success',
                'Подписки объединены: '.substr($sourceId, 0, 8).' → '.substr($targetId, 0, 8),
                ['metadata' => ['source_id' => $sourceId, 'target_id' => $targetId,
                                'days' => $remainingDays, 'expiry' => $newExpiry]]);

            $merged = $this->db->one('SELECT * FROM subscriptions WHERE id=?', [$targetId]);
            if (!$merged) {
                throw new BillingError('Не удалось прочитать объединённую подписку.');
            }
            return ['merged' => $merged, 'source_panel_id' => $sourcePanelId];
            });
        } catch (\Throwable $e) {
            if (!($op['existing'] ?? false)) $ops->fail($op['id'], $e);
            throw $e;
        }
        if (!($op['existing'] ?? false)) $ops->complete($op['id']);

        // ---- Outside the transaction: idempotent panel sync. ----
        // A crash between commit and this point still leaves the DB in the
        // correct merged state; the Reconciler re-runs provisioning recovery,
        // and the deterministic zb_<target> username makes this update safe.
        $this->syncPanel($targetId);
        $this->disableSourceOnPanel($sourceId, (int)$local['source_panel_id']);
        return $local['merged'];
    }

    private function assertMergeable(array $sub, string $otherId, string $userId, string $label): void
    {
        if (!$sub || $sub['id'] !== $otherId) {
            throw new BillingError('Подписка не найдена.');
        }
        if ((string)$sub['user_id'] !== $userId) {
            throw new BillingError('Подписка принадлежит другому аккаунту.');
        }
        $status = (string)($sub['lifecycle_status'] ?? $sub['status']);
        if (!in_array($status, ['active', 'provisioning', 'trial', 'pending'], true)) {
            throw new BillingError(ucfirst($label).' подписка недоступна для объединения (статус «'.$status.'»).');
        }
        if ($label === 'целевая' && (int)$sub['expires_at'] <= time() && (int)$sub['expires_at'] !== 0) {
            throw new BillingError('Целевая подписка уже истекла.');
        }
    }

    private function syncPanel(string $subscriptionId): void
    {
        if (!$this->provisioner) return;
        $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?', [$subscriptionId]);
        if (!$sub || $sub['status'] !== 'active') return;
        $sub['traffic_bytes'] = (int)$sub['traffic_limit_gb'] === 0
            ? 0
            : ((int)$sub['traffic_limit_gb'] + (int)$sub['purchased_traffic_gb']) * 1073741824;
        $sub['devices'] = (int)$sub['device_limit'];
        try {
            $panelId = (int)($sub['remnawave_id'] ?? 0);
            if ($panelId > 0 && method_exists($this->provisioner, 'fetchById')) {
                $panel = $this->provisioner->fetchById($panelId);
                if ($panel && !empty($panel['id'])) {
                    $this->provisioner->updateById((int)$panel['id'], (int)$sub['traffic_bytes'], (int)$sub['devices'], (int)$sub['expires_at']);
                    return;
                }
            }
            $panel = $this->provisioner->fetch('zb_'.$sub['id']);
            if ($panel && !empty($panel['id'])) {
                $this->provisioner->extend($sub);
            }
        } catch (\Throwable $e) {
            // Panel sync failure is not a money failure: the local DB is
            // committed and Recovery/provisioning re-enqueues the update.
            error_log(json_encode(['event' => 'merge.panel_sync_failed', 'subscription_id' => $subscriptionId, 'error' => get_class($e)]));
        }
    }

    private function disableSourceOnPanel(string $sourceId, int $panelId = 0): void
    {
        if (!$this->provisioner) return;
        try {
            if ($panelId > 0 && method_exists($this->provisioner, 'disableById')) {
                $this->provisioner->disableById($panelId);
                return;
            }
            if (method_exists($this->provisioner, 'disable')) {
                $this->provisioner->disable('zb_'.$sourceId);
            }
        } catch (\Throwable $e) {
            error_log(json_encode(['event' => 'merge.panel_disable_failed', 'subscription_id' => $sourceId, 'error' => get_class($e)]));
        }
    }

    private function audit(string $userId, string $action, string $subject, array $extra): void
    {
        // audit_log has (id, actor, action, subject, created_at) — no metadata
        // column. Encode a compact, bounded summary into subject.
        $subject = substr($action.':'.($extra['target_id'] ?? 'none').':'.($extra['days'] ?? 0).'d', 0, 100);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [
            Database::id(), 'user:'.$userId, $action, $subject, time(),
        ]);
    }
}
