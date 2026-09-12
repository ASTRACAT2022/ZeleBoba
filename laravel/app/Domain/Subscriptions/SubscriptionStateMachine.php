<?php

namespace App\Domain\Subscriptions;

use DomainException;

/**
 * Canonical transition policy for the Laravel runtime. Legacy-only statuses
 * remain readable during migration, but Laravel must not create new ones.
 */
final class SubscriptionStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'provisioning' => ['active', 'failed', 'cancelled'],
        'active' => ['suspended', 'expired', 'cancelled'],
        'suspended' => ['active', 'expired', 'cancelled'],
        'expired' => ['active'],
        'failed' => ['provisioning', 'cancelled'],
        'cancelled' => [],
    ];

    public static function assert(string $from, string $to): void
    {
        if (! isset(self::TRANSITIONS[$from]) || ! in_array($to, self::TRANSITIONS[$from], true)) {
            throw new DomainException("Invalid subscription transition: {$from} -> {$to}");
        }
    }
}
