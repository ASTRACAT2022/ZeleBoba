<?php
declare(strict_types=1);

use App\Web\RailsPresentationGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\{MockHttpClient};
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class RailsPresentationGatewayTest extends TestCase
{
    public function testOrderGatewayForwardsExistingFormAndReturns303(): void
    {
        $calls = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls) {
            $calls[] = [$method, $url, $options];
            return new MockResponse('{"id":"ord_123","status":"pending"}', [
                'http_code' => 201,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        $request = Request::create('/orders', 'POST', [
            '_csrf' => 'csrf-token', 'plan_id' => 'plan_1',
            'idempotency_key' => 'key-1', 'receipt_email' => 'a@example.com',
        ], ['zb_session' => 'session_token'], [], ['REMOTE_ADDR' => '203.0.113.8']);

        $redirect = (new RailsPresentationGateway([
            'RAILS_WRITE_GATEWAY_ENABLED' => '1', 'RAILS_INTERNAL_URL' => 'http://rails-api:3000',
        ], $http))->createOrder($request);

        self::assertSame(303, $redirect?->getStatusCode());
        self::assertSame('/orders/ord_123', $redirect?->getTargetUrl());
        self::assertCount(1, $calls);
        [$method, $url, $options] = $calls[0];
        self::assertSame('POST', $method);
        self::assertSame('http://rails-api:3000/rails/orders', $url);
        $headers = array_change_key_case(array_reduce($options['headers'], static function (array $headers, string $line): array {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
            return $headers;
        }, []));
        self::assertSame('zb_session=session_token', $headers['cookie']);
        self::assertSame('203.0.113.8', $headers['x-forwarded-for']);
        parse_str($options['body'], $form);
        self::assertSame('plan_1', $form['plan_id']);
        self::assertSame('csrf-token', $form['_csrf']);
        self::assertSame('a@example.com', $form['receipt_email']);
    }

    public function testTopupConvertsRublesToKopeks(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            parse_str($options['body'], $form);
            self::assertSame('12300', $form['amount_kopeks']);
            self::assertSame('platega', $form['provider']);
            self::assertSame('csrf-token', $form['_csrf']);
            self::assertSame('key-2', $form['idempotency_key']);
            return new MockResponse('{"id":"top_456"}', [
                'http_code' => 201,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        $request = Request::create('/balance/topup', 'POST', [
            '_csrf' => 'csrf-token', 'amount' => '123', 'provider' => 'platega',
            'idempotency_key' => 'key-2',
        ], ['zb_session' => 'session_token']);

        $redirect = (new RailsPresentationGateway([
            'RAILS_WRITE_GATEWAY_ENABLED' => '1', 'RAILS_INTERNAL_URL' => 'http://rails-api:3000',
        ], $http))->createTopup($request);

        self::assertSame(303, $redirect?->getStatusCode());
        self::assertSame('/balance/topup/top_456', $redirect?->getTargetUrl());
    }

    public function testBalancePurchasePreservesPlanKeyAndRedirectsHome(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('http://rails-api:3000/rails/me/orders/balance', $url);
            parse_str($options['body'], $form);
            self::assertSame('csrf-token', $form['_csrf']);
            self::assertSame('plan_2', $form['plan_id']);
            self::assertSame('balance:12345678:plan_2', $form['idempotency_key']);
            return new MockResponse('{"id":"ord_balance"}', [
                'http_code' => 201,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        $request = Request::create('/orders/balance', 'POST', [
            '_csrf' => 'csrf-token', 'plan_id' => 'plan_2',
            'idempotency_key' => 'balance:12345678:plan_2',
        ], ['zb_session' => 'session_token']);

        $redirect = (new RailsPresentationGateway([
            'RAILS_WRITE_GATEWAY_ENABLED' => '1', 'RAILS_INTERNAL_URL' => 'http://rails-api:3000',
        ], $http))->createBalanceOrder($request);

        self::assertSame(303, $redirect?->getStatusCode());
        self::assertSame('/', $redirect?->getTargetUrl());
    }

    public function testRenewalPreservesExistingIdempotencyAndOrderRedirect(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('http://rails-api:3000/rails/me/subscriptions/sub_123/renew', $url);
            parse_str($options['body'], $form);
            self::assertSame('csrf-token', $form['_csrf']);
            self::assertSame('sub_123:1758800000', $form['idempotency_key']);
            self::assertSame('client@example.com', $form['receipt_email']);
            return new MockResponse('{"id":"ord_renew"}', [
                'http_code' => 201,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        $request = Request::create('/subscriptions/sub_123/renew', 'POST', [
            '_csrf' => 'csrf-token', 'key' => 'sub_123:1758800000', 'receipt_email' => 'client@example.com',
        ], ['zb_session' => 'session_token']);

        $redirect = (new RailsPresentationGateway([
            'RAILS_WRITE_GATEWAY_ENABLED' => '1', 'RAILS_INTERNAL_URL' => 'http://rails-api:3000',
        ], $http))->createRenewal($request, 'sub_123');

        self::assertSame(303, $redirect?->getStatusCode());
        self::assertSame('/orders/ord_renew', $redirect?->getTargetUrl());
    }

    public function testGiftPurchaseMapsTwigMessageAndRedirectsToGiftList(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('http://rails-api:3000/rails/me/gifts', $url);
            parse_str($options['body'], $form);
            self::assertSame('csrf-token', $form['_csrf']);
            self::assertSame('plan_gift', $form['plan_id']);
            self::assertSame('gift-key-1234', $form['idempotency_key']);
            self::assertSame('', $form['recipient_type']);
            self::assertSame('@friend', $form['recipient_value']);
            self::assertSame('Поздравляю!', $form['message']);
            return new MockResponse('{"id":"gift_123"}', [
                'http_code' => 201,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        $request = Request::create('/gifts/buy', 'POST', [
            '_csrf' => 'csrf-token', 'plan_id' => 'plan_gift', 'idempotency_key' => 'gift-key-1234',
            'recipient_value' => '@friend', 'gift_message' => 'Поздравляю!',
        ], ['zb_session' => 'session_token']);

        $redirect = (new RailsPresentationGateway([
            'RAILS_WRITE_GATEWAY_ENABLED' => '1', 'RAILS_INTERNAL_URL' => 'http://rails-api:3000',
        ], $http))->createGiftPurchase($request);

        self::assertSame(303, $redirect?->getStatusCode());
        self::assertSame('/gifts', $redirect?->getTargetUrl());
    }

    public function testReferralWithdrawalConvertsRublesAndRedirectsToReferralPage(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('http://rails-api:3000/rails/me/referral/withdrawals', $url);
            parse_str($options['body'], $form);
            self::assertSame('csrf-token', $form['_csrf']);
            self::assertSame('100000', $form['amount_kopeks']);
            self::assertSame('wallet details', $form['payment_details']);
            return new MockResponse('{"id":"withdrawal_123"}', [
                'http_code' => 201,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        $request = Request::create('/referral/withdraw', 'POST', [
            '_csrf' => 'csrf-token', 'amount' => '1000', 'payment_details' => 'wallet details',
        ], ['zb_session' => 'session_token']);

        $redirect = (new RailsPresentationGateway([
            'RAILS_WRITE_GATEWAY_ENABLED' => '1', 'RAILS_INTERNAL_URL' => 'http://rails-api:3000',
        ], $http))->createReferralWithdrawal($request);

        self::assertSame(303, $redirect?->getStatusCode());
        self::assertSame('/referral', $redirect?->getTargetUrl());
    }

    public function testGatewayIsOffByDefault(): void
    {
        $called = false;
        $http = new MockHttpClient(function () use (&$called) {
            $called = true;
            return new MockResponse('{}');
        });
        $request = Request::create('/orders', 'POST', ['plan_id' => 'plan_1']);

        self::assertNull((new RailsPresentationGateway([], $http))->createOrder($request));
        self::assertFalse($called);
    }

    public function testRailsErrorFailsClosedWithoutAFormRedirect(): void
    {
        $http = new MockHttpClient(fn () => new MockResponse('{"error":"rejected"}', [
            'http_code' => 422,
            'response_headers' => ['content-type: application/json'],
        ]));
        $request = Request::create('/orders', 'POST', [
            '_csrf' => 'csrf-token', 'plan_id' => 'plan_1', 'idempotency_key' => 'key-1',
        ], ['zb_session' => 'session_token']);

        try {
            (new RailsPresentationGateway([
                'RAILS_WRITE_GATEWAY_ENABLED' => '1', 'RAILS_INTERNAL_URL' => 'http://rails-api:3000',
            ], $http))->createOrder($request);
            self::fail('Rails errors must not fall back to PHP or redirect as success.');
        } catch (RuntimeException $error) {
            self::assertSame('Rails checkout endpoint rejected the request', $error->getMessage());
        }
    }
}
