<?php

namespace Tests;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\ProvisionSubscription;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop a table that the shared legacy schema may reference via FK.
     *
     * PostgreSQL refuses to DROP TABLE on a table that other tables reference
     * via foreign-key constraints, even if the FK triggers are disabled —
     * pg_depend enforces it at the catalog level. The supported escape hatch
     * is DROP TABLE ... CASCADE. We expose it as dropLegacy() so test setUp()
     * methods can keep using a one-liner instead of writing raw SQL each time.
     *
     * SQLite ignores the CASCADE keyword, so this is safe on both drivers.
     */
    protected function dropLegacy(string $table): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS '.DB::getTablePrefix().$table.' CASCADE');

            return;
        }
        Schema::dropIfExists($table);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel 12 attaches Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        // to the "web" group. Routes/web.php also references a custom
        // App\Http\Middleware\VerifyCsrfToken shim for backwards compatibility.
        // Feature tests POST form data without a real token, so disable both.
        // ThrottleRequests is disabled so multiple logins in one suite don't 429.
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
            ThrottleRequests::class,
        ]);
    }

    protected function tearDown(): void
    {
        // The OrderService dispatches ProvisionSubscription with afterCommit().
        // Under the database queue driver this normally records the job in
        // "jobs", but tests construct their fixture tables inside the same
        // test transaction so the dispatch is never persisted. Run the job
        // synchronously here for any subscription that is still mid-flight
        // and let the production code path take over.
        if (DB::getDriverName() === 'pgsql') {
            try {
                $pending = DB::table('subscriptions')
                    ->where('status', 'provisioning')
                    ->whereNull('remote_id')
                    ->pluck('id');
                foreach ($pending as $subscriptionId) {
                    (new ProvisionSubscription($subscriptionId))->handle();
                }
            } catch (\Throwable) {
                // Best-effort drain; tests will assert the final state.
            }
        }

        parent::tearDown();
    }
}
