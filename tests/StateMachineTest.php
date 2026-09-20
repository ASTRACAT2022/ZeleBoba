<?php
declare(strict_types=1);
namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Infrastructure\StateMachine;
use App\Billing\BillingError;

/**
 * §6: explicit assertCanTransition state-machine validators.
 * Verifies legal/illegal transitions on the money-critical axes and that the
 * validator throws BillingError on illegal ones (behavior-preserving vs the
 * removed string guards).
 */
final class StateMachineTest extends TestCase
{
    public function testOrderAllowedPaid(): void
    {
        self::assertTrue(StateMachine::can('order', 'pending', 'paid'));
        self::assertTrue(StateMachine::can('order', 'pending', 'canceled'));
        self::assertTrue(StateMachine::can('order', 'paid', 'fulfilled'));
    }

    public function testOrderIllegalPaidFromPaid(): void
    {
        self::assertFalse(StateMachine::can('order', 'paid', 'paid'));     // no double-settle
        self::assertFalse(StateMachine::can('order', 'canceled', 'paid')); // no zombie revive
        self::assertFalse(StateMachine::can('order', 'fulfilled', 'paid'));
    }

    public function testTopup(): void
    {
        self::assertTrue(StateMachine::can('topup', 'pending', 'paid'));
        self::assertTrue(StateMachine::can('topup', 'pending', 'canceled'));
        self::assertFalse(StateMachine::can('topup', 'paid', 'paid'));
        self::assertFalse(StateMachine::can('topup', 'canceled', 'paid'));
    }

    public function testPayment(): void
    {
        self::assertTrue(StateMachine::can('payment', 'pending', 'succeeded'));
        self::assertTrue(StateMachine::can('payment', 'pending', 'failed'));
        self::assertFalse(StateMachine::can('payment', 'succeeded', 'failed'));
    }

    public function testSubscriptionLifecycle(): void
    {
        self::assertTrue(StateMachine::can('subscription', 'pending', 'active'));
        self::assertTrue(StateMachine::can('subscription', 'provisioning', 'active'));
        self::assertTrue(StateMachine::can('subscription', 'active', 'expired'));
        self::assertTrue(StateMachine::can('subscription', 'grace', 'expired'));
        self::assertTrue(StateMachine::can('subscription', 'grace', 'active'));
        self::assertFalse(StateMachine::can('subscription', 'expired', 'active'));
        self::assertFalse(StateMachine::can('subscription', 'active', 'pending'));
    }

    public function testAssertThrowsOnIllegal(): void
    {
        $this->expectException(BillingError::class);
        StateMachine::assertCanTransition('order', 'paid', 'paid');
    }

    public function testAssertPassesOnLegal(): void
    {
        StateMachine::assertCanTransition('topup', 'pending', 'paid');
        self::assertTrue(true);
    }

    public function testUnknownEntityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StateMachine::allowed('does_not_exist', 'pending');
    }
}
