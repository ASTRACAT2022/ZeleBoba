<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class PlanController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->attributes->get('legacyUser');
        $discount = (int) ($user->promo_offer_discount_percent ?? 0);
        $expires = $user->promo_offer_discount_expires_at ?? null;
        if ($expires !== null && (int) $expires <= time()) {
            $discount = 0;
        }

        return view('plans.index', [
            'user' => $user,
            'plans' => DB::table('plans')->where('active', 1)->orderBy('price_minor')->get()->map(function (object $plan) use ($discount): object {
                $plan->effective_price_minor = $discount > 0 ? max(1, intdiv((int) $plan->price_minor * (100 - $discount), 100)) : (int) $plan->price_minor;

                return $plan;
            }),
        ]);
    }
}
