<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DemoCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['customer_timeline', 'subscriptions', 'ledger_entries', 'payment_receipts', 'orders', 'plans', 'sessions', 'users'] as $table) {
            $this->dropLegacy($table);
        }
        Schema::create('users', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('email');
            $t->string('telegram_id')->nullable();
            $t->string('role')->default('customer');
            $t->integer('disabled')->default(0);
            $t->integer('promo_offer_discount_percent')->default(0);
            $t->unsignedBigInteger('promo_offer_discount_expires_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('user_id');
            $t->string('csrf');
            $t->unsignedBigInteger('expires_at');
            $t->unsignedBigInteger('admin_verified_until')->default(0);
        });
        Schema::create('plans', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('price_minor');
            $t->string('currency');
            $t->integer('duration_days');
            $t->unsignedBigInteger('traffic_bytes');
            $t->integer('devices');
            $t->integer('active');
        });
        Schema::create('orders', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('user_id');
            $t->string('plan_id');
            $t->string('idempotency_key');
            $t->integer('price_minor');
            $t->string('currency');
            $t->string('plan_name');
            $t->integer('duration_days');
            $t->unsignedBigInteger('traffic_bytes');
            $t->integer('devices');
            $t->string('status');
            $t->string('provider');
            $t->string('provider_payment_id')->nullable();
            $t->text('checkout_url')->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('paid_at')->nullable();
            $t->unique(['user_id', 'idempotency_key']);
        });
        Schema::create('payment_receipts', function (Blueprint $t): void {
            $t->string('provider');
            $t->string('payment_id');
            $t->string('order_id');
            $t->integer('amount_minor');
            $t->string('currency');
            $t->unsignedBigInteger('created_at');
            $t->primary(['provider', 'payment_id']);
        });
        Schema::create('ledger_entries', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('order_id');
            $t->string('account');
            $t->integer('amount_minor');
            $t->string('currency');
            $t->unsignedBigInteger('created_at');
        });
        Schema::create('subscriptions', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('order_id');
            $t->string('user_id');
            $t->string('status');
            $t->unsignedBigInteger('expires_at');
            $t->unsignedBigInteger('created_at');
            $t->string('plan_id');
            $t->integer('traffic_limit_gb');
            $t->integer('device_limit');
            $t->string('remote_id')->nullable();
            $t->text('subscription_url')->nullable();
        });
        Schema::create('customer_timeline', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('user_id');
            $t->string('event_type');
            $t->text('payload');
            $t->unsignedBigInteger('occurred_at');
            $t->unsignedBigInteger('recorded_at');
        });
    }

    public function test_demo_payment_is_idempotent_and_provisions_the_subscription(): void
    {
        $user = str_repeat('6', 32);
        $token = str_repeat('c', 64);
        DB::table('users')->insert(['id' => $user, 'email' => 'buyer@example.test', 'role' => 'customer', 'disabled' => 0]);
        DB::table('sessions')->insert(['id' => hash('sha256', $token), 'user_id' => $user, 'csrf' => 'csrf', 'expires_at' => time() + 60]);
        DB::table('plans')->insert(['id' => 'basic', 'name' => 'Старт', 'price_minor' => 19900, 'currency' => 'RUB', 'duration_days' => 30, 'traffic_bytes' => 0, 'devices' => 3, 'active' => 1]);

        $this->withCookie('zb_session', $token)->post('/orders', ['plan_id' => 'basic', 'idempotency_key' => 'checkout-key-123'])->assertRedirect();
        $order = DB::table('orders')->first();
        $this->withCookie('zb_session', $token)->post('/orders/'.$order->id.'/demo-pay')->assertRedirect('/orders/'.$order->id);
        $this->withCookie('zb_session', $token)->post('/orders/'.$order->id.'/demo-pay')->assertRedirect('/orders/'.$order->id);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'fulfilled']);
        $this->assertDatabaseHas('subscriptions', ['order_id' => $order->id, 'status' => 'active']);
        $this->assertSame(1, DB::table('payment_receipts')->count());
        $this->assertSame(['payment.created', 'payment.paid', 'vpn.provisioning_started', 'vpn.resource_updated', 'subscription.active'], DB::table('customer_timeline')->orderBy('recorded_at')->pluck('event_type')->all());
    }

    public function test_yookassa_webhook_is_verified_by_yookassa_before_settlement(): void
    {
        config(['services.yookassa.shop_id' => 'shop', 'services.yookassa.secret' => 'secret']);
        $user = str_repeat('7', 32);
        $orderId = str_repeat('8', 32);
        DB::table('users')->insert(['id' => $user, 'email' => 'yoo@example.test', 'role' => 'customer', 'disabled' => 0]);
        DB::table('orders')->insert(['id' => $orderId, 'user_id' => $user, 'plan_id' => 'basic', 'idempotency_key' => 'yookassa-key-123', 'price_minor' => 19900, 'currency' => 'RUB', 'plan_name' => 'Старт', 'duration_days' => 30, 'traffic_bytes' => 0, 'devices' => 3, 'status' => 'pending', 'provider' => 'yookassa', 'created_at' => time()]);
        Http::fake(['https://api.yookassa.ru/v3/payments/payment-1' => Http::response(['id' => 'payment-1', 'status' => 'succeeded', 'paid' => true, 'metadata' => ['order_id' => $orderId], 'amount' => ['value' => '199.00', 'currency' => 'RUB']], 200)]);

        $this->postJson('/webhooks/yookassa', ['event' => 'payment.succeeded', 'object' => ['id' => 'payment-1']])->assertNoContent();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'fulfilled', 'provider_payment_id' => 'payment-1']);
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_yookassa_checkout_is_created_with_an_idempotency_key(): void
    {
        config(['payments.driver' => 'yookassa', 'services.yookassa.shop_id' => 'shop', 'services.yookassa.secret' => 'secret']);
        $user = str_repeat('9', 32);
        $token = str_repeat('d', 64);
        DB::table('users')->insert(['id' => $user, 'email' => 'checkout@example.test', 'role' => 'customer', 'disabled' => 0]);
        DB::table('sessions')->insert(['id' => hash('sha256', $token), 'user_id' => $user, 'csrf' => 'csrf', 'expires_at' => time() + 60]);
        DB::table('plans')->insert(['id' => 'basic', 'name' => 'Старт', 'price_minor' => 19900, 'currency' => 'RUB', 'duration_days' => 30, 'traffic_bytes' => 0, 'devices' => 3, 'active' => 1]);
        Http::fake(['https://api.yookassa.ru/v3/payments' => Http::response(['id' => 'payment-2', 'confirmation' => ['confirmation_url' => 'https://pay.example/2']], 200)]);

        $this->withCookie('zb_session', $token)->post('/orders', ['plan_id' => 'basic', 'idempotency_key' => 'checkout-key-yookassa'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['provider' => 'yookassa', 'provider_payment_id' => 'payment-2', 'checkout_url' => 'https://pay.example/2']);
    }

    public function test_freekassa_checkout_is_signed_and_persisted(): void
    {
        config(['payments.driver' => 'freekassa', 'services.freekassa.shop_id' => '123', 'services.freekassa.api_key' => 'api-key', 'services.freekassa.payment_id' => '44']);
        $user = str_repeat('a', 32);
        $token = str_repeat('e', 64);
        DB::table('users')->insert(['id' => $user, 'email' => 'fk@example.test', 'role' => 'customer', 'disabled' => 0]);
        DB::table('sessions')->insert(['id' => hash('sha256', $token), 'user_id' => $user, 'csrf' => 'csrf', 'expires_at' => time() + 60]);
        DB::table('plans')->insert(['id' => 'basic', 'name' => 'Старт', 'price_minor' => 19900, 'currency' => 'RUB', 'duration_days' => 30, 'traffic_bytes' => 0, 'devices' => 3, 'active' => 1]);
        Http::fake(['https://api.fk.life/v1/orders/create' => Http::response(['type' => 'success', 'orderId' => 321, 'location' => 'https://pay.fk.example/321'], 200)]);

        $this->withCookie('zb_session', $token)->post('/orders', ['plan_id' => 'basic', 'idempotency_key' => 'checkout-key-freekassa'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['provider' => 'freekassa', 'provider_payment_id' => '321', 'checkout_url' => 'https://pay.fk.example/321']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.fk.life/v1/orders/create' && $request['paymentId'] !== null && $request['signature'] !== null);
    }

    public function test_freekassa_webhook_signature_and_api_verification_are_required(): void
    {
        config(['services.freekassa.shop_id' => '123', 'services.freekassa.api_key' => 'api-key', 'services.freekassa.secret2' => 'secret-word']);
        $user = str_repeat('b', 32);
        $orderId = str_repeat('c', 32);
        DB::table('users')->insert(['id' => $user, 'email' => 'fk-webhook@example.test', 'role' => 'customer', 'disabled' => 0]);
        DB::table('orders')->insert(['id' => $orderId, 'user_id' => $user, 'plan_id' => 'basic', 'idempotency_key' => 'freekassa-webhook-key', 'price_minor' => 19900, 'currency' => 'RUB', 'plan_name' => 'Старт', 'duration_days' => 30, 'traffic_bytes' => 0, 'devices' => 3, 'status' => 'pending', 'provider' => 'freekassa', 'created_at' => time()]);
        Http::fake(['https://api.fk.life/v1/orders' => Http::response(['type' => 'success', 'orders' => [['status' => 1, 'merchant_order_id' => $orderId, 'fk_order_id' => 999, 'amount' => '199.00', 'currency' => 'RUB']]], 200)]);
        $amount = '199.00';
        $sign = md5('123:'.$amount.':secret-word:'.$orderId);

        $this->post('/webhooks/freekassa', ['MERCHANT_ID' => '123', 'AMOUNT' => $amount, 'MERCHANT_ORDER_ID' => $orderId, 'SIGN' => $sign])->assertOk()->assertSee('YES');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'fulfilled', 'provider_payment_id' => '999']);
        $this->post('/webhooks/freekassa', ['MERCHANT_ID' => '123', 'AMOUNT' => $amount, 'MERCHANT_ORDER_ID' => $orderId, 'SIGN' => 'invalid'])->assertStatus(400);
    }
}
