<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::get('/', DashboardController::class)->middleware('legacy.session');
Route::get('/plans', [PlanController::class, 'index'])->middleware('legacy.session');
Route::post('/orders', [OrderController::class, 'create'])->middleware('legacy.session');
Route::get('/orders/{id}', [OrderController::class, 'show'])->where('id', '[a-f0-9]{32}')->middleware('legacy.session');
Route::post('/orders/{id}/demo-pay', [OrderController::class, 'demoPay'])->where('id', '[a-f0-9]{32}')->middleware('legacy.session');
Route::get('/balance', [BalanceController::class, 'index'])->middleware('legacy.session');
Route::post('/balance/topup', [BalanceController::class, 'topup'])->middleware('legacy.session');
Route::get('/balance/topup/{id}', [BalanceController::class, 'show'])->where('id', '[a-f0-9]{32}')->middleware('legacy.session');
Route::post('/balance/topup/{id}/demo-pay', [BalanceController::class, 'demo'])->where('id', '[a-f0-9]{32}')->middleware('legacy.session');
Route::post('/trials', [SubscriptionController::class, 'trial'])->middleware('legacy.session');
Route::post('/subscriptions/{id}/auto-renew', [SubscriptionController::class, 'autoRenew'])->where('id', '[a-f0-9]{32}')->middleware('legacy.session');
Route::post('/webhooks/yookassa', [PaymentWebhookController::class, 'yookassa'])->withoutMiddleware(VerifyCsrfToken::class);
Route::match(['get', 'post'], '/webhooks/freekassa', [PaymentWebhookController::class, 'freekassa'])->withoutMiddleware(VerifyCsrfToken::class);

Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::get('/register', [AuthController::class, 'registerForm']);
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('legacy.session');

Route::middleware('legacy.admin')->group(function (): void {
    Route::get('/admin/users/{id}', [CustomerController::class, 'show'])
        ->where('id', '[a-f0-9]{32}')
        ->middleware('permission:users.view')
        ->name('admin.customers.show');

    Route::post('/api/v1/admin/users/{id}/block', [AdminUserController::class, 'block'])
        ->where('id', '[a-f0-9]{32}')
        ->middleware(['permission:users.block', 'throttle:10,1']);
});
