<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RbacAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['laravel_audit_log', 'customer_timeline', 'user_roles', 'admin_roles', 'sessions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email')->nullable();
            $table->string('role')->default('customer');
            $table->integer('disabled')->default(0);
            $table->unsignedBigInteger('created_at');
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('csrf');
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('admin_verified_until');
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->text('permissions');
            $table->integer('is_active')->default(1);
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('role_id');
            $table->integer('is_active')->default(1);
            $table->unsignedBigInteger('expires_at')->nullable();
        });
        Schema::create('customer_timeline', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('event_type');
            $table->text('payload');
            $table->unsignedBigInteger('occurred_at');
            $table->unsignedBigInteger('recorded_at');
        });
        Schema::create('laravel_audit_log', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->text('before_json')->nullable();
            $table->text('after_json')->nullable();
            $table->text('metadata_json');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('correlation_id')->nullable();
            $table->unsignedBigInteger('created_at');
        });
    }

    public function test_staff_role_only_receives_its_explicit_permission(): void
    {
        [$staff, $target, $token] = $this->seedStaff(['users.view']);
        DB::table('customer_timeline')->insert(['id' => 'event-1', 'user_id' => $target, 'event_type' => 'payment.created', 'payload' => '{"amount_kopeks":100}', 'occurred_at' => 1, 'recorded_at' => 1]);

        $this->withCookie('zb_session', $token)->get('/admin/users/'.$target)->assertOk();
        $this->withCookie('zb_session', $token)->post('/api/v1/admin/users/'.$target.'/block')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target, 'disabled' => 0]);
    }

    public function test_block_action_requires_permission_and_writes_complete_audit_record(): void
    {
        [$staff, $target, $token] = $this->seedStaff(['users.block']);

        $this->withCookie('zb_session', $token)->post('/api/v1/admin/users/'.$target.'/block')->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target, 'disabled' => 1]);
        $this->assertDatabaseHas('laravel_audit_log', [
            'actor_type' => 'user',
            'actor_id' => $staff,
            'action' => 'user.blocked',
            'entity_type' => 'user',
            'entity_id' => $target,
            'before_json' => '{"disabled":0}',
            'after_json' => '{"disabled":1}',
        ]);
    }

    /** @param list<string> $permissions @return array{string,string,string} */
    private function seedStaff(array $permissions): array
    {
        $staff = str_repeat('a', 32);
        $target = str_repeat('b', 32);
        $role = str_repeat('c', 32);
        $token = str_repeat('d', 64);
        DB::table('users')->insert([
            ['id' => $staff, 'email' => 'staff@example.test', 'role' => 'customer', 'disabled' => 0, 'created_at' => 1],
            ['id' => $target, 'email' => 'user@example.test', 'role' => 'customer', 'disabled' => 0, 'created_at' => 1],
        ]);
        DB::table('sessions')->insert(['id' => hash('sha256', $token), 'user_id' => $staff, 'csrf' => 'csrf', 'expires_at' => time() + 60, 'admin_verified_until' => time() + 60]);
        DB::table('admin_roles')->insert(['id' => $role, 'name' => 'support', 'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR), 'is_active' => 1]);
        DB::table('user_roles')->insert(['id' => str_repeat('e', 32), 'user_id' => $staff, 'role_id' => $role, 'is_active' => 1]);

        return [$staff, $target, $token];
    }
}
