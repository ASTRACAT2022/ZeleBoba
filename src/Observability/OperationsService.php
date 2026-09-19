<?php
declare(strict_types=1);
namespace App\Observability;
use App\Infrastructure\Database;
use App\Infrastructure\SecretRedactor;

final class OperationsService
{
    public function __construct(private Database $db) {}

    public function start(string $type, array $refs = [], ?string $correlationId = null): array
    {
        $operationId = 'op_' . Database::id();
        $correlationId ??= 'cor_' . Database::id();
        $existing = $this->db->one('SELECT id,correlation_id,trace_id FROM operations WHERE correlation_id=?', [$correlationId]);
        if ($existing) { $existing['existing'] = true; return $existing; }
        $traceId = bin2hex(random_bytes(16));
        $now = time();
        $this->db->execute(
            'INSERT INTO operations(id,correlation_id,trace_id,type,status,user_id,subscription_id,order_id,payment_id,started_at,metadata) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$operationId, $correlationId, $traceId, $type, 'processing', $refs['user_id'] ?? null, $refs['subscription_id'] ?? null, $refs['order_id'] ?? null, $refs['payment_id'] ?? null, $now, $this->json($refs['metadata'] ?? [])]
        );
        $this->event($operationId, $type . '.started', 'processing', 'Operation started', $refs);
        return ['id' => $operationId, 'correlation_id' => $correlationId, 'trace_id' => $traceId, 'existing' => false];
    }

    public function event(string $operationId, string $type, string $status, string $message, array $refs = []): void
    {
        $op = $this->db->one('SELECT correlation_id,trace_id,user_id,subscription_id,order_id,payment_id FROM operations WHERE id=?', [$operationId]);
        if (!$op) return;
        $now = time();
        $this->db->execute(
            'INSERT INTO operation_events(id,operation_id,correlation_id,trace_id,span_id,user_id,subscription_id,order_id,payment_id,type,status,message,metadata,occurred_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [Database::id(), $operationId, $op['correlation_id'], $op['trace_id'], $refs['span_id'] ?? null, $refs['user_id'] ?? $op['user_id'], $refs['subscription_id'] ?? $op['subscription_id'], $refs['order_id'] ?? $op['order_id'], $refs['payment_id'] ?? $op['payment_id'], $type, $status, $message, $this->json($refs['metadata'] ?? []), $now, $now]
        );
    }

    public function complete(string $operationId, string $status = 'success', ?string $message = null, array $metadata = []): void
    {
        $this->db->execute('UPDATE operations SET status=?,completed_at=?,metadata=? WHERE id=?', [$status, time(), $this->json($metadata), $operationId]);
        $this->event($operationId, 'operation.completed', $status, $message ?? 'Operation completed', ['metadata' => $metadata]);
    }

    public function fail(string $operationId, \Throwable $e, array $metadata = []): void
    {
        $this->db->execute('UPDATE operations SET status=\'failed\',completed_at=?,last_error=? WHERE id=?', [time(), get_class($e), $operationId]);
        $this->event($operationId, 'operation.failed', 'failed', 'Application error', ['metadata' => $metadata + ['error_class' => get_class($e)]]);
    }

    public function addStep(string $operationId, array $step): string
    {
        $stepId = Database::id();
        $now = time();
        $metadata = $this->redact($step['request_metadata'] ?? []);
        $responseMetadata = $this->redact($step['response_metadata'] ?? []);
        $this->db->execute(
            'INSERT INTO operation_steps(id,operation_id,parent_step_id,name,category,status,started_at,finished_at,duration_ms,attempt,max_attempts,http_method,http_url,http_status,error_code,error_message,request_metadata,response_metadata,occurred_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$stepId, $operationId, $step['parent_step_id'] ?? null, $step['name'], $step['category'] ?? 'general', $step['status'] ?? 'pending', $step['started_at'] ?? null, $step['finished_at'] ?? null, $step['duration_ms'] ?? null, $step['attempt'] ?? 1, $step['max_attempts'] ?? 1, $step['http_method'] ?? null, $step['http_url'] ?? null, $step['http_status'] ?? null, $step['error_code'] ?? null, $step['error_message'] ?? null, $this->json($metadata), $this->json($responseMetadata), $now, $now]
        );
        return $stepId;
    }

