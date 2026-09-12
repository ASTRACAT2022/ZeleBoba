<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminUserController extends Controller
{
    public function block(Request $request, string $id, AdminUserService $users): JsonResponse
    {
        $users->block($request, (string) $request->attributes->get('legacyUser')->id, $id);

        return response()->json(['data' => ['id' => $id, 'disabled' => true]]);
    }
}
