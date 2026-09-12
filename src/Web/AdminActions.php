<?php
declare(strict_types=1);
namespace App\Web;
use App\Settings\{IntegrationCheck,Readiness};
use App\Infrastructure\Database;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\{Response,RedirectResponse};
trait AdminActions
{
    private function administration(string $handler,string $id,string $sid=''):Response
    {
        $input=$this->request->request;$db=$this->app->db;$uid=$this->user['id'];
        if($handler==='admin-config-save'){
            $this->app->settings->save($input->all(),$uid,$input->getInt('revision',-1));return new RedirectResponse('/admin/config?saved=1',303);
        }
        if($handler==='admin-check'){
            (new IntegrationCheck($this->app))->check($id,$input->get('register')==='1');$this->app->billing->audit($uid,'integration.checked',$id);return new RedirectResponse('/admin/config?checked=1',303);
        }
        if($handler==='admin-config')return $this->render('admin-config',array_merge($this->app->settings->form(),['checks'=>$db->all('SELECT integration,status,checked_at FROM integration_checks'),'saved'=>$this->request->query->has('saved'),'checked'=>$this->request->query->has('checked')]));
        if($handler==='admin-sync'){
            if($this->app->config['PAYMENT_DRIVER']==='demo' || empty($this->app->config['REMNAWAVE_URL']) || empty($this->app->config['REMNAWAVE_TOKEN']))throw new BillingError('Сначала настройте Remnawave и реальный платежный драйвер.');
            $sync=new \App\Integration\RemnawaveSync($db,new \App\Integration\RemnawaveProvisioner(\Symfony\Component\HttpClient\HttpClient::create(),$this->app->config['REMNAWAVE_URL'],$this->app->config['REMNAWAVE_TOKEN'],$this->app->config['REMNAWAVE_SQUAD_UUID']));
            $report=$sync->run(100,true);
            $this->app->billing->audit($uid,'remnawave.sync',sprintf('checked=%d fixed=%d reprovisioned=%d disabled=%d errors=%d',$report['checked'],$report['fixed'],$report['reprovisioned'],$report['disabled'],$report['errors']));
            return $this->render('admin-sync',['report'=>$report]);
        }
        if($handler==='admin-readiness')return $this->render('readiness',['checks'=>Readiness::report($this->app)]);
        if($handler==='admin-plans')return $this->render('admin-plans',['plans'=>$db->all('SELECT * FROM plans ORDER BY active DESC,price_minor')]);
        if($handler==='admin-plan-save'){
            $name=trim($input->get('name',''));$price=filter_var($input->get('price_minor'),FILTER_VALIDATE_INT);$days=$input->getInt('duration_days');$devices=$input->getInt('devices');$traffic=filter_var($input->get('traffic_gb'),FILTER_VALIDATE_INT);$squad=trim($input->get('squad_uuid',''));
            if(!$name||mb_strlen($name)>100||$price<100||$price>100000000||$days<1||$days>3650||$devices<0||$devices>20||$traffic===false||$traffic<0||$traffic>100000||($squad!==''&&!preg_match('/^[0-9a-f-]{36}$/iD',$squad)))throw new BillingError('Проверьте параметры тарифа. Цена указывается в копейках.');
            $db->transaction(function()use($db,$input,$name,$price,$days,$devices,$traffic,$squad,$id,$uid){
                if(!$db->one('SELECT id FROM plans WHERE id=?'.$db->lock(),[$id]))throw new BillingError('Тариф не найден.');
                $db->execute('UPDATE plans SET name=?,price_minor=?,duration_days=?,devices=?,traffic_bytes=?,squad_uuid=?,active=? WHERE id=?',[$name,$price,$days,$devices,$traffic*1073741824,$squad,$input->get('active')==='1'?1:0,$id]);$this->app->billing->audit($uid,'plan.updated',$id);
            });return new RedirectResponse('/admin/plans',303);
        }
        if($handler==='admin-users')return $this->render('admin-users',['users'=>$db->all('SELECT id,email,telegram_id,role,disabled,balance_kopeks,created_at FROM users ORDER BY created_at DESC LIMIT 100')]);
        if($handler==='admin-user-search'){
            $query=$this->request->query->get('q','');
            return $this->render('admin-users',['users'=>$this->app->userAdmin->search($query),'query'=>$query]);
        }
        if($handler==='admin-user')return $this->render('admin-user',['profile'=>$this->app->userAdmin->profile($id),'plans'=>$db->all('SELECT id,name FROM plans ORDER BY name'),'sub_removed'=>$this->request->query->has('sub_removed')]);
        if($handler==='admin-user-balance'){
            $amount=filter_var($input->get('amount'),FILTER_VALIDATE_INT);
                if ($amount===false || $amount < -1000000 || $amount > 1000000) throw new BillingError('Некорректная сумма.');
            $this->app->userAdmin->adjustBalance($id,($amount??0)*100,$input->get('reason',''),$uid);return new RedirectResponse('/admin/users/'.$id,303);
        }
        if($handler==='admin-user-days'){
            $this->app->userAdmin->grantDays($id,$input->getInt('days',0),$input->get('plan_id','')?:null,$uid);return new RedirectResponse('/admin/users/'.$id,303);
        }
        if($handler==='admin-user-discount'){
            $this->app->userAdmin->setDiscount($id,$input->getInt('percent',0),$input->getInt('hours',0),$uid);return new RedirectResponse('/admin/users/'.$id,303);
        }
        if($handler==='admin-user-discount-clear'){
            $this->app->userAdmin->clearDiscount($id,$uid);return new RedirectResponse('/admin/users/'.$id,303);
        }
        if($handler==='admin-user-subscription-remove'){
            $this->app->userAdmin->removeSubscription($id,$sid,$uid);return new RedirectResponse('/admin/users/'.$id.'?sub_removed=1',303);
        }
        if($handler==='admin-subscription')return $this->render('admin-subscription',['data'=>$this->app->userAdmin->subscription($sid),'saved'=>$this->request->query->has('saved')]);
        if($handler==='admin-subscription-traffic'){
            $this->app->userAdmin->updateSubscriptionTraffic($id,$sid,$input->getInt('traffic_gb',-1),$uid);return new RedirectResponse('/admin/subscriptions/'.$sid.'?saved=1',303);
        }
        if($handler==='admin-subscription-devices'){
            $this->app->userAdmin->updateSubscriptionDevices($id,$sid,$input->getInt('devices',-1),$uid);return new RedirectResponse('/admin/subscriptions/'.$sid.'?saved=1',303);
        }
        if($handler==='admin-subscription-extend'){
            $this->app->userAdmin->extendSubscription($id,$sid,$input->getInt('days',0),$uid);return new RedirectResponse('/admin/subscriptions/'.$sid.'?saved=1',303);
        }
        if($handler==='admin-subscription-expiry'){
            $expiry=$input->get('expires_at','');
            $ts=strtotime($expiry.' 23:59:59 UTC');
            if($ts===false)throw new BillingError('Укажите дату окончания правильно.');
            $this->app->userAdmin->setSubscriptionExpiry($id,$sid,$ts,$uid);return new RedirectResponse('/admin/subscriptions/'.$sid.'?saved=1',303);
        }
        if($handler==='admin-subscription-reset-traffic'){
            $this->app->userAdmin->resetSubscriptionTraffic($id,$sid,$uid);return new RedirectResponse('/admin/subscriptions/'.$sid.'?saved=1',303);
        }
        if($handler==='admin-promocodes')return $this->render('admin-promocodes',['promocodes'=>$this->app->promocodes->list(),'plans'=>$db->all('SELECT id,name FROM plans ORDER BY name')]);
        if($handler==='admin-promocode-create'){
            $this->app->promocodes->create($input->all(),$uid);return new RedirectResponse('/admin/promocodes',303);
        }
        if($handler==='admin-promocode-toggle'){
            $this->app->promocodes->toggle($id,$input->get('active')==='1',$uid);return new RedirectResponse('/admin/promocodes',303);
        }
        if($handler==='admin-withdrawals')return $this->render('admin-withdrawals',['withdrawals'=>$this->app->referrals->allWithdrawals()]);
        if($handler==='admin-withdrawal-process'){
            $this->app->referrals->processWithdrawal($id,$input->get('status',''),$input->get('admin_comment',''),$uid);return new RedirectResponse('/admin/withdrawals',303);
        }
        if($handler==='admin-broadcasts')return $this->render('admin-broadcasts',['broadcasts'=>$this->app->broadcasts->list()]);
        if($handler==='admin-broadcast-create'){
            $this->app->broadcasts->create($input->get('target_type',''),$input->get('message_text',''),$uid,$this->user['email']??'admin',$input->get('category','system'));return new RedirectResponse('/admin/broadcasts',303);
        }
        if($handler==='admin-compensations')return $this->render('admin-compensations',['compensations'=>$this->app->compensations->list()]);
        if($handler==='admin-compensation-create'){
            $kind=$input->get('kind','');
            $value=$input->getInt('value',0);
            if($kind==='balance')$value*=100;
            $this->app->compensations->create($input->get('segment',''),$kind,$value,$input->get('reason',''),$uid,$this->user['email']??'admin');return new RedirectResponse('/admin/compensations',303);
        }
        if($handler==='admin-channels')return $this->render('admin-channels',['channels'=>$this->app->channels->list()]);
        if($handler==='admin-channel-add'){
            $this->app->channels->add($input->get('channel_id',''),$input->get('channel_link',''),$input->get('title',''),$uid);return new RedirectResponse('/admin/channels',303);
        }
        if($handler==='admin-channel-toggle'){
            $this->app->channels->toggle($id,$input->get('active')==='1',$uid);return new RedirectResponse('/admin/channels',303);
        }
        if($handler==='admin-channel-remove'){
            $this->app->channels->remove($id,$uid);return new RedirectResponse('/admin/channels',303);
        }
        if($handler==='admin-landings')return $this->render('admin-landings',['landings'=>$this->app->landings->list()]);
        if($handler==='admin-landing-save'){
            $this->app->landings->save($input->all(),$uid);return new RedirectResponse('/admin/landings',303);
        }
        if($handler==='admin-landing-toggle'){
            $this->app->landings->toggle($id,$input->get('active')==='1',$uid);return new RedirectResponse('/admin/landings',303);
        }
        if($handler==='admin-contests')return $this->render('admin-contests',['templates'=>$this->app->contests->listTemplates()]);
        if($handler==='admin-contest-create'){
            $this->app->contests->createTemplate($input->all(),$uid);return new RedirectResponse('/admin/contests',303);
        }
        if($handler==='admin-contest-round'){
            $this->app->contests->startRound($id,$uid);return new RedirectResponse('/admin/contests',303);
        }
        if($handler==='admin-polls')return $this->render('admin-polls',['polls'=>$this->app->polls->list()]);
        if($handler==='admin-poll-create'){
            $questions=[];
            foreach ($input->all('q_text') as $i=>$text) {
                $questions[]=['text'=>$text,'options'=>array_values(array_filter($input->all('q_options_'.$i)))];
            }
            $this->app->polls->create(['title'=>$input->get('title',''),'description'=>$input->get('description',''),'reward_amount_kopeks'=>$input->get('reward_amount_kopeks','0'),'questions'=>$questions],$uid);return new RedirectResponse('/admin/polls',303);
        }
        if($handler==='admin-campaigns')return $this->render('admin-campaigns',['campaigns'=>$this->app->campaigns->list()]);
        if($handler==='admin-campaign-create'){
            $this->app->campaigns->create($input->all(),$uid);return new RedirectResponse('/admin/campaigns',303);
        }
        if($handler==='admin-reports'){
            $days=$this->request->query->getInt('days',30);
            if($days<1||$days>365)$days=30;
            $stats=$this->app->reporting->salesStats($days);
            $daily=$this->app->reporting->dailyRevenue(min($days,30));
            $byProvider=$this->app->reporting->revenueByProvider($days);
            $byPlan=$this->app->reporting->revenueByPlan($days);
            $byType=$this->app->reporting->revenueByType($days);
            $topCustomers=$this->app->reporting->topCustomers($days);
            $top=$this->app->reporting->topReferrers();
            $earnings=$this->app->reporting->earningsOverview();
            return $this->render('admin-reports',['stats'=>$stats,'daily'=>$daily,'by_provider'=>$byProvider,'by_plan'=>$byPlan,'by_type'=>$byType,'top_customers'=>$topCustomers,'top'=>$top,'days'=>$days,'earnings'=>$earnings]);
        }
        if($handler==='admin-monitoring')return $this->render('admin-monitoring',['events'=>$this->app->monitoring->recentEvents(),'errors'=>$this->app->monitoring->errors(),'anomalies'=>$this->app->monitoring->trafficAnomalies()]);
        if($handler==='admin-monitoring-clear'){
            $this->app->monitoring->clearErrors();return new RedirectResponse('/admin/monitoring',303);
        }
        if($handler==='admin-backups')return $this->render('admin-backups',['backups'=>$this->app->backups->list()]);
        if($handler==='admin-backup-create'){
            $this->app->backups->create();return new RedirectResponse('/admin/backups',303);
        }
        if($handler==='admin-backup-restore'){
            $this->app->backups->restore($id);return new RedirectResponse('/admin/backups',303);
        }
        if($handler==='admin-roles')return $this->render('admin-roles',['roles'=>$this->app->rbac->listRoles(),'permissions'=>\App\Billing\RbacService::PERMISSIONS,'users'=>$db->all('SELECT id,email,telegram_id FROM users ORDER BY created_at DESC LIMIT 100')]);
        if($handler==='admin-role-create'){
            $perms=array_values(array_filter($input->all('permissions')));
            $this->app->rbac->createRole($input->get('name',''),$input->get('description',''),$input->getInt('level',0),$perms,$uid);return new RedirectResponse('/admin/roles',303);
        }
        if($handler==='admin-role-assign'){
            $this->app->rbac->assignRole($input->get('user_id',''),$input->get('role_id',''),$uid);return new RedirectResponse('/admin/roles',303);
        }
        if($handler==='admin-role-revoke'){
            $this->app->rbac->revokeRole($input->get('user_id',''),$input->get('role_id',''),$uid);return new RedirectResponse('/admin/roles',303);
        }
        if($handler==='admin-audit')return $this->render('admin-audit',['audit'=>$this->app->rbac->auditLog()]);
        if($handler==='admin-maintenance'){
            $this->app->maintenance->setMaintenance($input->get('enabled')==='1',$uid);return new RedirectResponse('/admin',303);
        }
        if($handler==='admin-user-toggle'){
            $db->transaction(function()use($db,$id,$uid){
                $target=$db->one('SELECT * FROM users WHERE id=?'.$db->lock(),[$id]);
                if(!$target||$target['role']==='admin')throw new BillingError('Администраторов нельзя отключить этой операцией.');
                $db->execute('UPDATE users SET disabled=? WHERE id=?',[(int)$target['disabled']===1?0:1,$id]);$db->execute('DELETE FROM sessions WHERE user_id=?',[$id]);$this->app->billing->audit($uid,'user.access_changed',$id);
            });return new RedirectResponse('/admin/users',303);
        }
        throw new \LogicException('Unknown admin action');
    }
}
