<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WalletService
{
    public function debit(string $userId, int $amount, string $type, string $description, ?string $externalId = null): void
    {
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Сумма должна быть положительной.']);
        }
        DB::transaction(function () use ($userId, $amount, $type, $description, $externalId): void {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if (! $user || (int) $user->balance_kopeks < $amount) {
                throw ValidationException::withMessages(['balance' => 'Недостаточно средств на балансе.']);
            }
            $now = time();
            DB::table('users')->where('id', $userId)->decrement('balance_kopeks', $amount);
            $seq = (int) DB::table('transactions')->max('seq') + 1;
            DB::table('transactions')->insert(['id' => bin2hex(random_bytes(16)), 'seq' => $seq, 'user_id' => $userId, 'type' => $type, 'amount_kopeks' => -$amount, 'description' => $description, 'payment_method' => 'balance', 'external_id' => $externalId, 'is_completed' => 1, 'created_at' => $now, 'completed_at' => $now]);
        });
    }

    public function createTopup(string $userId, int $amount, string $key): object
    {
        if ($amount < 100 || $amount > 100_000_000 || ! preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D', $key)) {
            throw ValidationException::withMessages(['amount' => 'Сумма пополнения: от 1 до 1 000 000 ₽.']);
        }

        return DB::transaction(function () use ($userId, $amount, $key): object {
            $existing = DB::table('topups')->where('user_id', $userId)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ((int) $existing->amount_kopeks !== $amount || $existing->provider !== config('payments.driver')) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой суммы или способа оплаты.']);
                }

                return $existing;
            }
            $id = bin2hex(random_bytes(16));
            DB::table('topups')->insert(['id' => $id, 'user_id' => $userId, 'amount_kopeks' => $amount, 'currency' => 'RUB', 'status' => 'pending', 'provider' => config('payments.driver'), 'idempotency_key' => $key, 'created_at' => time()]);

            return DB::table('topups')->where('id', $id)->first();
        });
    }

    public function settleDemo(string $topupId, string $userId): void
    {
        if (app()->isProduction() || config('payments.driver') !== 'demo') {
            abort(404);
        }
        DB::transaction(function () use ($topupId, $userId): void {
            $topup = DB::table('topups')->where('id', $topupId)->lockForUpdate()->first();
            if (! $topup || $topup->user_id !== $userId || $topup->status !== 'pending') {
                if ($topup && $topup->status === 'paid') {
                    return;
                } abort(404);
            }
            $this->settleLocked($topup, 'demo_'.$topupId);
        });
    }

    /** Credit a provider-verified topup exactly once. */
    public function settleVerified(string $topupId, string $provider, string $paymentId, int $amount, string $currency): void
    {
        if ($paymentId === '' || strlen($paymentId) > 100) {
            throw ValidationException::withMessages(['payment' => 'Некорректный идентификатор платежа.']);
        }
        DB::transaction(function () use ($topupId, $provider, $paymentId, $amount, $currency): void {
            $topup = DB::table('topups')->where('id', $topupId)->lockForUpdate()->first();
            if (! $topup || $topup->provider !== $provider || (int) $topup->amount_kopeks !== $amount || $topup->currency !== $currency) {
                throw ValidationException::withMessages(['payment' => 'Платёж не соответствует пополнению.']);
            }
            if (DB::table('payment_receipts')->where('provider', $provider)->where('payment_id', $paymentId)->exists()) {
                throw ValidationException::withMessages(['payment' => 'Платёж уже использован для заказа.']);
            }
            $duplicate = DB::table('topups')->where('provider_payment_id', $paymentId)->first();
            if ($duplicate && $duplicate->id !== $topupId) {
                throw ValidationException::withMessages(['payment' => 'Платёж уже использован для другого пополнения.']);
            }
            if ($topup->status === 'paid' && $topup->provider_payment_id === $paymentId) {
                return;
            }
            if ($topup->status !== 'pending') {
                throw ValidationException::withMessages(['topup' => 'Пополнение уже обработано.']);
            }
            $this->settleLocked($topup, $paymentId);
        });
    }

    private function settleLocked(object $topup, string $paymentId): void
    {
        $now = time();
        $user = DB::table('users')->where('id', $topup->user_id)->lockForUpdate()->first();
        if (! $user) {
            throw ValidationException::withMessages(['user' => 'Аккаунт не найден.']);
        }
        DB::table('topups')->where('id', $topup->id)->update(['status' => 'paid', 'provider_payment_id' => $paymentId, 'paid_at' => $now, 'referral_first' => (int) (($user->has_made_first_topup ?? 0) === 0)]);
        DB::table('users')->where('id', $topup->user_id)->increment('balance_kopeks', (int) $topup->amount_kopeks);
        DB::table('users')->where('id', $topup->user_id)->update(['has_made_first_topup' => 1]);
        $seq = (int) DB::table('transactions')->max('seq') + 1;
        DB::table('transactions')->insert(['id' => bin2hex(random_bytes(16)), 'seq' => $seq, 'user_id' => $topup->user_id, 'type' => 'balance_topup', 'amount_kopeks' => $topup->amount_kopeks, 'description' => 'Пополнение баланса', 'payment_method' => $topup->provider, 'external_id' => $paymentId, 'is_completed' => 1, 'created_at' => $now, 'completed_at' => $now]);
        if (DB::getSchemaBuilder()->hasTable('customer_timeline')) {
            DB::table('customer_timeline')->insert(['id' => bin2hex(random_bytes(16)), 'user_id' => $topup->user_id, 'event_type' => 'balance.topped_up', 'payload' => json_encode(['topup_id' => $topup->id, 'amount_kopeks' => (int) $topup->amount_kopeks], JSON_THROW_ON_ERROR), 'occurred_at' => $now, 'recorded_at' => (int) floor(microtime(true) * 1_000_000)]);
        }
    }
}
