<?php

namespace App\Services;

use App\Domain\Subscriptions\SubscriptionStateMachine;
use App\Jobs\ProvisionSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Business lifecycle which runs against the pre-existing shared schema. */
final class SubscriptionLifecycleService
{
    public function __construct(private readonly WalletService $wallet) {}

    public function startTrial(string $userId, string $planId): string
    {
        return DB::transaction(function () use ($userId, $planId): string {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $plan = DB::table('plans')->where('id', $planId)->where('active', 1)->where('is_trial_available', 1)->lockForUpdate()->first();
            $alreadyUsed = DB::table('subscriptions')->where('user_id', $userId)->where(function ($q): void {
                $q->where('is_trial', 1)->orWhereIn('status', ['active', 'trial', 'limited', 'provisioning']);
            })->exists();
            if (! $user || (int) ($user->disabled ?? 0) === 1 || (int) ($user->has_had_paid_subscription ?? 0) === 1 || $alreadyUsed || ! $plan) {
                throw ValidationException::withMessages(['trial' => 'Триал недоступен.']);
            }
            $days = max(1, (int) ($plan->trial_duration_days ?? 3));
            $now = time();
            $id = bin2hex(random_bytes(16));
            DB::table('subscriptions')->insert(['id' => $id, 'order_id' => null, 'user_id' => $userId, 'status' => 'provisioning', 'expires_at' => $now + $days * 86400, 'created_at' => $now, 'plan_id' => $planId, 'traffic_limit_gb' => (int) ((int) $plan->traffic_bytes / 1073741824), 'device_limit' => (int) $plan->devices, 'is_trial' => 1, 'start_date' => $now, 'updated_at' => $now]);
            $this->timeline($userId, 'trial.started', ['subscription_id' => $id, 'plan_id' => $planId], $now);
            ProvisionSubscription::dispatch($id)->afterCommit();

            return $id;
        });
    }

    public function setAutoRenew(string $userId, string $subscriptionId, bool $enabled): void
    {
        DB::transaction(function () use ($userId, $subscriptionId, $enabled): void {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $subscription || (int) ($subscription->is_trial ?? 0) === 1) {
                throw ValidationException::withMessages(['subscription' => 'Подписка не поддерживает автопродление.']);
            }
            $plan = DB::table('plans')->where('id', $subscription->plan_id)->first();
            if (! $plan) {
                throw ValidationException::withMessages(['subscription' => 'Тариф не найден.']);
            }
            DB::table('subscriptions')->where('id', $subscriptionId)->update(['auto_renew' => (int) $enabled, 'renew_plan_id' => $plan->id, 'renew_price_minor' => $plan->price_minor, 'renew_at' => $enabled ? max(time(), (int) $subscription->expires_at - 3 * 86400) : null, 'renew_failed_at' => null, 'renew_fail_count' => 0, 'updated_at' => time()]);
        });
    }

    /** @return array{renewed:int,failed:int,expired:int} */
    public function processDue(): array
    {
        $now = time();
        $renewed = 0;
        $failed = 0;
        foreach (DB::table('subscriptions')->where('auto_renew', 1)->whereNotNull('renew_at')->where('renew_at', '<=', $now)->whereIn('status', ['active', 'limited'])->limit(100)->pluck('id') as $id) {
            try {
                $this->renew((string) $id, $now);
                $renewed++;
            } catch (ValidationException) {
                $failed++;
            }
        }
        $expired = 0;
        foreach (DB::table('subscriptions')->where('status', 'active')->where('expires_at', '<=', $now)->limit(100)->pluck('id') as $id) {
            $expired += DB::transaction(function () use ($id, $now): int {
                $subscription = DB::table('subscriptions')->where('id', $id)->lockForUpdate()->first();
                if (! $subscription || $subscription->status !== 'active' || (int) $subscription->expires_at > $now) {
                    return 0;
                }
                SubscriptionStateMachine::assert($subscription->status, 'expired');

                return DB::table('subscriptions')->where('id', $id)->where('status', 'active')->update(['status' => 'expired', 'updated_at' => $now]);
            });
        }

        return compact('renewed', 'failed', 'expired');
    }

    private function renew(string $subscriptionId, int $now): void
    {
        DB::transaction(function () use ($subscriptionId, $now): void {
            $sub = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first();
            if (! $sub || ! (int) $sub->auto_renew || (int) $sub->renew_at > $now) {
                return;
            }
            $plan = DB::table('plans')->where('id', $sub->renew_plan_id ?: $sub->plan_id)->where('active', 1)->first();
            if (! $plan) {
                throw ValidationException::withMessages(['renew' => 'Тариф для продления недоступен.']);
            }
            $price = (int) ($sub->renew_price_minor ?: $plan->price_minor);
            try {
                $this->wallet->debit($sub->user_id, $price, 'subscription_renewal', 'Автопродление: '.$plan->name, $sub->id.':'.((int) $sub->expires_at));
            } catch (ValidationException $e) {
                DB::table('subscriptions')->where('id', $sub->id)->update(['renew_failed_at' => $now, 'renew_fail_count' => (int) $sub->renew_fail_count + 1, 'renew_at' => $now + 86400, 'updated_at' => $now]);
                throw $e;
            }
            $base = max($now, (int) $sub->expires_at);
            $expiry = $base + (int) $plan->duration_days * 86400;
            DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'active', 'expires_at' => $expiry, 'plan_id' => $plan->id, 'renew_plan_id' => $plan->id, 'renew_price_minor' => $plan->price_minor, 'renew_at' => $expiry - 3 * 86400, 'renew_failed_at' => null, 'renew_fail_count' => 0, 'updated_at' => $now]);
            $this->timeline($sub->user_id, 'subscription.renewed', ['subscription_id' => $sub->id, 'amount_kopeks' => $price], $now);
        });
    }

    private function timeline(string $userId, string $event, array $payload, int $at): void
    {
        DB::table('customer_timeline')->insert(['id' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'event_type' => $event, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => $at, 'recorded_at' => (int) floor(microtime(true) * 1_000_000)]);
    }
}
