<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class ContestService
{
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet) {}
    /** Create a contest template. */
    public function createTemplate(array $input, string $actor): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $slug = trim((string)($input['slug'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) throw new BillingError('Название: 1–100 символов.');
        if (!preg_match('/^[a-z0-9-]{2,50}$/D', $slug)) throw new BillingError('Слаг: 2–50 символов a-z, 0-9, -.');
        $prizeType = (string)($input['prize_type'] ?? 'days');
        if (!in_array($prizeType, ['days', 'balance', 'traffic'], true)) throw new BillingError('Некорректный тип приза.');
        $id = Database::id();
        $this->db->execute(
            'INSERT INTO contest_templates(id,name,slug,description,prize_type,prize_value,max_winners,attempts_per_user,times_per_day,cooldown_hours,is_enabled,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,1,?)',
            [$id, $name, $slug, $input['description'] ?? null, $prizeType, (string)($input['prize_value'] ?? '1'), (int)($input['max_winners'] ?? 1), (int)($input['attempts_per_user'] ?? 1), (int)($input['times_per_day'] ?? 1), (int)($input['cooldown_hours'] ?? 24), time()]
        );
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'contest.created', $id, time()]);
        return $this->db->one('SELECT * FROM contest_templates WHERE id=?', [$id]);
    }
    public function listTemplates(): array
    {
        return $this->db->all('SELECT * FROM contest_templates ORDER BY created_at DESC');
    }
    /** Start a round for a template. */
    public function startRound(string $templateId, string $actor): array
    {
        $t = $this->db->one('SELECT * FROM contest_templates WHERE id=?', [$templateId]);
        if (!$t) throw new BillingError('Конкурс не найден.');
        $id = Database::id();
        $now = time();
        $this->db->execute('INSERT INTO contest_rounds(id,template_id,status,starts_at,created_at) VALUES(?,?,?,?,?)', [$id, $templateId, 'running', $now, $now]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'contest.round_started', $id, $now]);
        return $this->db->one('SELECT * FROM contest_rounds WHERE id=?', [$id]);
    }
    /** Attempt a contest round. Returns ['won'=>bool,'prize'=>?string]. */
    public function attempt(string $userId, string $roundId): array
    {
        return $this->db->transaction(function () use ($userId, $roundId) {
            $round = $this->db->one('SELECT * FROM contest_rounds WHERE id=?' . $this->db->lock(), [$roundId]);
            if (!$round || $round['status'] !== 'running') throw new BillingError('Раунд не активен.');
            $template = $this->db->one('SELECT * FROM contest_templates WHERE id=?', [$round['template_id']]);
            if (!$template || (int)$template['is_enabled'] !== 1) throw new BillingError('Конкурс отключён.');
            $existing = $this->db->one('SELECT * FROM contest_attempts WHERE round_id=? AND user_id=?', [$roundId, $userId]);
            if ($existing) return ['won' => (int)$existing['won'] === 1, 'prize' => $existing['prize_value'], 'already' => true];
            $attemptsToday = (int)($this->db->one('SELECT COUNT(*) AS c FROM contest_attempts WHERE user_id=? AND created_at>?', [$userId, time() - 86400])['c'] ?? 0);
            if ($attemptsToday >= (int)$template['times_per_day']) throw new BillingError('Лимит попыток на сегодня исчерпан.');
            $winners = (int)($this->db->one('SELECT COUNT(*) AS c FROM contest_attempts WHERE round_id=? AND won=1', [$roundId])['c'] ?? 0);
            $won = $winners < (int)$template['max_winners'] && random_int(1, 100) <= 20;
            $prize = $won ? $template['prize_value'] : null;
            $this->db->execute('INSERT INTO contest_attempts(id,round_id,user_id,won,prize_value,created_at) VALUES(?,?,?,?,?,?)', [Database::id(), $roundId, $userId, (int)$won, $prize, time()]);
            if ($won) {
                $this->award($userId, $template, $prize);
            }
            return ['won' => $won, 'prize' => $prize, 'already' => false];
        });
    }
    private function award(string $userId, array $template, string $prize): void
    {
        $type = $template['prize_type'];
        $value = (int)$prize;
        if ($type === 'balance' && $value > 0) {
            $this->wallet->credit($userId, $value, 'referral_reward', 'Приз за конкурс: '.$template['name']);
        } elseif ($type === 'days' && $value > 0) {
            $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1", [$userId]);
            if ($sub) {
                $base = max(time(), (int)$sub['expires_at']);
                $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active' WHERE id=?", [$base + $value * 86400, $sub['id']]);
                $this->outbox->enqueue('subscription.extend', 'extend:'.$sub['id'], ['subscription_id' => $sub['id']]);
            }
        }
    }
    public function finishRound(string $roundId, string $actor): void
    {
        $this->db->execute("UPDATE contest_rounds SET status='finished',ends_at=? WHERE id=? AND status='running'", [time(), $roundId]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'contest.round_finished', $roundId, time()]);
    }
}
