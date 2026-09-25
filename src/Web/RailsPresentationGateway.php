<?php
declare(strict_types=1);

namespace App\Web;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\{RedirectResponse, Request};

/** Opt-in bridge for existing browser purchase and subscription forms. */
final class RailsPresentationGateway
{
    private const MAX_RESPONSE_BYTES = 16_384;

    public function __construct(
        private readonly array $config,
        private readonly ?HttpClientInterface $http = null
    ) {}

    public function createOrder(Request $request): ?RedirectResponse
    {
        if (!$this->enabled()) return null;

        $payload = [
            '_csrf' => (string)$request->request->get('_csrf', ''),
            'plan_id' => (string)$request->request->get('plan_id', ''),
            'idempotency_key' => (string)$request->request->get('idempotency_key', ''),
        ];
        foreach (['receipt_email', 'landing_slug', 'renew_subscription_id'] as $field) {
            if ($request->request->has($field)) $payload[$field] = (string)$request->request->get($field);
        }

        return $this->forward($request, '/rails/orders', $payload, '/orders/');
    }

    public function createTopup(Request $request): ?RedirectResponse
    {
        if (!$this->enabled()) return null;

        $amountRubles = filter_var($request->request->get('amount'), FILTER_VALIDATE_INT);
        if ($amountRubles === false || $amountRubles < -1_000_000 || $amountRubles > 1_000_000) {
            throw new \RuntimeException('Invalid top-up amount for Rails gateway');
        }

        $payload = [
            '_csrf' => (string)$request->request->get('_csrf', ''),
            'amount_kopeks' => (string)($amountRubles * 100),
            'idempotency_key' => (string)$request->request->get('idempotency_key', ''),
            'provider' => (string)$request->request->get('provider', ''),
        ];

        return $this->forward($request, '/rails/topups', $payload, '/balance/topup/');
    }

    public function createBalanceOrder(Request $request): ?RedirectResponse
    {
        if (!$this->enabled()) return null;

        $payload = [
            '_csrf' => (string)$request->request->get('_csrf', ''),
            'plan_id' => (string)$request->request->get('plan_id', ''),
            'idempotency_key' => (string)$request->request->get('idempotency_key', ''),
        ];
        return $this->forward($request, '/rails/me/orders/balance', $payload, '/', false);
    }

    public function createRenewal(Request $request, string $subscriptionId): ?RedirectResponse
    {
        if (!$this->enabled()) return null;
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $subscriptionId)) {
            throw new \RuntimeException('Invalid subscription id for Rails gateway');
        }

        $payload = [
            '_csrf' => (string)$request->request->get('_csrf', ''),
            'idempotency_key' => (string)$request->request->get('key', ''),
        ];
        if ($request->request->has('receipt_email')) $payload['receipt_email'] = (string)$request->request->get('receipt_email');

        return $this->forward($request, '/rails/me/subscriptions/'.rawurlencode($subscriptionId).'/renew', $payload, '/orders/');
    }

    public function createGiftPurchase(Request $request): ?RedirectResponse
    {
        if (!$this->enabled()) return null;

        $payload = [
            '_csrf' => (string)$request->request->get('_csrf', ''),
            'plan_id' => (string)$request->request->get('plan_id', ''),
            'idempotency_key' => (string)$request->request->get('idempotency_key', ''),
            'recipient_type' => (string)$request->request->get('recipient_type', ''),
            'recipient_value' => (string)$request->request->get('recipient_value', ''),
            'message' => (string)$request->request->get('gift_message', ''),
        ];
        return $this->forward($request, '/rails/me/gifts', $payload, '/gifts', false);
    }

    public function createReferralWithdrawal(Request $request): ?RedirectResponse
    {
        if (!$this->enabled()) return null;

        $amountRubles = filter_var($request->request->get('amount'), FILTER_VALIDATE_INT);
        if ($amountRubles === false || $amountRubles < -1_000_000 || $amountRubles > 1_000_000) {
            throw new \RuntimeException('Invalid withdrawal amount for Rails gateway');
        }
        $payload = [
            '_csrf' => (string)$request->request->get('_csrf', ''),
            'amount_kopeks' => (string)($amountRubles * 100),
            'payment_details' => (string)$request->request->get('payment_details', ''),
        ];
        return $this->forward($request, '/rails/me/referral/withdrawals', $payload, '/referral', false);
    }

    private function enabled(): bool
    {
        return ($this->config['RAILS_WRITE_GATEWAY_ENABLED'] ?? '0') === '1';
    }

    private function forward(Request $request, string $endpoint, array $payload, string $redirectTarget, bool $appendId = true): RedirectResponse
    {
        $session = (string)$request->cookies->get('zb_session', '');
        if ($session === '' || strlen($session) > 512 || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $session)) {
            throw new \RuntimeException('Invalid session token for Rails gateway');
        }

        $ip = $request->getClientIp();
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) $ip = null;

        $base = $this->baseUrl();
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Cookie' => 'zb_session='.$session,
            'X-CSRF-Token' => $payload['_csrf'],
        ];
        if ($ip !== null) $headers['X-Forwarded-For'] = $ip;

        $client = $this->http ?? HttpClient::create();
        $response = $client->request('POST', $base.$endpoint, [
            'headers' => $headers,
            'body' => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            'timeout' => 5.0,
            'max_duration' => 6.0,
            'max_connect_duration' => 2.0,
            'max_redirects' => 0,
        ]);

        $status = $response->getStatusCode();
        if ($status !== 201) {
            $response->cancel();
            throw new \RuntimeException('Rails checkout endpoint rejected the request');
        }
        $responseHeaders = $response->getHeaders(false);
        $contentType = strtolower((string)($responseHeaders['content-type'][0] ?? ''));
        if (!str_starts_with($contentType, 'application/json')) {
            $response->cancel();
            throw new \RuntimeException('Rails checkout endpoint returned an unexpected content type');
        }

        $body = '';
        foreach ($client->stream($response, 5.0) as $chunk) {
            if ($chunk->isTimeout()) throw new \RuntimeException('Rails checkout endpoint timed out');
            $body .= $chunk->getContent();
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                $response->cancel();
                throw new \RuntimeException('Rails checkout response exceeded the size limit');
            }
        }

        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;
        if (!is_string($id) || strlen($id) > 128 || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $id)) {
            throw new \RuntimeException('Rails checkout response did not contain a valid id');
        }

        return new RedirectResponse($appendId ? $redirectTarget.rawurlencode($id) : $redirectTarget, 303);
    }

    private function baseUrl(): string
    {
        $base = rtrim((string)($this->config['RAILS_INTERNAL_URL'] ?? ''), '/');
        $parts = parse_url($base);
        if ($base === '' || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            throw new \RuntimeException('Rails internal URL is not configured safely');
        }
        return $base;
    }
}
