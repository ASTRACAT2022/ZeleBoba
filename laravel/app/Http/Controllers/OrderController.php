<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use App\Services\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class OrderController extends Controller
{
    public function create(Request $request, OrderService $orders, CheckoutService $checkout): RedirectResponse
    {
        $data = $request->validate(['plan_id'=>['required','string','max:32'], 'idempotency_key'=>['required','string','max:128']]);
        $order = $orders->create($request->attributes->get('legacyUser')->id, $data['plan_id'], $data['idempotency_key']);
        $checkout->create($order->id);
        return redirect('/orders/'.$order->id, 303);
    }

    public function show(Request $request, string $id): View
    {
        $order = DB::table('orders')->where('id', $id)->where('user_id', $request->attributes->get('legacyUser')->id)->first();
        abort_unless($order, 404);
        return view('orders.show', compact('order'));
    }

    public function demoPay(Request $request, string $id, OrderService $orders): RedirectResponse
    {
        $orders->settleDemo($id, $request->attributes->get('legacyUser')->id);
        return redirect('/orders/'.$id, 303);
    }
}
