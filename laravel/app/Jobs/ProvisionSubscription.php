<?php

namespace App\Jobs;

use App\Domain\Subscriptions\SubscriptionStateMachine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class ProvisionSubscription implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public array $backoff = [5, 15, 45, 120, 300];

    public function __construct(public readonly string $subscriptionId)
    {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $subscription = DB::table('subscriptions')->where('id', $this->subscriptionId)->lockForUpdate()->first();
            if (! $subscription || $subscription->status !== 'provisioning' || $subscription->remote_id !== null) {
                return;
            }
            $now = time();
            $this->timeline($subscription->user_id, 'vpn.provisioning_started', ['subscription_id' => $subscription->id], $now);
            $remote = $this->provision($subscription);
            SubscriptionStateMachine::assert($subscription->status, 'active');
            DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'active', 'remote_id' => $remote['id'], 'subscription_url' => $remote['url']]);
            DB::table('orders')->where('id', $subscription->order_id)->update(['status' => 'fulfilled']);
            $this->timeline($subscription->user_id, 'vpn.resource_updated', ['subscription_id' => $subscription->id], $now);
            $this->timeline($subscription->user_id, 'subscription.active', ['subscription_id' => $subscription->id], $now);
            $telegramId = DB::table('users')->where('id', $subscription->user_id)->value('telegram_id');
            if ($telegramId) {
                SendTelegramNotification::dispatch((string) $subscription->user_id, (string) $telegramId, 'Подписка готова. Откройте веб-кабинет или отправьте /status.')->afterCommit();
            }
        });
    }

    /** @return array{id:string,url:?string} */
    private function provision(object $subscription): array
    {
        if (config('payments.provision_driver') === 'demo') {
            return ['id' => 'demo_'.$subscription->id, 'url' => null];
        }
        if (config('payments.provision_driver') !== 'remnawave') {
            throw new \RuntimeException('Unsupported provisioning driver.');
        }
        $base = rtrim((string) config('services.remnawave.url'), '/');
        $token = (string) config('services.remnawave.token');
        $squad = (string) config('services.remnawave.squad');
        if (! str_starts_with($base, 'https://') || $token === '' || $squad === '') {
            throw new \RuntimeException('Remnawave configuration missing.');
        }
        $username = 'zb_'.$subscription->id;
        $client = Http::acceptJson()->withToken($token)->timeout(20);
        $response = $client->get($base.'/api/users/by-username/'.$username);
        if ($response->status() === 404) {
            $response = $client->post($base.'/api/users', ['username' => $username, 'status' => 'ACTIVE', 'expireAt' => gmdate('Y-m-d\\TH:i:s\\Z', (int) $subscription->expires_at), 'trafficLimitBytes' => (int) $subscription->traffic_limit_gb * 1073741824, 'trafficLimitStrategy' => 'NO_RESET', 'hwidDeviceLimit' => (int) $subscription->device_limit, 'activeInternalSquads' => [$squad]]);
            // Another worker may have created the deterministic username while
            // this request was in flight. Recover it instead of duplicating.
            if ($response->status() === 409) {
                $response = $client->get($base.'/api/users/by-username/'.$username);
            }
        }
        if (! $response->successful()) {
            throw new \RuntimeException('Remnawave provisioning failed.');
        }
        $user = $response->json('response');
        $id = $user['id'] ?? null;
        $url = $user['subscriptionUrl'] ?? null;
        if (($user['username'] ?? null) !== $username || ! $id || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Invalid Remnawave response.');
        }

        return ['id' => (string) $id, 'url' => $url];
    }

    /** @param array<string, scalar> $payload */
    private function timeline(string $userId, string $event, array $payload, int $at): void
    {
        DB::table('customer_timeline')->insert(['id' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'event_type' => $event, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => $at, 'recorded_at' => (int) floor(microtime(true) * 1_000_000)]);
    }
}
