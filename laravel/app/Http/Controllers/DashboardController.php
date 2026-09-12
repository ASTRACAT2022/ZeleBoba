<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->attributes->get('legacyUser');

        return view('dashboard', [
            'user' => $user,
            'subscriptions' => DB::table('subscriptions as s')
                ->leftJoin('orders as o', 'o.id', '=', 's.order_id')
                ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.user_id', $user->id)
                ->orderByDesc('s.created_at')->limit(50)
                ->selectRaw('s.*, COALESCE(o.plan_name, p.name) as plan_name')
                ->get(),
            'orders' => DB::table('orders')->where('user_id', $user->id)->orderByDesc('created_at')->limit(5)->get(),
        ]);
    }
}
