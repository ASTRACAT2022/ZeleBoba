<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Process;

final class CutoverCheck extends Command
{
    protected $signature = 'cutover:check {--production : Require production-safe configuration}';
    protected $description = 'Verify that the shared legacy schema and Laravel runtime are safe for a cutover rehearsal.';

    public function handle(): int
    {
        $required = [
            'users'=>['id', 'password_hash', 'balance_kopeks', 'totp_secret'],
            'sessions'=>['id', 'user_id', 'csrf', 'admin_verified_until'],
            'orders'=>['id', 'provider', 'provider_payment_id', 'status'],
            'topups'=>['id', 'provider', 'provider_payment_id', 'status'],
            'subscriptions'=>['id', 'auto_renew', 'renew_at', 'is_trial'],
            'customer_timeline'=>['id', 'user_id', 'event_type'],
            'outbox'=>['id', 'topic', 'status'],
            'admin_roles'=>['id', 'permissions', 'is_active'],
            'user_roles'=>['id', 'user_id', 'role_id', 'is_active'],
            'laravel_audit_log'=>['id', 'actor_id', 'action', 'entity_id', 'correlation_id'],
        ];
        $errors = [];
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table)) { $errors[] = "missing table: {$table}"; continue; }
            foreach ($columns as $column) if (!Schema::hasColumn($table, $column)) $errors[] = "missing column: {$table}.{$column}";
        }
        try { DB::select('select 1'); } catch (\Throwable) { $errors[] = 'database is not reachable'; }
        if ($this->option('production')) {
            if (!app()->environment('production')) $errors[] = 'APP_ENV must be production';
            if (config('app.debug')) $errors[] = 'APP_DEBUG must be false';
            if (config('payments.driver') === 'demo') $errors[] = 'PAYMENT_DRIVER=demo is forbidden';
            if (config('queue.default') === 'sync') $errors[] = 'QUEUE_CONNECTION=sync is forbidden';
            if (config('queue.default') !== 'redis') $errors[] = 'QUEUE_CONNECTION=redis is required for the production topology';
            if (!class_exists(\Laravel\Horizon\Horizon::class)) $errors[] = 'Laravel Horizon is not installed';
            if (config('payments.driver') === 'yookassa' && (!(string) config('services.yookassa.shop_id') || !(string) config('services.yookassa.secret'))) $errors[] = 'YooKassa credentials are missing';
            if (config('payments.driver') === 'freekassa' && (!(string) config('services.freekassa.shop_id') || !(string) config('services.freekassa.api_key') || !(string) config('services.freekassa.secret2'))) $errors[] = 'FreeKassa credentials are missing';
            $errors = [...$errors, ...$this->validateEvidence()];
        }
        if ($errors !== []) { foreach ($errors as $error) $this->error($error); return self::FAILURE; }
        $this->info('Cutover preflight passed.');
        return self::SUCCESS;
    }

    /** @return list<string> */
    private function validateEvidence(): array
    {
        $required = ['parity', 'rbac', 'payments', 'provisioning', 'queue-recovery', 'restore', 'security', 'rehearsal', 'rollback'];
        $git = Process::path(base_path('..'))->run(['git', 'rev-parse', 'HEAD']);
        if ($git->failed()) {
            return ['cannot resolve the release commit for cutover evidence'];
        }
        $commit = trim($git->output());
        $errors = [];
        foreach ($required as $name) {
            $path = storage_path("app/cutover/{$name}.json");
            if (!is_file($path)) {
                $errors[] = "missing cutover evidence: {$name}.json";
                continue;
            }
            try {
                $evidence = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $errors[] = "invalid cutover evidence JSON: {$name}.json";
                continue;
            }
            if (!is_array($evidence) || ($evidence['status'] ?? null) !== 'passed') {
                $errors[] = "cutover evidence is not passed: {$name}.json";
            }
            if (($evidence['commit'] ?? null) !== $commit) {
                $errors[] = "cutover evidence commit does not match release: {$name}.json";
            }
            if (!is_string($evidence['tested_at'] ?? null) || strtotime($evidence['tested_at']) === false) {
                $errors[] = "cutover evidence has no valid tested_at: {$name}.json";
            }
            if (!is_string($evidence['environment'] ?? null) || $evidence['environment'] === '') {
                $errors[] = "cutover evidence has no environment: {$name}.json";
            }
        }

        return $errors;
    }
}
