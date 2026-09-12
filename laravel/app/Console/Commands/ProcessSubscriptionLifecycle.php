<?php

namespace App\Console\Commands;

use App\Services\SubscriptionLifecycleService;
use Illuminate\Console\Command;

final class ProcessSubscriptionLifecycle extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Renew due subscriptions from balance and expire overdue subscriptions.';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $result = $lifecycle->processDue();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
