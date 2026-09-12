<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SubscriptionController extends Controller
{
    public function trial(Request $request, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'string', 'max:32']]);
        $lifecycle->startTrial((string) $request->attributes->get('legacyUser')->id, $data['plan_id']);

        return redirect('/', 303);
    }

    public function autoRenew(Request $request, string $id, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $lifecycle->setAutoRenew((string) $request->attributes->get('legacyUser')->id, $id, (bool) $data['enabled']);

        return redirect('/', 303);
    }
}
