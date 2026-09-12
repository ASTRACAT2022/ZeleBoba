<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class PaymentWebhookController extends Controller
{
    public function yookassa(Request $request, OrderService $orders, WalletService $wallet): JsonResponse
    {
        $payload = $request->validate(['event' => ['required', 'string'], 'object.id' => ['required', 'string', 'max:100']]);
        if ($payload['event'] !== 'payment.succeeded') {
            return response()->json([], 204);
        }

        $id = data_get($payload, 'object.id');
        $shop = (string) config('services.yookassa.shop_id');
        $secret = (string) config('services.yookassa.secret');
        if ($shop === '' || $secret === '') {
            return response()->json([], 204);
        }
        $response = Http::acceptJson()->withBasicAuth($shop, $secret)->timeout(10)->get('https://api.yookassa.ru/v3/payments/'.rawurlencode($id));
        if (! $response->successful()) {
            return response()->json([], 204);
        }
        $payment = $response->json();
        $orderId = $payment['metadata']['order_id'] ?? null;
        $topupId = $payment['metadata']['topup_id'] ?? null;
        if ((! is_string($orderId) && ! is_string($topupId)) || (is_string($orderId) && is_string($topupId)) || ! preg_match('/^[a-f0-9]{32}$/D', (string) ($orderId ?? $topupId)) || ($payment['id'] ?? null) !== $id || ($payment['status'] ?? null) !== 'succeeded' || ($payment['paid'] ?? false) !== true) {
            return response()->json([], 204);
        }
        $value = $payment['amount']['value'] ?? null;
        $currency = $payment['amount']['currency'] ?? null;
        if (! is_string($value) || ! is_string($currency) || $currency !== 'RUB') {
            return response()->json([], 204);
        }
        try {
            if (is_string($orderId)) {
                $orders->settleVerified($orderId, 'yookassa', $id, self::minor($value), $currency);
            } else {
                $wallet->settleVerified((string) $topupId, 'yookassa', $id, self::minor($value), $currency);
            }
        } catch (\Throwable) {
            return response()->json([], 204);
        }

        return response()->json([], 204);
    }

    public function freekassa(Request $request, OrderService $orders, WalletService $wallet)
    {
        $data = $request->validate(['MERCHANT_ID' => ['required', 'string'], 'AMOUNT' => ['required', 'string'], 'MERCHANT_ORDER_ID' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'], 'SIGN' => ['required', 'string']]);
        $shop = (string) config('services.freekassa.shop_id');
        $secret2 = (string) config('services.freekassa.secret2');
        $key = (string) config('services.freekassa.api_key');
        $expected = md5($data['MERCHANT_ID'].':'.$data['AMOUNT'].':'.$secret2.':'.$data['MERCHANT_ORDER_ID']);
        if ($shop === '' || $key === '' || ! hash_equals(strtolower($expected), strtolower($data['SIGN'])) || $data['MERCHANT_ID'] !== $shop) {
            return response('NO', 400);
        }
        try {
            $params = ['shopId' => (int) $shop, 'nonce' => $this->freeKassaNonce(), 'paymentId' => $data['MERCHANT_ORDER_ID']];
            $signed = $params;
            ksort($signed);
            $params['signature'] = hash_hmac('sha256', implode('|', array_map('strval', $signed)), $key);
            $result = Http::acceptJson()->timeout(20)->post('https://api.fk.life/v1/orders', $params);
            $remote = $result->json('orders.0');
            if (! $result->successful() || ! is_array($remote) || (int) ($remote['status'] ?? -1) !== 1 || ($remote['merchant_order_id'] ?? $remote['paymentId'] ?? null) !== $data['MERCHANT_ORDER_ID']) {
                throw new \RuntimeException('Unverified FreeKassa payment.');
            }
            $paymentId = (string) ($remote['fk_order_id'] ?? $remote['orderId'] ?? '');
            $amount = self::minor((string) ($remote['amount'] ?? ''));
            $currency = (string) ($remote['currency'] ?? 'RUB');
            if (DB::table('orders')->where('id', $data['MERCHANT_ORDER_ID'])->exists()) {
                $orders->settleVerified($data['MERCHANT_ORDER_ID'], 'freekassa', $paymentId, $amount, $currency);
            } else {
                $wallet->settleVerified($data['MERCHANT_ORDER_ID'], 'freekassa', $paymentId, $amount, $currency);
            }
        } catch (\Throwable) {
            return response('NO', 503);
        }

        return response('YES', 200);
    }

    private static function minor(string $value): int
    {
        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid amount.');
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function freeKassaNonce(): int
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return (int) DB::selectOne("SELECT nextval('freekassa_nonce_seq') AS value")->value;
        }

        return (int) (microtime(true) * 1_000_000) + 8_500_000_000_000_000_000;
    }
}
