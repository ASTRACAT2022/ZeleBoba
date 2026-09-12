<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class CustomerController extends Controller
{
    public function show(Request $request, string $id): View
    {
        $user = DB::table('users')->where('id', $id)->first();
        abort_unless($user !== null, 404);

        $timeline = DB::table('customer_timeline')
            ->where('user_id', $id)
            ->orderBy('recorded_at')
            ->limit(100)
            ->get()
            ->map(function (object $event): object {
                $event->payload = json_decode($event->payload, true, 32, JSON_THROW_ON_ERROR);

                return $event;
            });

        return view('admin.customers.show', [
            'customer' => $user,
            'timeline' => $timeline,
            'legacyUser' => $request->attributes->get('legacyUser'),
        ]);
    }
}
