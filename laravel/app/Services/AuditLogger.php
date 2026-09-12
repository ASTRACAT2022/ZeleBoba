<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuditLogger
{
    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after @param array<string, mixed> $metadata */
    public function record(Request $request, string $actorId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata = []): void
    {
        DB::table('laravel_audit_log')->insert([
            'id' => bin2hex(random_bytes(16)),
            'actor_type' => 'user',
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_json' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1024),
            'correlation_id' => (string) $request->attributes->get('correlation_id', ''),
            'created_at' => time(),
        ]);
    }
}
