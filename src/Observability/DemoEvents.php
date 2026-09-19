<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
use App\Infrastructure\SecretRedactor;

final class DemoEvents
{
    public function __construct(private Database $db) {}

    public function createSuccessFlow(string $userId, string $email): string
    {
        $opId = 'op_' . Database::id();
        $correlationId = 'cor_' . Database::id();
        $traceId = bin2hex(random_bytes(16));
        $now = time();
        $op = $this->db->one('SELECT id,correlation_id,trace_id,user_id,subscription_id,order_id,payment_id FROM operations WHERE correlation_id=?', [$correlationId]);
        if ($op) { $op['existing'] = true; return $op['id']; }

        $this->db->execute('INSERT INTO operations(id,correlation_id,trace_id,type,status,user_id,started_at,metadata) VALUES(?,?,?,?,?,?,?,?)', [$opId, $correlationId, $traceId, 'payment', 'processing', $userId, $now, json_encode(['amount' => 19900, 'currency' => 'RUB', 'email' => $email], JSON_THROW_ON_ERROR)]);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now, 'payment.webhook.received', 'success', 'Webhook received from FreeKassa', ['webhook_id' => 'wf_' . bin2hex(random_bytes(8)), 'amount' => 19900]);

        $this->addStep($opId, null, 'Webhook received', 'webhook', 'success', $now, $now + 1, 23, 1, 1, 'POST', '/api/webhooks/freekassa', 200, null, null, ['body' => 'MERCHANT_ID=xxx'], ['status' => 'ok']);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 1, 'payment.signature.verified', 'success', 'Signature verified');
        $this->addStep($opId, null, 'Signature verified', 'payment', 'success', $now + 1, $now + 2, 8, 1, 1);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 2, 'payment.transaction.lookup', 'success', 'Transaction created in billing');
        $this->addStep($opId, null, 'Transaction created', 'payment', 'success', $now + 2, $now + 3, 4, 1, 1);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 3, 'invoice.lookup', 'success', 'Invoice found and applied');
        $this->addStep($opId, null, 'Invoice applied', 'invoice', 'success', $now + 3, $now + 4, 11, 1, 1);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 4, 'subscription.extend', 'success', 'Subscription extended');
        $this->addStep($opId, null, 'Subscription extended', 'subscription', 'success', $now + 4, $now + 5, 15, 1, 1);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 5, 'provisioning.queued', 'success', 'Provisioning queued for Remnawave');
        $this->addStep($opId, null, 'Remnawave API request', 'provisioning', 'success', $now + 5, $now + 10, 320, 1, 3, 'PATCH', '/api/v1/users/subscription', 200);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 10, 'provisioning.completed', 'success', 'Provisioning completed');
        $this->addStep($opId, null, 'Provisioning completed', 'provisioning', 'success', $now + 10, $now + 11, 8, 1, 1);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 11, 'notification.sent', 'success', 'Email notification sent');
        $this->addStep($opId, null, 'Notification sent', 'email', 'success', $now + 11, $now + 12, 45, 1, 1);

        $this->db->execute('UPDATE operations SET status=?,completed_at=?,metadata=? WHERE id=?', ['success', $now + 12, json_encode([], JSON_THROW_ON_ERROR), $opId]);
        return $opId;
    }

    public function createFailedFlow(string $userId, string $email): string
    {
        $opId = 'op_' . Database::id();
        $correlationId = 'cor_' . Database::id();
        $traceId = bin2hex(random_bytes(16));
        $now = time();

        $this->db->execute('INSERT INTO operations(id,correlation_id,trace_id,type,status,user_id,started_at,metadata) VALUES(?,?,?,?,?,?,?,?)', [$opId, $correlationId, $traceId, 'payment', 'processing', $userId, $now, json_encode(['amount' => 19900, 'currency' => 'RUB', 'email' => $email], JSON_THROW_ON_ERROR)]);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now, 'payment.webhook.received', 'success', 'Webhook received');
        $this->addStep($opId, null, 'Webhook received', 'webhook', 'success', $now, $now + 1, 23, 1, 1);
        $this->addStep($opId, null, 'Signature verified', 'payment', 'success', $now + 1, $now + 2, 8, 1, 1);
        $this->addStep($opId, null, 'Transaction created', 'payment', 'success', $now + 2, $now + 3, 4, 1, 1);
        $this->addStep($opId, null, 'Invoice applied', 'invoice', 'success', $now + 3, $now + 4, 11, 1, 1);
        $this->addStep($opId, null, 'Subscription extended', 'subscription', 'success', $now + 4, $now + 5, 15, 1, 1);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 5, 'provisioning.started', 'processing', 'Provisioning started');
        $this->addStep($opId, null, 'Remnawave API request', 'provisioning', 'failed', $now + 5, $now + 10, 512, 1, 3, 'PATCH', '/api/v1/users/subscription', 503, 'UPSTREAM_UNAVAILABLE', 'Service temporarily unavailable');

        $this->addRetry($opId, $now + 12, 2, 503, 480, 'UPSTREAM_UNAVAILABLE', 'Service temporarily unavailable');
        $this->addRetry($opId, $now + 17, 3, 200, 302, null, null);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 12, 'provisioning.retry', 'retrying', 'Retry 2/3 for Remnawave');
        $this->addStep($opId, null, 'Remnawave API request (retry)', 'provisioning', 'success', $now + 12, $now + 17, 302, 2, 3, 'PATCH', '/api/v1/users/subscription', 200);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 17, 'provisioning.completed', 'success', 'Provisioning recovered after retry');
        $this->addStep($opId, null, 'Provisioning completed', 'provisioning', 'success', $now + 17, $now + 18, 8, 1, 1);

        $this->db->execute('UPDATE operations SET status=?,completed_at=?,metadata=? WHERE id=?', ['success', $now + 18, json_encode([], JSON_THROW_ON_ERROR), $opId]);
        return $opId;
    }

    public function createPermanentFailure(string $userId, string $email): string
    {
        $opId = 'op_' . Database::id();
        $correlationId = 'cor_' . Database::id();
        $traceId = bin2hex(random_bytes(16));
        $now = time();

        $this->db->execute('INSERT INTO operations(id,correlation_id,trace_id,type,status,user_id,started_at,metadata) VALUES(?,?,?,?,?,?,?,?)', [$opId, $correlationId, $traceId, 'payment', 'processing', $userId, $now, json_encode(['amount' => 19900, 'currency' => 'RUB', 'email' => $email], JSON_THROW_ON_ERROR)]);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now, 'payment.webhook.received', 'success', 'Webhook received');
        $this->addStep($opId, null, 'Webhook received', 'webhook', 'success', $now, $now + 1, 23, 1, 1);
        $this->addStep($opId, null, 'Signature verified', 'payment', 'success', $now + 1, $now + 2, 8, 1, 1);
        $this->addStep($opId, null, 'Invoice applied', 'invoice', 'success', $now + 2, $now + 3, 11, 1, 1);
        $this->addEvent($opId, $correlationId, $traceId, $userId, null, null, null, $now + 5, 'provisioning.started', 'processing', 'Provisioning started');

        for ($i = 1; $i <= 3; $i++) {
            $this->addRetry($opId, $now + $i * 5, $i, 503, 500 + $i * 50, 'UPSTREAM_UNAVAILABLE', 'Service temporarily unavailable');
        }
        $this->addStep($opId, null, 'Remnawave API request', 'provisioning', 'failed', $now + 5, $now + 20, 15000, 3, 3, 'PATCH', '/api/v1/users/subscription', 503, 'UPSTREAM_UNAVAILABLE', 'Service unavailable after 3 retries');
        $this->db->execute('UPDATE operations SET status=?,completed_at=?,last_error=? WHERE id=?', ['failed', $now + 20, 'UPSTREAM_UNAVAILABLE', $opId]);
        return $opId;
    }

    private function addStep(string $opId, ?string $parentId, string $name, string $category, string $status, ?int $startedAt, ?int $finishedAt, ?int $durationMs, int $attempt, int $maxAttempts, ?string $httpMethod = null, ?string $httpUrl = null, ?int $httpStatus = null, ?string $errorCode = null, ?string $errorMessage = null, array $requestMetadata = [], array $responseMetadata = []): string
    {
        $stepId = Database::id();
        $now = time();
        $this->db->execute('INSERT INTO operation_steps(id,operation_id,parent_step_id,name,category,status,started_at,finished_at,duration_ms,attempt,max_attempts,http_method,http_url,http_status,error_code,error_message,request_metadata,response_metadata,occurred_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$stepId, $opId, $parentId, $name, $category, $status, $startedAt, $finishedAt, $durationMs, $attempt, $maxAttempts, $httpMethod, $httpUrl, $httpStatus, $errorCode, $errorMessage, json_encode(SecretRedactor::redact($requestMetadata), JSON_THROW_ON_ERROR), json_encode(SecretRedactor::redact($responseMetadata), JSON_THROW_ON_ERROR), $now, $now]);
        return $stepId;
    }

    private function addRetry(string $opId, int $at, int $attempt, ?int $httpStatus, ?int $durationMs, ?string $errorCode, ?string $errorMessage): void
    {
        $retryId = Database::id();
        $this->db->execute('INSERT INTO operation_retries(id,operation_id,step_id,attempt,http_status,duration_ms,error_code,error_message,started_at,finished_at,metadata,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', [$retryId, $opId, null, $attempt, $httpStatus, $durationMs, $errorCode, $errorMessage, $at, $at, '{}', $at]);
    }

    private function addEvent(string $opId, string $correlationId, string $traceId, ?string $userId, ?string $subId, ?string $orderId, ?string $payId, int $at, string $type, string $status, string $message, array $metadata = []): void
    {
        $this->db->execute('INSERT INTO operation_events(id,operation_id,correlation_id,trace_id,span_id,user_id,subscription_id,order_id,payment_id,type,status,message,metadata,occurred_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [Database::id(), $opId, $correlationId, $traceId, bin2hex(random_bytes(8)), $userId, $subId, $orderId, $payId, $type, $status, $message, json_encode(SecretRedactor::redact($metadata), JSON_THROW_ON_ERROR), $at, $at]);
    }
}
