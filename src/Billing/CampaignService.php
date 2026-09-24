<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class CampaignService
{
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet) {}
    /** Create a campaign. */
    public function create(array $input, string $actor): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $param = trim((string)($input['start_parameter'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) throw new BillingError('Название: 1–255 символов.');
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/D', $param)) throw new BillingError('Параметр: 2–64 символа a-z, 0-9, _ или -.');
        $type = (string)($input['bonus_type'] ?? 'balance');
        if (!in_array($type, ['balance', 'subscription'], true)) throw new BillingError('Некорректный тип бонуса.');
        $balance=(int)($input['balance_bonus_kopeks'] ?? 0);
        $days=(int)($input['subscription_duration_days'] ?? 0);
        $planId=(string)($input['plan_id'] ?? '');
        if ($type==='balance' && ($balance<1 || $balance>100000000)) throw new BillingError('Бонус: 1–1 000 000 ₽.');
        if ($type==='subscription' && ($days<1 || $days>3650 || !$this->db->one('SELECT id FROM plans WHERE id=? AND active=1',[$planId]))) throw new BillingError('Для подписки укажите 1–3650 дней и активный тариф.');
        $id = Database::id();
        $this->db->transaction(function() use($id,$name,$param,$type,$balance,$days,$planId,$input,$actor){
            $this->db->execute(
                'INSERT INTO advertising_campaigns(id,name,start_parameter,bonus_type,balance_bonus_kopeks,subscription_duration_days,subscription_traffic_gb,subscription_device_limit,plan_id,is_active,partner_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?)',
                [$id,$name,$param,$type,$type==='balance'?$balance:0,$type==='subscription'?$days:null,null,null,$type==='subscription'?$planId:null,($input['partner_user_id']??'')!==''?$input['partner_user_id']:null,$actor,time()]
            );
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(),$actor,'campaign.created',$id,time()]);
        });
        return $this->db->one('SELECT * FROM advertising_campaigns WHERE id=?', [$id]);
    }
    public function list(): array
    {
        return $this->db->all('SELECT * FROM advertising_campaigns ORDER BY created_at DESC');
    }
    /** Register a user via campaign start parameter. Grants the bonus once. */
    public function register(string $userId, string $startParameter): ?array
    {
        return $this->db->transaction(function () use ($userId, $startParameter) {
            $campaign = $this->db->one('SELECT * FROM advertising_campaigns WHERE start_parameter=? AND is_active=1'.$this->db->lock(), [$startParameter]);
            if (!$campaign) return null;
            $user=$this->db->one('SELECT disabled FROM users WHERE id=?',[$userId]);
            if (!$user || (int)$user['disabled']===1) throw new BillingError('Аккаунт недоступен.');
            if ($this->db->one('SELECT id FROM advertising_campaign_registrations WHERE campaign_id=? AND user_id=?', [$campaign['id'], $userId])) return $campaign;
            $this->db->execute('INSERT INTO advertising_campaign_registrations(id,campaign_id,user_id,bonus_granted,created_at) VALUES(?,?,?,0,?)', [Database::id(), $campaign['id'], $userId, time()]);
            $this->grant($userId, $campaign);
            $this->db->execute('UPDATE advertising_campaign_registrations SET bonus_granted=1 WHERE campaign_id=? AND user_id=?', [$campaign['id'], $userId]);
            return $campaign;
        });
    }
    private function grant(string $userId, array $campaign): void
    {
        $type = $campaign['bonus_type'];
        if ($type === 'balance' && (int)$campaign['balance_bonus_kopeks'] > 0) {
            $this->wallet->credit($userId, (int)$campaign['balance_bonus_kopeks'], 'promo_credit', 'Бонус кампании: '.$campaign['name']);
        } elseif ($type === 'subscription' && (int)$campaign['subscription_duration_days'] > 0) {
            $days = (int)$campaign['subscription_duration_days'];
            $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1", [$userId]);
            if ($sub) {
                $base = max(time(), (int)$sub['expires_at']);
                $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active' WHERE id=?", [$base + $days * 86400, $sub['id']]);
                $this->outbox->enqueue('subscription.extend', 'campaign-extend:'.$campaign['id'].':'.$sub['id'], ['subscription_id' => $sub['id']]);
            } else {
                $plan = $this->db->one('SELECT * FROM plans WHERE id=?', [$campaign['plan_id']]);
                if (!$plan) throw new BillingError('Тариф кампании больше недоступен.');
                $subId = Database::id();
                $now = time();
                $this->db->execute(
                    "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,start_date,lifecycle_status,updated_at) VALUES(?,NULL,?,'provisioning',?,?,?,?,?,0,?,'pending',?)",
                    [$subId,$userId,$now+$days*86400,$now,$plan['id'],intdiv((int)$plan['traffic_bytes'],1073741824),(int)$plan['devices'],$now,$now]
                );
                $this->outbox->enqueue('subscription.provision', 'provision:'.$subId, ['subscription_id' => $subId]);
            }
        } else {
            throw new BillingError('Бонус кампании не настроен.');
        }
    }
}
