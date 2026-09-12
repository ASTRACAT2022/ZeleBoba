<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class VerifiedTopupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['customer_timeline', 'transactions', 'topups', 'payment_receipts', 'orders', 'sessions', 'users'] as $table) Schema::dropIfExists($table);
        Schema::create('users', function (Blueprint $t): void { $t->string('id')->primary(); $t->string('email'); $t->string('telegram_id')->nullable(); $t->string('role')->default('customer'); $t->integer('disabled')->default(0); $t->integer('promo_offer_discount_percent')->default(0); $t->unsignedBigInteger('promo_offer_discount_expires_at')->nullable(); $t->bigInteger('balance_kopeks')->default(0); $t->integer('has_made_first_topup')->default(0); });
        Schema::create('sessions', function (Blueprint $t): void { $t->string('id')->primary(); $t->string('user_id'); $t->string('csrf'); $t->unsignedBigInteger('expires_at'); $t->unsignedBigInteger('admin_verified_until')->default(0); });
        Schema::create('topups', function (Blueprint $t): void { $t->string('id')->primary(); $t->string('user_id'); $t->integer('amount_kopeks'); $t->string('currency'); $t->string('status'); $t->string('provider'); $t->string('provider_payment_id')->nullable()->unique(); $t->text('checkout_url')->nullable(); $t->string('idempotency_key'); $t->integer('referral_first')->default(0); $t->integer('created_at'); $t->integer('paid_at')->nullable(); $t->unique(['user_id', 'idempotency_key']); });
        Schema::create('transactions', function (Blueprint $t): void { $t->string('id')->primary(); $t->integer('seq'); $t->string('user_id'); $t->string('type'); $t->integer('amount_kopeks'); $t->text('description')->nullable(); $t->string('payment_method')->nullable(); $t->string('external_id')->nullable(); $t->integer('is_completed'); $t->integer('created_at'); $t->integer('completed_at')->nullable(); });
        Schema::create('orders', function (Blueprint $t): void { $t->string('id')->primary(); });
        Schema::create('payment_receipts', function (Blueprint $t): void { $t->string('provider'); $t->string('payment_id'); $t->primary(['provider', 'payment_id']); });
        Schema::create('customer_timeline', function (Blueprint $t): void { $t->string('id')->primary(); $t->string('user_id'); $t->string('event_type'); $t->text('payload'); $t->integer('occurred_at'); $t->unsignedBigInteger('recorded_at'); });
    }

    public function test_yookassa_topup_is_created_and_credited_once_after_remote_verification(): void
    {
        config(['payments.driver'=>'yookassa', 'services.yookassa.shop_id'=>'shop', 'services.yookassa.secret'=>'secret']);
        $user = str_repeat('1', 32); $token = str_repeat('a', 64);
        DB::table('users')->insert(['id'=>$user, 'email'=>'wallet@example.test']);
        DB::table('sessions')->insert(['id'=>hash('sha256', $token), 'user_id'=>$user, 'csrf'=>'csrf', 'expires_at'=>time()+60]);
        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response(['id'=>'yoo-topup-1', 'confirmation'=>['confirmation_url'=>'https://pay.example/topup']], 200),
            'https://api.yookassa.ru/v3/payments/yoo-topup-1' => Http::response(['id'=>'yoo-topup-1', 'status'=>'succeeded', 'paid'=>true, 'metadata'=>['topup_id'=>str_repeat('2', 32)], 'amount'=>['value'=>'500.00', 'currency'=>'RUB']], 200),
        ]);

        $this->withCookie('zb_session', $token)->post('/balance/topup', ['amount'=>500, 'idempotency_key'=>'topup-key-123'])->assertRedirect();
        $topup = DB::table('topups')->first();
        DB::table('topups')->where('id', $topup->id)->update(['id'=>str_repeat('2', 32)]);
        $this->postJson('/webhooks/yookassa', ['event'=>'payment.succeeded', 'object'=>['id'=>'yoo-topup-1']])->assertNoContent();
        $this->postJson('/webhooks/yookassa', ['event'=>'payment.succeeded', 'object'=>['id'=>'yoo-topup-1']])->assertNoContent();

        $this->assertDatabaseHas('topups', ['id'=>str_repeat('2',32), 'status'=>'paid', 'provider_payment_id'=>'yoo-topup-1']);
        $this->assertDatabaseHas('users', ['id'=>$user, 'balance_kopeks'=>50000, 'has_made_first_topup'=>1]);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('customer_timeline', 1);
    }
}
