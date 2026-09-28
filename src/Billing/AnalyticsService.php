<?php
declare(strict_types=1);
namespace App\Billing;

use App\Infrastructure\Database;
use Symfony\Component\HttpFoundation\Request;

/** Product and financial analytics, isolated from billing failures. */
final class AnalyticsService
{
    public function __construct(private Database $db) {}

    /** Capture only allow-listed UTM values; never persist IPs, headers, or profile data. */
    public function captureVisit(Request $request,string $route,?string $userId=null): array
    {
        $anonymousId=(string)$request->cookies->get('zb_anon','');
        $newAnonymous=!preg_match('/^[a-f0-9]{32,64}$/D',$anonymousId);
        if($newAnonymous)$anonymousId=bin2hex(random_bytes(16));
        $sessionId=(string)$request->cookies->get('zb_analytics_session','');
        $newSession=!preg_match('/^[a-f0-9]{32,64}$/D',$sessionId);
        if($newSession)$sessionId=bin2hex(random_bytes(16));
        try {
            $now=time();
            $landing=substr((string)$request->getPathInfo(),0,500);
            $attrs=[];
            foreach(['source'=>'utm_source','medium'=>'utm_medium','campaign'=>'utm_campaign','content'=>'utm_content','term'=>'utm_term'] as $key=>$param)$attrs[$key]=$this->clean($request->query->get($param),$key==='campaign'?160:120);
            $attrs['landing_page']=$landing;
            $attrs['referral_code']=$this->clean($request->query->get('ref'),100);
            $attrs['click_id']=$this->clean($request->query->get('click_id'),160);
            $attrs['campaign_id']=$this->clean($request->query->get('campaign_id'),100);
            if($attrs['source']===null&&$attrs['referral_code']!==null){$attrs['source']='referral';$attrs['medium']='referral';}
            if($attrs['campaign']===null&&$attrs['campaign_id']!==null)$attrs['campaign']=$attrs['campaign_id'];
            $hasSource=false;foreach(['source','medium','campaign','content','term','referral_code','click_id','campaign_id'] as $key)if($attrs[$key]!==null)$hasSource=true;
            $existing=$this->db->one('SELECT anonymous_id FROM analytics_attribution WHERE anonymous_id=?',[$anonymousId]);
            if(!$existing){
                // Explicit direct attribution is preferable to a permanently
                // blank first touch when the visitor first arrives without UTMs.
                if(!$hasSource){$attrs['source']='direct';$attrs['medium']='none';}
                $this->db->execute('INSERT INTO analytics_attribution(anonymous_id,user_id,first_source,first_medium,first_campaign,first_content,first_term,first_landing_page,first_referral_code,first_click_id,first_campaign_id,first_touch_at,last_source,last_medium,last_campaign,last_content,last_term,last_landing_page,last_referral_code,last_click_id,last_campaign_id,last_touch_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                    $anonymousId,$userId,$attrs['source'],$attrs['medium'],$attrs['campaign'],$attrs['content'],$attrs['term'],$landing,$attrs['referral_code'],$attrs['click_id'],$attrs['campaign_id'],$now,
                    $attrs['source'],$attrs['medium'],$attrs['campaign'],$attrs['content'],$attrs['term'],$landing,$attrs['referral_code'],$attrs['click_id'],$attrs['campaign_id'],$now,$now,$now,
                ]);
            } else {
                if($userId!==null)$this->db->execute('UPDATE analytics_attribution SET user_id=COALESCE(user_id,?),updated_at=? WHERE anonymous_id=?',[$userId,$now,$anonymousId]);
                if($hasSource)$this->db->execute("UPDATE analytics_attribution SET first_source=?,first_medium=?,first_campaign=?,first_content=?,first_term=?,first_landing_page=?,first_referral_code=?,first_click_id=?,first_campaign_id=?,first_touch_at=? WHERE anonymous_id=? AND first_source='direct'",[$attrs['source'],$attrs['medium'],$attrs['campaign'],$attrs['content'],$attrs['term'],$landing,$attrs['referral_code'],$attrs['click_id'],$attrs['campaign_id'],$now,$anonymousId]);
                if($hasSource)$this->db->execute('UPDATE analytics_attribution SET last_source=COALESCE(?,last_source),last_medium=COALESCE(?,last_medium),last_campaign=COALESCE(?,last_campaign),last_content=COALESCE(?,last_content),last_term=COALESCE(?,last_term),last_landing_page=?,last_referral_code=COALESCE(?,last_referral_code),last_click_id=COALESCE(?,last_click_id),last_campaign_id=COALESCE(?,last_campaign_id),last_touch_at=?,updated_at=? WHERE anonymous_id=?',[$attrs['source'],$attrs['medium'],$attrs['campaign'],$attrs['content'],$attrs['term'],$landing,$attrs['referral_code'],$attrs['click_id'],$attrs['campaign_id'],$now,$now,$anonymousId]);
            }
            $event=match($route){'home','landing'=>'landing_viewed','plans'=>'pricing_viewed',default=>null};
            if($event)$this->track($event,$userId,$anonymousId,$sessionId,['landing_page'=>$landing]);
            if($userId!==null)$this->db->execute('UPDATE analytics_attribution SET user_id=COALESCE(user_id,?) WHERE anonymous_id=?',[$userId,$anonymousId]);
        } catch(\Throwable $e) { error_log('analytics.capture failed: '.get_class($e)); }
        return ['anonymous_id'=>$anonymousId,'session_id'=>$sessionId,'new_anonymous'=>$newAnonymous,'new_session'=>$newSession];
    }

    public function associateRegistration(string $userId,string $anonymousId,string $sessionId=''): void
    {
        try{$this->db->execute('UPDATE analytics_attribution SET user_id=COALESCE(user_id,?),updated_at=? WHERE anonymous_id=?',[$userId,time(),$anonymousId]);$this->db->execute('UPDATE analytics_events SET user_id=COALESCE(user_id,?) WHERE anonymous_id=?',[$userId,$anonymousId]);$this->track('registered',$userId,$anonymousId,$sessionId,[],'registered:'.$userId);}
        catch(\Throwable $e){error_log('analytics.registration failed: '.get_class($e));}
    }

    public function track(string $name,?string $userId=null,?string $anonymousId=null,?string $sessionId=null,array $properties=[],?string $eventKey=null,?int $occurredAt=null): bool
    {
        try {
            if(!preg_match('/^[a-z][a-z0-9_]{1,79}$/D',$name))return false;
            $props=json_encode($properties,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            $attr=$userId!==null?$this->db->one('SELECT last_source,last_medium,last_campaign FROM analytics_attribution WHERE user_id=? ORDER BY last_touch_at DESC LIMIT 1',[$userId]):null;
            if(!$attr&&$anonymousId!==null)$attr=$this->db->one('SELECT last_source,last_medium,last_campaign FROM analytics_attribution WHERE anonymous_id=?',[$anonymousId]);
            $this->db->execute('INSERT INTO analytics_events(id,event_key,event_name,user_id,anonymous_id,session_id,source,medium,campaign,properties,occurred_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(event_key) DO NOTHING',[
                Database::id(),$eventKey,$name,$userId,$anonymousId,$sessionId,$attr['last_source']??null,$attr['last_medium']??null,$attr['last_campaign']??null,$props,$occurredAt??time(),time(),
            ]);
            return true;
        } catch(\Throwable $e) { error_log('analytics.event '.$name.' failed: '.get_class($e));return false; }
    }

    public function trackPlanSelected(string $userId,string $anonymousId,string $sessionId,array $plan,int $price): void
    { $this->track('plan_selected',$userId,$anonymousId,$sessionId,['plan_id'=>(string)$plan['id'],'price_minor'=>$price,'currency'=>(string)$plan['currency'],'duration_days'=>(int)$plan['duration_days']]); }

    public function trackSettledOrder(array $order,array $payment,string $purpose,?string $subscriptionId): void
    {
        $attempt=$this->db->one("SELECT id FROM payment_attempts WHERE entity_type='order' AND entity_id=? ORDER BY created_at DESC LIMIT 1",[$order['id']]);
        $this->track('payment_succeeded',(string)$order['user_id'],null,null,[
            'payment_id'=>(string)$payment['id'],'payment_attempt_id'=>$attempt['id']??null,'provider'=>(string)$payment['provider'],'provider_payment_id'=>(string)$payment['provider_payment_id'],
            'amount_minor'=>(int)$payment['amount_minor'],'currency'=>(string)$payment['currency'],'plan_id'=>(string)$order['plan_id'],'payment_purpose'=>$purpose,
            'order_id'=>(string)$order['id'],'subscription_id'=>$subscriptionId,
        ],'payment_succeeded:'.$payment['id'],(int)($payment['paid_at']??time()));
        if($subscriptionId!==null&&$purpose==='renewal'){
            $this->track('subscription_extended',(string)$order['user_id'],null,null,['order_id'=>(string)$order['id'],'subscription_id'=>$subscriptionId,'plan_id'=>(string)$order['plan_id'],'reason'=>'renewal'],'subscription_extended:'.$payment['id'],(int)($payment['paid_at']??time()));
            $this->track('renewal_succeeded',(string)$order['user_id'],null,null,['order_id'=>(string)$order['id'],'subscription_id'=>$subscriptionId,'plan_id'=>(string)$order['plan_id']],'renewal_succeeded:'.$payment['id'],(int)($payment['paid_at']??time()));
        }elseif($subscriptionId!==null&&$purpose==='reactivation')$this->track('subscription_reactivated',(string)$order['user_id'],null,null,['order_id'=>(string)$order['id'],'subscription_id'=>$subscriptionId,'plan_id'=>(string)$order['plan_id'],'reason'=>'repurchase'],'subscription_reactivated:'.$payment['id'],(int)($payment['paid_at']??time()));
        elseif($subscriptionId!==null)$this->track('subscription_created',(string)$order['user_id'],null,null,['order_id'=>(string)$order['id'],'subscription_id'=>$subscriptionId,'plan_id'=>(string)$order['plan_id'],'origin'=>'purchase'],'subscription_created:'.$subscriptionId,(int)($payment['paid_at']??time()));
    }

    /** Track a cash top-up after its wallet credit transaction has committed. */
    public function trackSettledTopup(array $topup): void
    {
        $this->track('topup_succeeded',(string)$topup['user_id'],null,null,[
            'payment_id'=>'topup:'.(string)$topup['id'],'topup_id'=>(string)$topup['id'],
            'provider'=>(string)$topup['provider'],'provider_payment_id'=>(string)$topup['provider_payment_id'],
            'amount_minor'=>(int)$topup['amount_kopeks'],'currency'=>(string)$topup['currency'],
            'payment_purpose'=>'balance_topup','entity_type'=>'topup',
        ],'topup_succeeded:'.$topup['id'],(int)($topup['paid_at']??time()));
    }

    public function trackAttempt(array $attempt,string $event,array $properties=[]): void
    { $this->track($event,(string)$attempt['user_id'],null,null,['payment_attempt_id'=>(string)$attempt['id'],'provider'=>(string)$attempt['provider'],'amount_minor'=>(int)$attempt['amount_minor'],'currency'=>(string)$attempt['currency'],'plan_id'=>$attempt['plan_id']??null]+$properties,$event.':'.$attempt['id']); }

    private function clean(mixed $value,int $limit): ?string
    {
        if(!is_string($value))return null;$value=trim($value);if($value===''||strlen($value)>$limit)return null;
        return preg_replace('/[^\pL\pN._:@+-]/u','',$value)?:null;
    }

    public function dashboard(int $days=30): array
    {
        $days=max(1,min(90,$days));$now=time();$since=$now-$days*86400;$today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        $insights=new RevenueInsights($this->db);
        $breakdown=$insights->revenueBreakdown($since);
        $payments=$insights->subscriptionPayments($since);
        $revenue=$breakdown['total'];$newRevenue=$breakdown['new']+$breakdown['reactivation'];$renewalRevenue=$breakdown['renewal'];
        $purposeCounts=$breakdown['counts'];$buyers=array_fill_keys(array_keys($breakdown['buyers']),true);$newBuyers=array_fill_keys(array_keys($breakdown['new_buyers']),true);$checks=array_map(static fn($p)=>(int)$p['amount_minor'],$payments);
        $registrations=(int)($this->db->one('SELECT COUNT(*) c FROM users WHERE created_at>=?',[$since])['c']??0);
        $registeredBuyers=(int)($this->db->one("SELECT COUNT(DISTINCT u.id) c FROM users u JOIN payments p ON p.user_id=u.id WHERE u.created_at>=? AND p.paid_at>=? AND p.status='succeeded' AND p.provider='platega' AND p.provider_payment_id NOT LIKE 'balance_%'",[$since,$since])['c']??0);
        $attempts=(int)($this->db->one("SELECT COUNT(*) c FROM payment_attempts WHERE created_at>=? AND status IN ('succeeded','failed','cancelled','expired')",[$since])['c']??0);
        $succeeded=(int)($this->db->one("SELECT COUNT(*) c FROM payment_attempts WHERE created_at>=? AND status='succeeded'",[$since])['c']??0);
        $median=0;$durations=$this->db->all("SELECT succeeded_at-created_at AS seconds FROM payment_attempts WHERE created_at>=? AND succeeded_at IS NOT NULL",[$since]);$vals=array_map(static fn($r)=>(int)$r['seconds'],$durations);sort($vals);if($vals)$median=$vals[(int)floor((count($vals)-1)/2)];
        $funnelResult=$insights->funnel($since);$funnel=$funnelResult['stages'];
        $daily=[];$zone=new \DateTimeZone('Europe/Moscow');for($i=$days-1;$i>=0;$i--){$day=(new \DateTimeImmutable('@'.($today-$i*86400)))->setTimezone($zone)->format('Y-m-d');$daily[$day]=['total'=>0,'new'=>0,'renewal'=>0];}
        foreach($payments as $p){$day=(new \DateTimeImmutable('@'.(int)$p['paid_at']))->setTimezone($zone)->format('Y-m-d');if(!isset($daily[$day]))continue;$amount=(int)$p['amount_minor'];$daily[$day]['total']+=$amount;$rp=$p['revenue_purpose'];if(in_array($rp,['new','reactivation'],true))$daily[$day]['new']+=$amount;if($rp==='renewal')$daily[$day]['renewal']+=$amount;}
        $todayRevenue=$this->cashReceiptsSince($today);
        $weekRevenue=$this->cashReceiptsSince($now-7*86400);
        $monthRevenue=$this->cashReceiptsSince($now-30*86400);
        $provisioning=$insights->provisioningHealth($since);
        $sinceRow=$this->db->one("SELECT MIN(paid_at) since FROM (SELECT paid_at FROM payments WHERE status='succeeded' AND provider='platega' AND provider_payment_id NOT LIKE 'balance_%' UNION ALL SELECT paid_at FROM topups WHERE status='paid' AND provider='platega') history");
        $migrationRow=$this->db->one("SELECT applied_at FROM migrations WHERE version='045_business_analytics.sql'");
        $maxDaily=max(1,...array_values(array_map(static fn($day)=>$day['total'],$daily)));$points=['total'=>[],'new'=>[],'renewal'=>[]];$i=0;
        foreach($daily as $day){$x=count($daily)>1?round($i*800/(count($daily)-1),1):400;foreach(['total','new','renewal'] as $series)$points[$series][]=$x.','.round(180-($day[$series]/$maxDaily)*160,1);$i++;}
        return ['days'=>$days,'since'=>$since,'history_since'=>$sinceRow['since']?gmdate('d M Y',(int)$sinceRow['since']):null,'revenue'=>$revenue,'today_revenue'=>$todayRevenue,'revenue_7d'=>$weekRevenue,'revenue_30d'=>$monthRevenue,'chart_points'=>array_map(static fn($list)=>implode(' ',$list),$points),'new_revenue'=>$newRevenue,'renewal_revenue'=>$renewalRevenue,
            'paid_customers'=>count($buyers),'new_paid_customers'=>count($newBuyers),'renewals'=>$purposeCounts['renewal']??0,'arppu'=>count($buyers)?intdiv($revenue,count($buyers)):0,'average_check'=>$checks?intdiv($revenue,count($checks)):0,'median_time_to_pay'=>$median,'registrations'=>$registrations,'conversion'=>$registrations?round($registeredBuyers*100/$registrations,1):0,
            'payment_success_rate'=>$attempts?round($succeeded*100/$attempts,1):0,'funnel'=>$funnel,'funnel_meta'=>$funnelResult,'daily'=>$daily,'provisioning'=>$provisioning,'payment_attempts'=>$this->paymentStats($since),'campaigns'=>$this->campaignStats($since),'trial'=>$insights->trialConversion(),'churn'=>$insights->churn(),'cohorts'=>$this->cohorts($since),'reactivations'=>$breakdown['reactivations'],'health'=>$this->health($insights)];
    }

    private function cashReceiptsSince(int $since): int
    {
        $row=$this->db->one("SELECT COALESCE((SELECT SUM(amount_minor) FROM payments WHERE provider='platega' AND status='succeeded' AND provider_payment_id NOT LIKE 'balance_%' AND paid_at>=?),0)+COALESCE((SELECT SUM(amount_kopeks) FROM topups WHERE provider='platega' AND status='paid' AND paid_at>=?),0) s",[$since,$since]);
        return (int)($row['s']??0);
    }

    private function paymentStats(int $since): array
    {
        $states=$this->db->all('SELECT status,COUNT(*) count FROM payment_attempts WHERE created_at>=? GROUP BY status ORDER BY status',[$since]);
        $stages=$this->db->one("SELECT COUNT(*) created, SUM(CASE WHEN redirected_at IS NOT NULL THEN 1 ELSE 0 END) redirected, SUM(CASE WHEN returned_at IS NOT NULL THEN 1 ELSE 0 END) returned, SUM(CASE WHEN status='succeeded' THEN 1 ELSE 0 END) succeeded, SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed, SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) expired, SUM(CASE WHEN status IN ('creating','pending','unknown') THEN 1 ELSE 0 END) pending FROM payment_attempts WHERE created_at>=?",[$since])??[];
        $reasons=$this->db->all("SELECT COALESCE(provider_error_code,provider_error_message,last_error,'Причина не передана провайдером') reason,COUNT(*) count FROM payment_attempts WHERE created_at>=? AND status IN ('cancelled','failed','expired') GROUP BY COALESCE(provider_error_code,provider_error_message,last_error,'Причина не передана провайдером') ORDER BY count DESC LIMIT 12",[$since]);
        $plans=$this->db->all("SELECT COALESCE(pl.name,pa.plan_id,'Без тарифа') plan_name,pa.status,COUNT(*) count FROM payment_attempts pa LEFT JOIN plans pl ON pl.id=pa.plan_id WHERE pa.created_at>=? AND pa.entity_type='order' GROUP BY pa.plan_id,pl.name,pa.status ORDER BY plan_name,pa.status",[$since]);
        $providers=$this->db->all("SELECT provider,COALESCE(payment_method,'неизвестно') payment_method,status,COUNT(*) count FROM payment_attempts WHERE created_at>=? GROUP BY provider,payment_method,status ORDER BY provider,payment_method,status",[$since]);
        return ['states'=>$states,'stages'=>$stages,'reasons'=>$reasons,'plans'=>$plans,'providers'=>$providers];
    }

    public function campaignStats(int $since): array
    {
        $campaigns=$this->db->all('SELECT * FROM marketing_campaigns ORDER BY created_at DESC');
        foreach($campaigns as &$campaign){
            $key=$campaign['campaign_key'];$source=$campaign['source'];$medium=$campaign['medium'];
            $reg=(int)($this->db->one("SELECT COUNT(DISTINCT u.id) c FROM users u JOIN analytics_attribution a ON a.user_id=u.id WHERE u.created_at>=? AND a.first_campaign=? AND a.first_source=? AND (? IS NULL OR a.first_medium=?)",[$since,$key,$source,$medium,$medium])['c']??0);
            $buyers=(int)($this->db->one("SELECT COUNT(DISTINCT p.user_id) c FROM payments p JOIN analytics_attribution a ON a.user_id=p.user_id WHERE p.provider='platega' AND p.status='succeeded' AND p.payment_purpose='first_purchase' AND p.provider_payment_id NOT LIKE 'balance_%' AND p.paid_at>=? AND a.first_campaign=? AND a.first_source=? AND (? IS NULL OR a.first_medium=?)",[$since,$key,$source,$medium,$medium])['c']??0);
            $revenue=(int)($this->db->one("SELECT COALESCE(SUM(p.amount_minor),0) s FROM payments p JOIN analytics_attribution a ON a.user_id=p.user_id WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id NOT LIKE 'balance_%' AND p.paid_at>=? AND a.first_campaign=? AND a.first_source=? AND (? IS NULL OR a.first_medium=?)",[$since,$key,$source,$medium,$medium])['s']??0);
            $spend=(int)$campaign['spend_minor'];$campaign['registrations']=$reg;$campaign['buyers']=$buyers;$campaign['revenue']=$revenue;$campaign['conversion']=$reg?round($buyers*100/$reg,1):null;$campaign['cac']=$buyers?intdiv($spend,$buyers):null;$campaign['arppu']=$buyers?intdiv($revenue,$buyers):null;$campaign['roas']=$spend?round($revenue/$spend,2):null;
        }unset($campaign);return $campaigns;
    }

    private function cohorts(int $since): array
    {
        $rows=$this->db->all("SELECT user_id,paid_at FROM payments WHERE status='succeeded' AND provider='platega' AND provider_payment_id NOT LIKE 'balance_%' ORDER BY user_id,paid_at");$customers=[];
        foreach($rows as $row){$user=(string)$row['user_id'];$month=gmdate('Y-m',(int)$row['paid_at']);if(!isset($customers[$user])){$customers[$user]=['cohort'=>$month,'first_paid'=>(int)$row['paid_at'],'months'=>[$month=>true]];continue;}if(strtotime($month.'-01 UTC')>=strtotime($customers[$user]['cohort'].'-01 UTC'))$customers[$user]['months'][$month]=true;}
        $groups=[];$offsets=[1,2,3,6];
        $currentMonth=(new \DateTimeImmutable('first day of this month',new \DateTimeZone('UTC')))->getTimestamp();
        foreach($customers as $customer){$cohort=$customer['cohort'];$firstAt=strtotime($cohort.'-01 UTC');if($customer['first_paid']<$since)continue;$groups[$cohort]??=['cohort'=>$cohort,'customers'=>0,'m1'=>null,'m2'=>null,'m3'=>null,'m6'=>null];$groups[$cohort]['customers']++;foreach($offsets as $offset){$monthAt=(new \DateTimeImmutable('@'.$firstAt))->setTimezone(new \DateTimeZone('UTC'))->modify('+'.$offset.' months')->getTimestamp();if($monthAt>=$currentMonth)continue;$month=gmdate('Y-m',$monthAt);$groups[$cohort]['m'.$offset]??=0;if(isset($customer['months'][$month]))$groups[$cohort]['m'.$offset]++;}}
        krsort($groups);return array_slice(array_values($groups),0,12);
    }

    public function createCampaign(array $input,string $actor): void
    {
        $key=trim((string)($input['campaign_key']??''));$name=trim((string)($input['name']??''));$source=trim((string)($input['source']??''));$medium=trim((string)($input['medium']??''));$spend=filter_var($input['spend']??'0',FILTER_VALIDATE_INT);$currency=strtoupper(trim((string)($input['currency']??'RUB')));
        if(!preg_match('/^[\pL\pN][\pL\pN._-]{1,159}$/uD',$key)||$name===''||mb_strlen($name)>255||$source===''||mb_strlen($source)>120||($medium!==''&&mb_strlen($medium)>120)||$spend===false||$spend<0||$spend>100000000||$currency!=='RUB')throw new BillingError('Проверьте кампанию: нужны ключ, название, источник и расход в рублях.');
        $started=$this->campaignDate($input['started_at']??'');$ended=$this->campaignDate($input['ended_at']??'');if($started&&$ended&&$ended<$started)throw new BillingError('Дата окончания кампании раньше даты начала.');
        try{$id=Database::id();$this->db->execute('INSERT INTO marketing_campaigns(id,campaign_key,name,source,medium,started_at,ended_at,spend_minor,currency,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$key,$name,$source,$medium?:null,$started,$ended,$spend*100,$currency,$actor,time()]);$this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$actor,'analytics.campaign.created',$id,time()]);}
        catch(\PDOException $e){if(in_array($e->getCode(),['23000','23505'],true))throw new BillingError('Кампания с таким UTM campaign уже существует.');throw $e;}
    }

    private function campaignDate(mixed $value): ?int
    { if(!is_string($value)||$value==='')return null;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));return $date&&$date->format('Y-m-d')===$value?$date->getTimestamp():throw new BillingError('Неверный формат даты кампании.'); }

    private function health(?RevenueInsights $insights=null): array
    {
        $insights ??= new RevenueInsights($this->db);
        $attr=$insights->attributionHealth();
        $stale=$insights->staleAttempts();
        return [
            'payments_without_event'=>(int)($this->db->one("SELECT COUNT(*) c FROM payments p LEFT JOIN analytics_events e ON e.event_key='payment_succeeded:'||p.id WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id NOT LIKE 'balance_%' AND e.id IS NULL")['c']??0),
            'topups_without_event'=>(int)($this->db->one("SELECT COUNT(*) c FROM topups t LEFT JOIN analytics_events e ON e.event_key='topup_succeeded:'||t.id WHERE t.provider='platega' AND t.status='paid' AND e.id IS NULL")['c']??0),
            'successful_without_subscription'=>(int)($this->db->one("SELECT COUNT(*) c FROM payments p LEFT JOIN subscriptions s ON s.order_id=p.order_id WHERE p.provider='platega' AND p.status='succeeded' AND p.provider_payment_id NOT LIKE 'balance_%' AND s.id IS NULL")['c']??0),
            'subscriptions_without_origin'=>(int)($this->db->one("SELECT COUNT(*) c FROM subscriptions WHERE subscription_origin IS NULL")['c']??0),
            'stale_attempts'=>array_sum($stale),
            'stale_attempts_detail'=>$stale,
            'unknown_attribution'=>$attr['legacy_pre_tracking']+$attr['missing_post_tracking'],
            'attribution_detail'=>$attr,
            'dead_analytics_jobs'=>(int)($this->db->one("SELECT COUNT(*) c FROM outbox WHERE topic='analytics.record' AND status='dead'")['c']??0),
        ];
    }
}
