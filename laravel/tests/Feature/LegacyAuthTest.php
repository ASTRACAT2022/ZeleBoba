<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['subscriptions', 'orders', 'plans', 'sessions', 'users'] as $table) {
            $this->dropLegacy($table);
        }
        Schema::create('users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email')->nullable()->unique();
            $table->string('password_hash')->nullable();
            $table->string('telegram_id')->nullable();
            $table->string('role')->default('customer');
            $table->integer('disabled')->default(0);
            $table->integer('promo_offer_discount_percent')->default(0);
            $table->unsignedBigInteger('promo_offer_discount_expires_at')->nullable();
            $table->unsignedBigInteger('created_at');
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('csrf');
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('admin_verified_until')->default(0);
        });
        Schema::create('plans', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('price_minor')->default(0);
            $table->integer('duration_days')->default(30);
            $table->unsignedBigInteger('traffic_bytes')->default(0);
            $table->integer('devices')->default(1);
            $table->integer('active')->default(1);
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('plan_name');
            $table->integer('price_minor');
            $table->string('status');
            $table->unsignedBigInteger('created_at');
        });
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('order_id')->nullable();
            $table->string('plan_id')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('created_at');
        });
    }

    public function test_login_uses_a_session_compatible_with_the_existing_application(): void
    {
        $id = str_repeat('3', 32);
        DB::table('users')->insert(['id' => $id, 'email' => 'user@example.test', 'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID), 'role' => 'customer', 'disabled' => 0, 'created_at' => 1]);

        $response = $this->post('/login', ['email' => 'user@example.test', 'password' => 'correct-horse-battery']);

        $response->assertRedirect('/')->assertCookie('zb_session');
        $token = $response->getCookie('zb_session')->getValue();
        $this->assertDatabaseHas('sessions', ['id' => hash('sha256', $token), 'user_id' => $id]);
        $this->withCookie('zb_session', $token)->get('/')->assertOk();
    }

    public function test_login_accepts_and_upgrades_a_legacy_django_password_hash(): void
    {
        $id = str_repeat('4', 32);
        $password = 'correct-horse-battery';
        $salt = 'legacy-salt';
        $derived = base64_encode(hash_pbkdf2('sha256', $password, $salt, 10_000, 32, true));
        DB::table('users')->insert(['id' => $id, 'email' => 'legacy@example.test', 'password_hash' => 'pbkdf2_sha256$10000$'.$salt.'$'.$derived, 'role' => 'customer', 'disabled' => 0, 'created_at' => 1]);

        $this->post('/login', ['email' => 'legacy@example.test', 'password' => $password])->assertRedirect('/');
        $this->assertStringStartsWith('$argon2id$', DB::table('users')->where('id', $id)->value('password_hash'));
    }

    public function test_plans_apply_the_customer_personal_discount(): void
    {
        $id = str_repeat('5', 32);
        $token = str_repeat('b', 64);
        DB::table('users')->insert(['id' => $id, 'email' => 'discount@example.test', 'password_hash' => password_hash('correct-horse-battery', PASSWORD_ARGON2ID), 'role' => 'customer', 'disabled' => 0, 'promo_offer_discount_percent' => 10, 'created_at' => 1]);
        DB::table('sessions')->insert(['id' => hash('sha256', $token), 'user_id' => $id, 'csrf' => 'csrf', 'expires_at' => time() + 60]);
        DB::table('plans')->insert(['id' => 'basic', 'name' => 'Старт', 'price_minor' => 19900, 'duration_days' => 30, 'traffic_bytes' => 0, 'devices' => 3, 'active' => 1]);

        $this->withCookie('zb_session', $token)->get('/plans')->assertOk()->assertSee('179 ₽')->assertSee('Старт');
    }
}
