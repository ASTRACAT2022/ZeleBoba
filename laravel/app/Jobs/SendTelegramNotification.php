<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class SendTelegramNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public array $backoff = [5, 30, 120, 300];

    public function __construct(public readonly string $userId, public readonly string $chatId, public readonly string $text)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return;
        }
        $response = Http::acceptJson()->timeout(20)->post(rtrim((string) config('services.telegram.api_base'), '/').'/bot'.$token.'/sendMessage', ['chat_id' => $this->chatId, 'text' => $this->text]);
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new \RuntimeException('Telegram rejected notification.');
        }
        DB::table('customer_timeline')->insert(['id' => bin2hex(random_bytes(16)), 'user_id' => $this->userId, 'event_type' => 'telegram.notification_delivered', 'payload' => '{}', 'occurred_at' => time(), 'recorded_at' => (int) floor(microtime(true) * 1_000_000)]);
    }
}
