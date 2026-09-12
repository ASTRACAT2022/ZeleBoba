<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class AdminCustomerTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['customer_timeline', 'sessions', 'users'] as $table) Schema::dropIfExists($table);
        Schema::create('users', function (Blueprint $table): void { $table->string('id')->primary(); $table->string('email')->nullable(); $table->string('role'); $table->integer('disabled')->default(0); $table->unsignedBigInteger('created_at'); });
        Schema::create('sessions', function (Blueprint $table): void { $table->string('id')->primary(); $table->string('user_id'); $table->string('csrf'); $table->unsignedBigInteger('expires_at'); $table->unsignedBigInteger('admin_verified_until'); });
        Schema::create('customer_timeline', function (Blueprint $table): void { $table->string('id')->primary(); $table->string('user_id'); $table->string('event_type'); $table->text('payload'); $table->unsignedBigInteger('occurred_at'); $table->unsignedBigInteger('recorded_at'); });
    }

    public function test_mfa_verified_legacy_admin_can_read_customer_timeline(): void
    {
        $adminId = str_repeat('1', 32);
        $customerId = str_repeat('2', 32);
        $token = str_repeat('a', 64);
        DB::table('users')->insert([['id'=>$adminId, 'email'=>'admin@example.test', 'role'=>'admin', 'disabled'=>0, 'created_at'=>1], ['id'=>$customerId, 'email'=>'user@example.test', 'role'=>'customer', 'disabled'=>0, 'created_at'=>1]]);
        DB::table('sessions')->insert(['id'=>hash('sha256', $token), 'user_id'=>$adminId, 'csrf'=>'csrf', 'expires_at'=>time()+60, 'admin_verified_until'=>time()+60]);
        DB::table('customer_timeline')->insert(['id'=>'event-1', 'user_id'=>$customerId, 'event_type'=>'payment.created', 'payload'=>'{"amount_kopeks":19900}', 'occurred_at'=>1, 'recorded_at'=>1]);

        $this->withCookie('zb_session', $token)->get('/admin/users/'.$customerId)
            ->assertOk()->assertSee('Payment created — 199 ₽');
    }
}
