<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
final class MonitoringService
{
    public function __construct(private Database $db) {}
    /** Record a monitoring event. */
    public function log(string $eventType, string $message, bool $success = true, ?array $data = null): void
    {
        $this->db->execute(
            'INSERT INTO monitoring_logs(id,event_type,message,data,is_success,created_at) VALUES(?,?,?,?,?,?)',
            [Database::id(), $eventType, $message, $data !== null ? json_encode($data, JSON_THROW_ON_ERROR) : null, (int)$success, time()]
        );
    }
    /** Record a system error (deduplicated by type+message). */
    public function recordError(string $errorType, string $message, ?array $context = null): void
    {
        $key = hash('sha256', $errorType.'|'.$message);
        $this->db->execute(
            'INSERT INTO system_error_events(id,error_type,message,context,count,first_seen,last_seen) VALUES(?,?,?,?,1,?,?) ON CONFLICT(id) DO UPDATE SET count=count+1,last_seen=excluded.last_seen',
            [$key, $errorType, mb_substr($message, 0, 500), $context !== null ? json_encode($context, JSON_THROW_ON_ERROR) : null, time(), time()]
        );
    }
    public function recentEvents(int $limit = 50): array
    {
        return $this->db->all('SELECT * FROM monitoring_logs ORDER BY created_at DESC LIMIT ?', [$limit]);
    }
    public function errors(int $limit = 50): array
    {
        return $this->db->all('SELECT * FROM system_error_events ORDER BY last_seen DESC LIMIT ?', [$limit]);
    }
    public function clearErrors(): void
    {
        $this->db->execute('DELETE FROM system_error_events');
    }
    /** Traffic anomaly check: subscriptions with suspicious usage. */
    public function trafficAnomalies(int $limit = 20): array
    {
        return $this->db->all(
            "SELECT s.id,s.user_id,s.traffic_used_gb,s.traffic_limit_gb,s.expires_at,u.email,u.telegram_id FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE s.status IN ('active','trial') AND s.traffic_limit_gb>0 AND s.traffic_used_gb>s.traffic_limit_gb*1.5 ORDER BY s.traffic_used_gb DESC LIMIT ?",
            [$limit]
        );
    }
}
