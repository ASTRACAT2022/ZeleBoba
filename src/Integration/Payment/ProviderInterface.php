<?php
declare(strict_types=1);
namespace App\Integration\Payment;
interface ProviderInterface
{
    /** Unique provider id (matches topups.provider / orders.provider). */
    public function id(): string;
    /** Human-readable display name. */
    public function name(): string;
    /** Whether the provider is configured (keys present). */
    public function configured(): bool;
    /** Create a checkout for a balance topup. Returns [payment_id, checkout_url]. */
    public function createTopup(array $topup, array $user): array;
    /** Create a checkout for a subscription order. Returns [payment_id, checkout_url]. */
    public function createOrder(array $order, array $user): array;
    /**
     * Verify a payment status with the provider API.
     * Returns ['status'=>'paid'|'pending'|'canceled','amount_kopeks'=>int,'currency'=>string,'payment_id'=>string,'metadata'=>array].
     */
    public function verify(string $paymentId): array;
    /**
     * Handle an incoming webhook. Returns ['payment_id'=>string,'status'=>'paid'|'canceled'|'pending'] or null if not ours.
     */
    public function handleWebhook(\Symfony\Component\HttpFoundation\Request $request): ?array;
}
