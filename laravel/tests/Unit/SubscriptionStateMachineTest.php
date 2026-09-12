<?php

namespace Tests\Unit;

use App\Domain\Subscriptions\SubscriptionStateMachine;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SubscriptionStateMachineTest extends TestCase
{
    public function test_allows_only_explicit_lifecycle_transitions(): void
    {
        SubscriptionStateMachine::assert('provisioning', 'active');
        SubscriptionStateMachine::assert('active', 'suspended');
        SubscriptionStateMachine::assert('suspended', 'active');
        SubscriptionStateMachine::assert('active', 'expired');

        $this->expectException(DomainException::class);
        SubscriptionStateMachine::assert('expired', 'suspended');
    }
}