    public function addRetry(string $operationId, string $stepId, array $retry): string
    {
        $retryId = Database::id();
        $now = time();
        $this->db->execute(
            'INSERT INTO operation_retries(id,operation_id,step_id,attempt,http_status,duration_ms,error_code,error_message,started_at,finished_at,metadata,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            [$retryId, $operationId, $stepId, $retry['attempt'], $retry['http_status'] ?? null, $retry['duration_ms'] ?? null, $retry['error_code'] ?? null, $retry['error_message'] ?? null, $now, $now, $this->json($this->redact($retry['metadata'] ?? [])), $now]
        );
        return $retryId;
    }

    public function getStepsTree(string $operationId): array
    {
        $rows = $this->db->all('SELECT * FROM operation_steps WHERE operation_id=? ORDER BY occurred_at,id', [$operationId]);
        return $this->buildTree($rows);
    }

    public function getRetries(string $operationId): array
    {
        return $this->db->all('SELECT * FROM operation_retries WHERE operation_id=? ORDER BY attempt', [$operationId]);
    }

    public function search(string $query = '', string $type = '', string $status = '', int $since = 0, string $client = '', string $invoice = '', string $service = '', string $provider = '', string $httpStatus = '', string $period = ''): array
    {
        $where = []; $params = [];
        if ($type !== '') { $where[] = 'o.type=?'; $params[] = $type; }
        if ($status !== '') { $where[] = 'o.status=?'; $params[] = $status; }
        if ($since > 0) { $where[] = 'o.started_at>=?'; $params[] = $since; }
        if ($client !== '') { $where[] = '(o.user_id=? OR u.email=?)'; array_push($params, $client, $client); }
        if ($invoice !== '') { $where[] = 'o.invoice_id=?'; $params[] = $invoice; }
        if ($service !== '') { $where[] = 'o.service_id=?'; $params[] = $service; }
        if ($provider !== '') { $where[] = 'p.provider=?'; $params[] = $provider; }
        if ($httpStatus !== '') { $where[] = 'o.http_status=?'; $params[] = (int)$httpStatus; }
        if ($period !== '') {
            $since = match ($period) { 'today' => strtotime('today UTC'), '24h' => time() - 86400, '7d' => time() - 7 * 86400, '30d' => time() - 30 * 86400, default => 0 };
            if ($since > 0) { $where[] = 'o.started_at>=?'; $params[] = $since; }
        }
        if ($query !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            $where[] = '(o.id LIKE ? OR o.correlation_id LIKE ? OR o.trace_id LIKE ? OR o.user_id LIKE ? OR o.subscription_id LIKE ? OR o.order_id LIKE ? OR o.invoice_id LIKE ? OR p.provider_payment_id LIKE ? OR ui.external_id LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        $sql = 'SELECT o.*,u.email,u.telegram_id,p.provider,p.provider_payment_id,p.amount_minor,p.currency,i.id AS invoice_id FROM operations o LEFT JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.id=o.payment_id LEFT JOIN invoices i ON i.id=o.invoice_id LEFT JOIN user_identities ui ON ui.user_id=o.user_id AND ui.type=\'telegram\'' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.started_at DESC LIMIT 100';
        return $this->db->all($sql, $params);
    }

    public function detail(string $id): ?array
    {
        $operation = $this->db->one('SELECT o.*,u.email,u.telegram_id,p.provider,p.provider_payment_id,p.amount_minor,p.currency,i.id AS invoice_id FROM operations o LEFT JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.id=o.payment_id LEFT JOIN invoices i ON i.id=o.invoice_id WHERE o.id=?', [$id]);
        if (!$operation) return null;
        $operation['events'] = $this->db->all('SELECT * FROM operation_events WHERE operation_id=? ORDER BY occurred_at,id', [$id]);
        $operation['steps'] = $this->getStepsTree($id);
        $operation['retries'] = $this->getRetries($id);
        $operation['outbox'] = $this->db->all('SELECT id,topic,status,attempts,available_at,last_error,created_at FROM outbox WHERE correlation_id=? ORDER BY created_at', [$operation['correlation_id']]);
        $operation['provisioning'] = $operation['subscription_id'] ? $this->db->one('SELECT * FROM provisioning_accounts WHERE subscription_id=?', [$operation['subscription_id']]) : null;
        return $operation;
    }

    public function operationCount(array $filters = []): int
    {
        $where = []; $params = [];
        foreach ($filters as $key => $val) {
            if ($val !== '') {
                $where[] = "o.{$key}=?"; $params[] = $val;
            }
        }
        $sql = 'SELECT COUNT(*) c FROM operations o ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '');
        return (int)($this->db->one($sql, $params)['c'] ?? 0);
    }

    public function recentActivity(string $clientId, int $limit = 20): array
    {
        return $this->db->all('SELECT o.*,u.email FROM operations o LEFT JOIN users u ON u.id=o.user_id WHERE o.user_id=? OR o.email=? ORDER BY o.started_at DESC LIMIT ?', [$clientId, $clientId, $limit]);
    }

    public function supportSummary(string $operationId): ?array
    {
        $op = $this->detail($operationId);
        if (!$op) return null;
        $steps = $this->getStepsTree($operationId);
        $summary = ['payment' => null, 'invoice' => null, 'subscription' => null, 'provisioning' => null, 'notification' => null, 'problem' => null, 'last_retry' => null];
        foreach ($this->flattenSteps($steps) as $step) {
            $cat = strtolower($step['category'] ?? '');
            if (str_contains($cat, 'payment') || str_contains($cat, 'webhook')) $summary['payment'] = $step;
            if (str_contains($cat, 'invoice') || str_contains($cat, 'invoice')) $summary['invoice'] = $step;
            if (str_contains($cat, 'subscription') || str_contains($cat, 'subscription')) $summary['subscription'] = $step;
            if (str_contains($cat, 'provisioning') || str_contains($cat, 'provisioning')) $summary['provisioning'] = $step;
            if (str_contains($cat, 'notification') || str_contains($cat, 'email')) $summary['notification'] = $step;
        }
        foreach (array_filter($this->flattenSteps($steps), fn($s) => ($s['status'] ?? '') === 'failed') as $failed) {
            $summary['problem'] = $failed;
            break;
        }
        foreach ($op['retries'] ?? [] as $retry) { $summary['last_retry'] = $retry; }
        return $summary;
    }

    public function getClientPageData(string $clientId): array
    {
        $recent = $this->recentActivity($clientId);
        $activity = [];
        foreach ($recent as $op) {
            $activity[] = ['time' => $op['started_at'], 'type' => $op['type'], 'operation' => $op['id'], 'status' => $op['status'], 'amount' => $op['amount_minor'] ?? null, 'currency' => $op['currency'] ?? 'RUB'];
        }
        return ['recent' => $activity, 'client_id' => $clientId];
    }

    private function buildTree(array $rows, ?string $parentId = null): array
    {
        $children = [];
        foreach ($rows as $row) {
            if (($row['parent_step_id'] ?? null) === $parentId) {
                $row['children'] = $this->buildTree($rows, $row['id']);
                $children[] = $row;
            }
        }
        return $children;
    }

    private function flattenSteps(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = $node;
            if (!empty($node['children'])) {
                $out = array_merge($out, $this->flattenSteps($node['children']));
            }
        }
        return $out;
    }

    private function json(array $metadata): string { return json_encode($this->redact($metadata), JSON_THROW_ON_ERROR); }

    private function redact(array $value): array
    {
        /** @var array $redacted */
        $redacted=SecretRedactor::redact($value);
        return $redacted;
    }
}
