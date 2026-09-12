<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class CheckoutService
{
    public function create(string $orderId): object
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if (!$order || $order->status !== 'pending' || $order->checkout_url) return $order;

        if ($order->provider === 'demo') {
            DB::table('orders')->where('id', $orderId)->update(['provider_payment_id'=>'demo_'.$orderId, 'checkout_url'=>'/orders/'.$orderId]);
            return DB::table('orders')->where('id', $orderId)->first();
        }
        if ($order->provider === 'freekassa') return $this->createFreeKassa($order);
        if ($order->provider !== 'yookassa') throw ValidationException::withMessages(['payment' => 'Этот платёжный провайдер ещё не перенесён в Laravel.']);

        $shop = (string) config('services.yookassa.shop_id'); $secret = (string) config('services.yookassa.secret');
        if ($shop === '' || $secret === '') throw ValidationException::withMessages(['payment' => 'ЮKassa не настроена.']);
        $response = Http::acceptJson()->withBasicAuth($shop, $secret)->timeout(20)->withHeaders(['Idempotence-Key'=>$orderId])->post('https://api.yookassa.ru/v3/payments', [
            'amount'=>['value'=>self::decimal((int) $order->price_minor), 'currency'=>$order->currency],
            'capture'=>true,
            'confirmation'=>['type'=>'redirect', 'return_url'=>url('/orders/'.$orderId)],
            'description'=>'Подписка: '.$order->plan_name,
            'metadata'=>['order_id'=>$orderId],
        ]);
        $payment = $response->json();
        $paymentId = $payment['id'] ?? null; $url = $payment['confirmation']['confirmation_url'] ?? null;
        if (!$response->successful() || !is_string($paymentId) || !is_string($url) || !str_starts_with($url, 'https://')) throw ValidationException::withMessages(['payment' => 'Провайдер не вернул ссылку оплаты.']);
        DB::table('orders')->where('id', $orderId)->whereNull('provider_payment_id')->update(['provider_payment_id'=>$paymentId, 'checkout_url'=>$url]);
        return DB::table('orders')->where('id', $orderId)->first();
    }

    /** Create an external checkout for a wallet topup. */
    public function createTopup(string $topupId): object
    {
        $topup = DB::table('topups')->where('id', $topupId)->first();
        if (!$topup || $topup->status !== 'pending' || $topup->checkout_url) return $topup;
        if ($topup->provider === 'demo') {
            DB::table('topups')->where('id', $topupId)->update(['provider_payment_id'=>'demo_'.$topupId, 'checkout_url'=>'/balance/topup/'.$topupId]);
            return DB::table('topups')->where('id', $topupId)->first();
        }
        if ($topup->provider === 'freekassa') return $this->createFreeKassa($topup, 'topups');
        if ($topup->provider !== 'yookassa') throw ValidationException::withMessages(['payment'=>'Этот платёжный провайдер ещё не перенесён в Laravel.']);
        $shop = (string) config('services.yookassa.shop_id'); $secret = (string) config('services.yookassa.secret');
        if ($shop === '' || $secret === '') throw ValidationException::withMessages(['payment'=>'ЮKassa не настроена.']);
        $response = Http::acceptJson()->withBasicAuth($shop, $secret)->timeout(20)->withHeaders(['Idempotence-Key'=>$topupId])->post('https://api.yookassa.ru/v3/payments', [
            'amount'=>['value'=>self::decimal((int) $topup->amount_kopeks), 'currency'=>$topup->currency], 'capture'=>true,
            'confirmation'=>['type'=>'redirect', 'return_url'=>url('/balance/topup/'.$topupId)], 'description'=>'Пополнение баланса', 'metadata'=>['topup_id'=>$topupId],
        ]);
        $payment = $response->json(); $paymentId = $payment['id'] ?? null; $url = $payment['confirmation']['confirmation_url'] ?? null;
        if (!$response->successful() || !is_string($paymentId) || !is_string($url) || !str_starts_with($url, 'https://')) throw ValidationException::withMessages(['payment'=>'Провайдер не вернул ссылку оплаты.']);
        DB::table('topups')->where('id', $topupId)->whereNull('provider_payment_id')->update(['provider_payment_id'=>$paymentId, 'checkout_url'=>$url]);
        return DB::table('topups')->where('id', $topupId)->first();
    }

    private function createFreeKassa(object $order, string $table = 'orders'): object
    {
        $shop = (string) config('services.freekassa.shop_id'); $key = (string) config('services.freekassa.api_key');
        if ($shop === '' || $key === '') throw ValidationException::withMessages(['payment' => 'FreeKassa не настроена.']);
        if (time() - (int) $order->created_at > 23 * 3600) throw ValidationException::withMessages(['payment' => 'Требуется ручная сверка платежа.']);
        $user = DB::table('users')->where('id', $order->user_id)->first(); $email = $user->email ?? null;
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw ValidationException::withMessages(['payment' => 'Некорректный email для FreeKassa.']);
        $amount = (int) ($order->price_minor ?? $order->amount_kopeks);
        $params = ['shopId'=>(int) $shop, 'nonce'=>$this->nonce(), 'paymentId'=>$order->id, 'i'=>(int) config('services.freekassa.payment_id'), 'email'=>$email, 'ip'=>'8.8.8.8', 'amount'=>self::decimal($amount), 'currency'=>$order->currency];
        $signed = $params; ksort($signed); $params['signature'] = hash_hmac('sha256', implode('|', array_map('strval', $signed)), $key);
        $response = Http::acceptJson()->timeout(20)->post('https://api.fk.life/v1/orders/create', $params);
        $data = $response->json(); $id = $data['orderId'] ?? null; $url = $data['location'] ?? null;
        if (!$response->successful() || ($data['type'] ?? '') === 'error' || !is_scalar($id) || !is_string($url) || !str_starts_with($url, 'https://')) throw ValidationException::withMessages(['payment' => 'FreeKassa не вернула ссылку оплаты.']);
        DB::table($table)->where('id', $order->id)->whereNull('provider_payment_id')->update(['provider_payment_id'=>(string) $id, 'checkout_url'=>$url]);
        return DB::table($table)->where('id', $order->id)->first();
    }

    private function nonce(): int
    {
        if (DB::connection()->getDriverName() === 'pgsql') return (int) DB::selectOne("SELECT nextval('freekassa_nonce_seq') AS value")->value;
        static $last = 0; $next = (int) (microtime(true) * 1_000_000) + 8_500_000_000_000_000_000;
        return $last = max($next, $last + 1);
    }

    private static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
