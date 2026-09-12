<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['user_roles', 'admin_roles', 'sessions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email')->nullable();
            $table->string('role')->default('customer');
            $table->integer('disabled')->default(0);
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
            $table->text('permissions');
            $table->integer('is_active');
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('role_id');
            $table->integer('is_active');
            $table->unsignedBigInteger('expires_at')->nullable();
        });
    }

    public function test_mfa_alone_does_not_grant_staff_access(): void
    {
        $id = str_repeat('a', 32);
        $token = str_repeat('b', 64);
        DB::table('users')->insert(['id' => $id, 'email' => 'customer@example.test', 'role' => 'customer', 'disabled' => 0]);
        DB::table('sessions')->insert(['id' => hash('sha256', $token), 'user_id' => $id, 'csrf' => 'csrf', 'expires_at' => time() + 60, 'admin_verified_until' => time() + 60]);

        $this->withCookie('zb_session', $token)->get('/admin/users/'.str_repeat('c', 32))->assertForbidden();
    }
}
