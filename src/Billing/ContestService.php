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
        $rawValue=(string)($input['prize_value'] ?? '');
        $maximum=match($prizeType){'balance'=>100000000,'days'=>3650,'traffic'=>100000};
        if (!ctype_digit($rawValue) || (int)$rawValue<1 || (int)$rawValue>$maximum) throw new BillingError('Некорректное значение приза.');
        $maxWinners=(int)($input['max_winners'] ?? 1);
        $timesPerDay=(int)($input['times_per_day'] ?? 1);
        $cooldownHours=(int)($input['cooldown_hours'] ?? 24);
        if ($maxWinners<1 || $maxWinners>100000 || $timesPerDay<1 || $timesPerDay>100 || $cooldownHours<0 || $cooldownHours>8760) throw new BillingError('Некорректные ограничения конкурса.');
        $id = Database::id();
        $this->db->transaction(function() use ($id,$name,$slug,$input,$prizeType,$rawValue,$maxWinners,$timesPerDay,$cooldownHours,$actor) {
            $this->db->execute(
                'INSERT INTO contest_templates(id,name,slug,description,prize_type,prize_value,max_winners,attempts_per_user,times_per_day,cooldown_hours,is_enabled,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,1,?)',
                [$id, $name, $slug, $input['description'] ?? null, $prizeType, $rawValue, $maxWinners, 1, $timesPerDay, $cooldownHours, time()]
            );
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'contest.created', $id, time()]);
        });
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
        $this->db->transaction(function() use ($id,$templateId,$now,$actor) {
            $this->db->execute('INSERT INTO contest_rounds(id,template_id,status,starts_at,created_at) VALUES(?,?,?,?,?)', [$id, $templateId, 'running', $now, $now]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'contest.round_started', $id, $now]);
        });
        return $this->db->one('SELECT * FROM contest_rounds WHERE id=?', [$id]);
    }
    /** Attempt a contest round. Returns ['won'=>bool,'prize'=>?string]. */
    public function attempt(string $userId, string $roundId): array
    {
        return $this->db->transaction(function () use ($userId, $roundId) {
            $round = $this->db->one('SELECT * FROM contest_rounds WHERE id=?' . $this->db->lock(), [$roundId]);
            if (!$round || $round['status'] !== 'running') throw new BillingError('Раунд не активен.');
            $template = $this->db->one('SELECT * FROM contest_templates WHERE id=?'.$this->db->lock(), [$round['template_id']]);
            if (!$template || (int)$template['is_enabled'] !== 1) throw new BillingError('Конкурс отключён.');
            $maximum=match($template['prize_type']){'balance'=>100000000,'days'=>3650,'traffic'=>100000,default=>0};
            if ($maximum===0 || !ctype_digit((string)$template['prize_value']) || (int)$template['prize_value']<1 || (int)$template['prize_value']>$maximum) throw new BillingError('Приз конкурса не настроен.');
            $existing = $this->db->one('SELECT * FROM contest_attempts WHERE round_id=? AND user_id=?', [$roundId, $userId]);
            if ($existing) return ['won' => (int)$existing['won'] === 1, 'prize' => $existing['prize_value'], 'already' => true];
            $user=$this->db->one('SELECT disabled FROM users WHERE id=?',[$userId]);
            if (!$user || (int)$user['disabled']===1) throw new BillingError('Аккаунт недоступен.');
            $now=time();
            $attemptsToday = (int)($this->db->one('SELECT COUNT(*) AS c FROM contest_attempts a JOIN contest_rounds r ON r.id=a.round_id WHERE a.user_id=? AND r.template_id=? AND a.created_at>?', [$userId,$template['id'],$now-86400])['c'] ?? 0);
            if ($attemptsToday >= (int)$template['times_per_day']) throw new BillingError('Лимит попыток на сегодня исчерпан.');
            $latest=$this->db->one('SELECT MAX(a.created_at) AS at FROM contest_attempts a JOIN contest_rounds r ON r.id=a.round_id WHERE a.user_id=? AND r.template_id=?',[$userId,$template['id']]);
            if ($latest['at']!==null && (int)$latest['at']+(int)$template['cooldown_hours']*3600>$now) throw new BillingError('Дождитесь окончания перерыва между попытками.');
            $sub=null;
            if ($template['prize_type']==='days' || $template['prize_type']==='traffic') {
                $limited=$template['prize_type']==='traffic' ? ' AND traffic_limit_gb>0' : '';
                $sub=$this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') AND expires_at>?$limited ORDER BY expires_at DESC LIMIT 1".$this->db->lock(),[$userId,$now]);
                if (!$sub) throw new BillingError($template['prize_type']==='traffic'?'Для приза нужен действующий тариф с лимитом трафика.':'Для приза нужна действующая подписка.');
            }
            $winners = (int)($this->db->one('SELECT COUNT(*) AS c FROM contest_attempts WHERE round_id=? AND won=1', [$roundId])['c'] ?? 0);
            $won = $winners < (int)$template['max_winners'] && random_int(1, 100) <= 20;
            $prize = $won ? $template['prize_value'] : null;
            $this->db->execute('INSERT INTO contest_attempts(id,round_id,user_id,won,prize_value,created_at) VALUES(?,?,?,?,?,?)', [Database::id(), $roundId, $userId, (int)$won, $prize, time()]);
            if ($won) {
                $this->award($userId, $roundId, $template, $prize, $sub);
            }
            return ['won' => $won, 'prize' => $prize, 'already' => false];
        });
    }
    private function award(string $userId, string $roundId, array $template, string $prize, ?array $sub): void
    {
        $type = $template['prize_type'];
        $value = (int)$prize;
        if ($type === 'balance' && $value > 0) {
            $this->wallet->credit($userId, $value, 'referral_reward', 'Приз за конкурс: '.$template['name']);
        } elseif ($type === 'days' && $value > 0) {
            $base = max(time(), (int)$sub['expires_at']);
            $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active' WHERE id=?", [$base + $value * 86400, $sub['id']]);
            $this->outbox->enqueue('subscription.extend', 'contest-extend:'.$roundId.':'.$userId.':'.$sub['id'], ['subscription_id' => $sub['id']]);
        } elseif ($type === 'traffic' && $value > 0) {
            $this->db->execute('UPDATE subscriptions SET purchased_traffic_gb=purchased_traffic_gb+?,updated_at=? WHERE id=?',[$value,time(),$sub['id']]);
            $this->outbox->enqueue('subscription.traffic','contest-traffic:'.$roundId.':'.$userId.':'.$sub['id'],['subscription_id'=>$sub['id'],'traffic_gb'=>$value]);
        } else {
            throw new BillingError('Приз конкурса не настроен.');
        }
    }
    public function finishRound(string $roundId, string $actor): void
    {
        $this->db->transaction(function() use ($roundId,$actor) {
            $updated=$this->db->execute("UPDATE contest_rounds SET status='finished',ends_at=? WHERE id=? AND status='running'", [time(), $roundId]);
            if ($updated) $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'contest.round_finished', $roundId, time()]);
        });
    }
}
