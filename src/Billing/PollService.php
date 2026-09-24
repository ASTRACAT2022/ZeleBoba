<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class PollService
{
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet) {}
    /** Create a poll with questions. */
    public function create(array $input, string $actor): array
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) throw new BillingError('Заголовок: 1–255 символов.');
        $reward = (int)($input['reward_amount_kopeks'] ?? 0);
        if ($reward<0 || $reward>100000000) throw new BillingError('Награда: от 0 до 1 000 000 ₽.');
        $questions = $input['questions'] ?? [];
        if (!is_array($questions) || count($questions) < 1 || count($questions) > 10) throw new BillingError('Добавьте 1–10 вопросов.');
        $id = Database::id();
        $now = time();
        $this->db->transaction(function () use ($id, $title, $reward, $questions, $actor, $now, $input) {
            $this->db->execute('INSERT INTO polls(id,title,description,reward_enabled,reward_amount_kopeks,created_by,created_at) VALUES(?,?,?,?,?,?,?)', [$id, $title, $input['description'] ?? null, (int)($reward > 0), $reward, $actor, $now]);
            foreach ($questions as $i => $q) {
                $options = $q['options'] ?? [];
                $question=trim((string)($q['text'] ?? ''));
                if ($question==='' || mb_strlen($question)>500 || !is_array($options) || count($options)<2 || count($options)>20) throw new BillingError('Проверьте вопрос и его варианты.');
                foreach ($options as $option) if (!is_string($option)) throw new BillingError('Варианты ответа должны быть текстом.');
                $options=array_map(static fn(string $option)=>trim($option),array_values($options));
                if (count(array_filter($options,static fn($option)=>$option!=='' && mb_strlen($option)<=500))!==count($options) || count(array_unique($options))!==count($options)) throw new BillingError('Варианты ответа должны быть уникальными и непустыми.');
                $this->db->execute('INSERT INTO poll_questions(id,poll_id,text,options,"order") VALUES(?,?,?,?,?)', [Database::id(), $id, $question, json_encode($options, JSON_THROW_ON_ERROR), $i]);
            }
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'poll.created', $id, $now]);
        });
        return $this->db->one('SELECT * FROM polls WHERE id=?', [$id]);
    }
    public function list(): array
    {
        return $this->db->all('SELECT * FROM polls ORDER BY created_at DESC');
    }
    public function get(string $id): ?array
    {
        $poll = $this->db->one('SELECT * FROM polls WHERE id=?', [$id]);
        if (!$poll) return null;
        $poll['questions'] = $this->db->all('SELECT * FROM poll_questions WHERE poll_id=? ORDER BY "order"', [$id]);
        return $poll;
    }
    /** Submit answers. Returns ['reward'=>int]. */
    public function submit(string $userId, string $pollId, array $answers): array
    {
        return $this->db->transaction(function () use ($userId, $pollId, $answers) {
            $poll = $this->db->one('SELECT * FROM polls WHERE id=?' . $this->db->lock(), [$pollId]);
            if (!$poll) throw new BillingError('Опрос не найден.');
            $user=$this->db->one('SELECT disabled FROM users WHERE id=?',[$userId]);
            if (!$user || (int)$user['disabled']===1) throw new BillingError('Аккаунт недоступен.');
            if ($this->db->one('SELECT id FROM poll_responses WHERE poll_id=? AND user_id=?', [$pollId, $userId])) throw new BillingError('Вы уже участвовали в этом опросе.');
            $questions = $this->db->all('SELECT * FROM poll_questions WHERE poll_id=? ORDER BY "order"', [$pollId]);
            $normalized = [];
            foreach ($questions as $i => $q) {
                $answer = $answers[$i] ?? null;
                $options=json_decode($q['options'],true,512,JSON_THROW_ON_ERROR);
                if (!is_string($answer) || !in_array($answer,$options,true)) throw new BillingError('Выберите один из вариантов ответа.');
                $normalized[] = $answer;
            }
            $reward = 0;
            if ((int)$poll['reward_enabled'] === 1 && (int)$poll['reward_amount_kopeks'] > 0) {
                $this->wallet->credit($userId, (int)$poll['reward_amount_kopeks'], 'referral_reward', 'Награда за опрос: '.$poll['title']);
                $reward = (int)$poll['reward_amount_kopeks'];
            }
            $this->db->execute('INSERT INTO poll_responses(id,poll_id,user_id,answers,reward_paid,created_at) VALUES(?,?,?,?,?,?)', [Database::id(), $pollId, $userId, json_encode($normalized, JSON_THROW_ON_ERROR), (int)($reward > 0), time()]);
            return ['reward' => $reward];
        });
    }
}
