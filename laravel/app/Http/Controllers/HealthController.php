<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Horizon;

/**
 * Health probes deliberately do not contact payment or provisioning providers:
 * a transient third-party outage must not cause a process restart.
 */
final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200);
    }

    public function ready(): JsonResponse
    {
        $dependencies = [];

        try {
            DB::select('select 1');
            $dependencies['database'] = 'ok';
        } catch (\Throwable) {
            $dependencies['database'] = 'unavailable';
        }

        try {
            $production = config('app.env') === 'production';
            if ($production && config('queue.default') !== 'redis') {
                throw new \RuntimeException('Production queue must use Redis.');
            }
            if (config('queue.default') === 'redis') {
                Redis::connection((string) config('queue.connections.redis.connection', 'default'))->ping();
            }
            if ($production && ! class_exists(Horizon::class)) {
                throw new \RuntimeException('Horizon is not installed.');
            }
            $dependencies['queue'] = 'ok';
        } catch (\Throwable) {
            $dependencies['queue'] = 'unavailable';
        }

        $ready = ! in_array('unavailable', $dependencies, true);

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'dependencies' => $dependencies,
        ], $ready ? 200 : 503);
    }
}
