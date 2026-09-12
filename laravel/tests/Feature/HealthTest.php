<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_liveness_never_depends_on_external_services(): void
    {
        $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_checks_the_database_without_calling_providers(): void
    {
        DB::select('select 1');
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('dependencies.database', 'ok')
            ->assertJsonPath('dependencies.queue', 'ok');
    }

    public function test_production_readiness_rejects_a_non_redis_queue(): void
    {
        config(['app.env' => 'production', 'queue.default' => 'database']);

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('dependencies.queue', 'unavailable');
    }
}
