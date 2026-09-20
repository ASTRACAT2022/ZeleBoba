<?php
declare(strict_types=1);
namespace App\Infrastructure;

use App\Billing\BillingError;

/**
 * Explicit state-machine for the money-critical status transitions (ТЗ §6).
 * Allowed transitions are derived from the actual transitions performed by
 * production code (settle/provision/extend/expire) — not invented. Every
 * transition here is guarded by at least one call site via assertCanTransition().
 *
 * Entity keys:
 *   'order'        => orders.status            (pending_payment/pending -> paid | canceled)
 *   'topup'        => topups.status            (pending -> paid | canceled)
 *   'payment'      => payments.status          (pending -> succeeded | failed)
 *   'subscription' => subscriptions.lifecycle_status (pending/provisioning -> active; active/grace -> expired)
 *
 * Note: orders.workflow_status is a separate display-ish field (pending_payment ->
 * paid/fulfilled/canceled); the money gate is orders.status. subscriptions.status
 * and lifecycle_status move together; we validate lifecycle_status as the
 * authoritative axis (subscriptions.status mirrors it: provisioning/pending ->
 * active, trial handled by the provisioning driver).
 */
final class StateMachine
{
    /** orders.status (CHECK: pending, paid, fulfilled, canceled, payment_unavailable) */
    private const ORDER = [
        'pending'            => ['paid', 'canceled'],
        'payment_unavailable'=> ['pending', 'canceled'],
        'paid'               => ['fulfilled'],
        'fulfilled'          => [],
        'canceled'           => [],
    ];
    /** topups.status */
    private const TOPUP = [
        'pending'  => ['paid', 'canceled'],
        'paid'     => [],
        'canceled' => [],
    ];
    /** payments.status */
    private const PAYMENT = [
        'pending'   => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed'    => [],
    ];
    /** subscriptions.lifecycle_status axis */
    private const SUBSCRIPTION = [
        'pending'     => ['provisioning', 'active', 'expired'],
        'provisioning'=> ['active', 'expired'],
        'active'      => ['grace', 'expired'],
        'grace'       => ['active', 'expired'],
        'expired'     => [],
        'trial'       => ['active', 'expired'],
    ];

    /** @return string[] the target states reachable from $from (immutable copy). */
    public static function allowed(string $entity, string $from): array
    {
        return self::table($entity)[$from] ?? [];
    }

    public static function can(string $entity, string $from, string $to): bool
    {
        return in_array($to, self::allowed($entity, $from), true);
    }

    /** @throws BillingError on an illegal transition. */
    public static function assertCanTransition(string $entity, string $from, string $to): void
    {
        if (self::can($entity, $from, $to)) return;
        $allowed = self::allowed($entity, $from);
        throw new BillingError(sprintf(
            'Недопустимый переход статуса: %s %s → %s' . ($allowed ? ' (допустимо: %s)' : ' (терминальное состояние)'),
            $entity, $from === '' ? '<не задан>' : $from, $to, implode(', ', $allowed)
        ));
    }

    /** @return array<string,array<string,string[]>> */
    private static function table(string $entity): array
    {
        return match ($entity) {
            'order'        => self::ORDER,
            'topup'        => self::TOPUP,
            'payment'      => self::PAYMENT,
            'subscription' => self::SUBSCRIPTION,
            default        => throw new \InvalidArgumentException("Unknown state-machine entity: {$entity}"),
        };
    }
}
