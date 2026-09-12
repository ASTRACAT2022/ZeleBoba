<?php

namespace App\Services;

use App\Jobs\ProvisionSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderService
{
    public function create(string $userId, string $planId, string $key): object
    {
        if (! preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Некорректный ключ операции.']);
        }

        return DB::transaction(function () use ($userId, $planId, $key): object {
            $existing = DB::table('orders')->where('user_id', $userId)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->plan_id !== $planId) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другого тарифа.']);
                }

                return $existing;
            }
            $plan = DB::table('plans')->where('id', $planId)->where('active', 1)->first();
            if (! $plan) {
                throw ValidationException::withMessages(['plan_id' => 'Тариф недоступен.']);
            }

            $id = bin2hex(random_bytes(16));
            $now = time();
            DB::table('orders')->insert([
                'id' => $id, 'user_id' => $userId, 'plan_id' => $planId, 'idempotency_key' => $key,
                'price_minor' => $plan->price_minor, 'currency' => $plan->currency, 'plan_name' => $plan->name,
                'duration_days' => $plan->duration_days, 'traffic_bytes' => $plan->traffic_bytes, 'devices' => $plan->devices,
                'status' => 'pending', 'provider' => config('payments.driver'), 'created_at' => $now,
            ]);
            $this->timeline($userId, 'payment.created', ['amount_kopeks' => (int) $plan->price_minor, 'order_id' => $id], $now);

            return DB::table('orders')->where('id', $id)->first();
        });
    }

    public function settleDemo(string $orderId, string $userId): void
    {
        if (app()->isProduction() || config('payments.driver') !== 'demo') {
            abort(404);
        }
        $order = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->first();
        if (! $order) {
            abort(404);
        }
        $this->settleVerified($orderId, 'demo', 'demo_'.$orderId, (int) $order->price_minor, $order->currency);
    }

    public function settleVerified(string $orderId, string $provider, string $paymentId, int $amount, string $currency): void
    {
        DB::transaction(function () use ($orderId, $provider, $paymentId, $amount, $currency): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->provider !== $provider || (int) $order->price_minor !== $amount || $order->currency !== $currency) {
                throw ValidationException::withMessages(['payment' => 'Платёж не соответствует заказу.']);
            }
            $receipt = DB::table('payment_receipts')->where('provider', $provider)->where('payment_id', $paymentId)->first();
            if ($receipt) {
                return;
            }
            if ($order->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Заказ уже обработан.']);
            }

            $now = time();
            DB::table('payment_receipts')->insert(['provider' => $provider, 'payment_id' => $paymentId, 'order_id' => $orderId, 'amount_minor' => $order->price_minor, 'currency' => $order->currency, 'created_at' => $now]);
            foreach (['provider_clearing' => $order->price_minor, 'subscription_sales' => -$order->price_minor] as $account => $amount) {
                DB::table('ledger_entries')->insert(['id' => bin2hex(random_bytes(16)), 'order_id' => $orderId, 'account' => $account, 'amount_minor' => $amount, 'currency' => $order->currency, 'created_at' => $now]);
            }
            DB::table('orders')->where('id', $orderId)->update(['status' => 'paid', 'provider_payment_id' => $paymentId, 'paid_at' => $now]);
            $subscriptionId = bin2hex(random_bytes(16));
            DB::table('subscriptions')->insert(['id' => $subscriptionId, 'order_id' => $orderId, 'user_id' => $order->user_id, 'status' => 'provisioning', 'expires_at' => $now + $order->duration_days * 86400, 'created_at' => $now, 'plan_id' => $order->plan_id, 'traffic_limit_gb' => (int) ($order->traffic_bytes / 1073741824), 'device_limit' => $order->devices]);
            $this->timeline($order->user_id, 'payment.paid', ['amount_kopeks' => (int) $order->price_minor, 'order_id' => $orderId], $now);
            ProvisionSubscription::dispatch($subscriptionId)->afterCommit();
        });
    }

    /** @param array<string, scalar> $payload */
    private function timeline(string $userId, string $event, array $payload, int $at): void
    {
        DB::table('customer_timeline')->insert(['id' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'event_type' => $event, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => $at, 'recorded_at' => (int) floor(microtime(true) * 1_000_000)]);
    }
}
