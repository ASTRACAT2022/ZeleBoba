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
            $sync=new \App\Integration\RemnawaveSync($db,new \App\Integration\RemnawaveProvisioner(\Symfony\Component\HttpClient\HttpClient::create(),$this->app->config['REMNAWAVE_URL'],$this->app->config['REMNAWAVE_TOKEN'],$this->app->config['REMNAWAVE_SQUAD_UUID'],$this->app->circuitBreaker));
            $report=$sync->run(100,true);
            $this->app->billing->audit($uid,'remnawave.sync',sprintf('checked=%d fixed=%d reprovisioned=%d disabled=%d errors=%d',$report['checked'],$report['fixed'],$report['reprovisioned'],$report['disabled'],$report['errors']));
            return $this->render('admin-sync',['report'=>$report]);
        }
        if($handler==='admin-readiness')return $this->render('readiness',['checks'=>Readiness::report($this->app)]);
        if($handler==='admin-creators')return $this->render('admin-creators',['creators'=>$db->all("SELECT c.*,COALESCE((SELECT SUM(amount_minor) FROM creator_ledger l WHERE l.creator_id=c.id),0) earned FROM creators c ORDER BY c.created_at DESC")]);
        if($handler==='admin-creators-reconcile'){
            if($this->request->isMethod('POST')) {$this->app->creators->reconcile($uid); return new RedirectResponse('/admin/creators/reconciliation',303);}
            return $this->render('admin-creators-reconciliation',['last'=>$db->one('SELECT * FROM creator_reconciliation_runs ORDER BY started_at DESC LIMIT 1'),'missing'=>$db->all("SELECT p.id,p.amount_minor,p.paid_at FROM payments p JOIN creator_attributions a ON a.user_id=p.user_id LEFT JOIN creator_commissions c ON c.payment_id=p.id WHERE p.status='succeeded' AND c.id IS NULL LIMIT 100")]);
        }
        if($handler==='admin-plans')return $this->render('admin-plans',['plans'=>$db->all('SELECT * FROM plans ORDER BY active DESC,price_minor')]);
        if($handler==='admin-plan-save'){
            $name=trim($input->get('name',''));$price=filter_var($input->get('price_minor'),FILTER_VALIDATE_INT);$days=$input->getInt('duration_days');$devices=$input->getInt('devices');$traffic=filter_var($input->get('traffic_gb'),FILTER_VALIDATE_INT);$squad=trim($input->get('squad_uuid',''));
            // Optional per-tariff auto-renew tuning; empty string means "use global default" (NULL).
            $adbg=$input->get('autorenew_days_before','');$adb=($adbg==='')?null:(int)$adbg;
            $amfg=$input->get('autorenew_max_fails','');$amf=($amfg==='')?null:(int)$amfg;
            if(!$name||mb_strlen($name)>100||$price<100||$price>100000000||$days<1||$days>3650||$devices<0||$devices>20||$traffic===false||$traffic<0||$traffic>100000||($squad!==''&&!preg_match('/^[0-9a-f-]{36}$/iD',$squad))||($adb!==null&&($adb<1||$adb>14))||($amf!==null&&($amf<1||$amf>10)))throw new BillingError('Проверьте параметры тарифа. Цена указывается в копейках.');
            $db->transaction(function()use($db,$input,$name,$price,$days,$devices,$traffic,$squad,$adb,$amf,$id,$uid){
                if(!$db->one('SELECT id FROM plans WHERE id=?'.$db->lock(),[$id]))throw new BillingError('Тариф не найден.');
                $db->execute('UPDATE plans SET name=?,price_minor=?,duration_days=?,devices=?,traffic_bytes=?,squad_uuid=?,autorenew_days_before=?,autorenew_max_fails=?,active=? WHERE id=?',[$name,$price,$days,$devices,$traffic*1073741824,$squad,$adb,$amf,$input->get('active')==='1'?1:0,$id]);
                // Product changes create a new immutable version. Existing orders
                // and subscriptions remain pinned to their previous version.
                $next=(int)($db->one('SELECT COALESCE(MAX(version_number),0) v FROM plan_versions WHERE plan_id=?',[$id])['v']??0)+1;
                $db->execute('UPDATE plan_versions SET retired_at=? WHERE plan_id=? AND retired_at IS NULL',[time(),$id]);
                $db->execute('INSERT INTO plan_versions(id,plan_id,version_number,name,price_minor,currency,duration_days,duration_months,entitlements_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[Database::id(),$id,$next,$name,$price,'RUB',$days,0,json_encode(['vpn_access'=>true,'traffic_bytes'=>$traffic*1073741824,'devices'=>$devices],JSON_THROW_ON_ERROR),time()]);
                $this->app->billing->audit($uid,'plan.version_created',$id);
            });return new RedirectResponse('/admin/plans',303);
        }
        if($handler==='admin-users')return $this->render('admin-users',['users'=>$db->all('SELECT id,email,telegram_id,role,disabled,balance_kopeks,created_at FROM users ORDER BY created_at DESC LIMIT 100')]);
        if($handler==='admin-user-search'){
            $query=$this->request->query->get('q','');
            return $this->render('admin-users',['users'=>$this->app->userAdmin->search($query),'query'=>$query]);
        }
        if($handler==='admin-user')return $this->render('admin-user',['profile'=>$this->app->userAdmin->profile($id),'creator'=>$this->app->creators->profile($id),'plans'=>$db->all('SELECT id,name FROM plans ORDER BY name'),'sub_removed'=>$this->request->query->has('sub_removed'),'recentActivity'=>$this->app->operations->recentActivity($id,10)]);
        if($handler==='admin-user-creator'){
            $this->app->creators->activate($id,$input->all(),$uid); return new RedirectResponse('/admin/users/'.$id,303);
        }
        if($handler==='admin-user-creator-suspend'){
            $this->app->creators->suspend($id,$uid); return new RedirectResponse('/admin/users/'.$id,303);
        }
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
        if($handler==='admin-operations'){
            $period=$this->request->query->get('period','7d');
            $since=match($period){'today'=>strtotime('today UTC'),'24h'=>time()-86400,'30d'=>time()-30*86400,'7d'=>time()-7*86400,default=>0};
            $query=trim($this->request->query->get('q',''));
            $type=$this->request->query->get('type','');
            $status=$this->request->query->get('status','');
            $client=$this->request->query->get('client','');
            $invoice=$this->request->query->get('invoice','');
            $service=$this->request->query->get('service','');
            $provider=$this->request->query->get('provider','');
            $httpStatus=$this->request->query->get('http_status','');
            return $this->render('admin-operations',['operations'=>$this->app->operations->search($query,$type,$status,$since,$client,$invoice,$service,$provider,$httpStatus,$period),'q'=>$query,'type'=>$type,'status'=>$status,'period'=>$period,'client'=>$client,'invoice'=>$invoice,'service'=>$service,'provider'=>$provider,'httpStatus'=>$httpStatus]);
        }
        if($handler==='admin-events'){
            $query=trim($this->request->query->get('q',''));
            $status=$this->request->query->get('status','');
            $type=$this->request->query->get('type','');
            $client=$this->request->query->get('client','');
            $period=$this->request->query->get('period','7d');
            return $this->render('admin-events',['operations'=>$this->app->operations->search($query,$type,$status,0,$client,'','','','','period'),'q'=>$query,'status'=>$status,'type'=>$type,'client'=>$client,'period'=>$period]);
        }
        if($handler==='admin-operation'){
            $operation=$this->app->operations->detail($id); if(!$operation) throw new BillingError('Операция не найдена.');
            $supportSummary=$this->app->operations->supportSummary($id);
            return $this->render('admin-operation',['operation'=>$operation,'supportSummary'=>$supportSummary]);
        }
        if($handler==='admin-provisioning')return $this->render('admin-provisioning',['accounts'=>$db->all("SELECT p.*,s.expires_at,s.lifecycle_status,u.email,u.telegram_id FROM provisioning_accounts p JOIN subscriptions s ON s.id=p.subscription_id JOIN users u ON u.id=s.user_id ORDER BY CASE p.state WHEN 'failed' THEN 0 WHEN 'retry' THEN 1 ELSE 2 END,p.updated_at DESC LIMIT 200")]);
        if($handler==='admin-provisioning-retry'){
            $reason=trim($input->get('reason','')); if($reason===''||mb_strlen($reason)>200)throw new BillingError('Укажите причину повторной синхронизации (до 200 символов).');
            $account=$db->one('SELECT p.*,s.remote_id,s.user_id FROM provisioning_accounts p JOIN subscriptions s ON s.id=p.subscription_id WHERE p.id=?'.$db->lock(),[$id]); if(!$account)throw new BillingError('Provisioning account не найден.');
            $operation=$this->app->operations->start('provisioning.retry',['user_id'=>$account['user_id'],'subscription_id'=>$account['subscription_id'],'metadata'=>['requested_by'=>$uid,'reason'=>$reason]]);
            $topic=$account['remote_id']===null?'subscription.provision':'subscription.extend';
            $db->transaction(function()use($db,$account,$topic,$operation,$reason,$uid){
                $db->execute("UPDATE provisioning_accounts SET state='retry',last_error=NULL,updated_at=? WHERE id=?",[time(),$account['id']]);
                $key='manual-retry:'.$account['subscription_id'].':'.$operation['id'];
                $this->app->outbox->enqueue($topic,$key,['subscription_id'=>$account['subscription_id']],0);
                $db->execute('UPDATE outbox SET correlation_id=? WHERE dedup_key=?',[$operation['correlation_id'],$key]);
                $this->app->billing->audit($uid,'provisioning.retry_requested',$account['subscription_id']);
            });
            $this->app->operations->event($operation['id'],'provisioning.queued','warning','Manual retry queued',['metadata'=>['reason'=>$reason]]);
            return new RedirectResponse('/admin/operations/'.$operation['id'],303);
        }
        if($handler==='admin-explain'){$state=(new \App\Observability\StateExplanation($db))->subscription($id);if(!$state)throw new BillingError('Подписка не найдена.');return $this->render('admin-explain',['state'=>$state,'subscription_id'=>$id]);}
        if($handler==='admin-preview-extend'){$months=$this->request->query->getInt('months',1);if($months<1||$months>36)throw new BillingError('1–36 месяцев.');$s=$db->one('SELECT * FROM subscriptions WHERE id=?',[$id]);if(!$s)throw new BillingError('Подписка не найдена.');$after=(new \App\Subscriptions\SubscriptionService($db,$this->app->outbox))->expiryAfter(max(time(),(int)$s['expires_at']),0,$months);return $this->render('admin-preview-extend',['sub'=>$s,'months'=>$months,'after'=>$after]);}
        if($handler==='admin-intelligence')return $this->render('admin-intelligence',$this->app->intelligence->overview()+['safety'=>($db->one("SELECT value FROM app_settings WHERE name='GLOBAL_SAFETY_MODE'")['value']??'0')==='1','checked'=>$this->request->query->has('checked')]);
        if($handler==='admin-invariants-run'){$this->app->intelligence->invariants(true);return new RedirectResponse('/admin/intelligence?checked=1',303);}
        if($handler==='admin-safety-mode'){$this->app->intelligence->setSafety($input->get('enabled')==='1',$uid);return new RedirectResponse('/admin/intelligence',303);}
        if($handler==='admin-maintenance-window'){$starts=strtotime($input->get('starts_at','').' UTC');$ends=strtotime($input->get('ends_at','').' UTC');if($starts===false||$ends===false)throw new BillingError('Укажите время технических работ.');$this->app->intelligence->setMaintenance($input->get('service',''),$starts,$ends,$input->get('note',''),$uid);return new RedirectResponse('/admin/intelligence',303);}
        if($handler==='admin-time-travel'){$at=strtotime($this->request->query->get('at','').' UTC');if($at===false)throw new BillingError('Укажите дату и время.');return $this->render('admin-time-travel',['state'=>$this->app->intelligence->timeTravel($id,$at)]);}
        if($handler==='admin-simulator')return $this->render('admin-simulator',['result'=>$this->app->intelligence->simulate($id,$this->request->query->get('plan_id',''),$this->request->query->getInt('promo',0),$this->request->query->get('action','renew')),'user_id'=>$id]);
        if($handler==='admin-dependency-graph'){$graph=$this->app->intelligence->graph($id);if(!$graph)throw new BillingError('Подписка не найдена.');return $this->render('admin-dependency-graph',['graph'=>$graph]);}
        if($handler==='admin-investigations')return $this->render('admin-investigations',['cases'=>$this->app->investigations->list(),'queues'=>$this->app->investigations->smartQueues()]);
        if($handler==='admin-investigation-start'){$case=$this->app->investigations->start($input->get('subject_type',''),$input->get('subject_id',''),$input->get('title',''),$uid);return new RedirectResponse('/admin/investigations/'.$case['id'],303);}
        if($handler==='admin-investigation'){$case=$this->app->investigations->detail($id);if(!$case)throw new BillingError('Расследование не найдено.');return $this->render('admin-investigation',['case'=>$case]);}
        if($handler==='admin-investigation-note'){$this->app->investigations->note($id,$input->get('body',''),$uid);return new RedirectResponse('/admin/investigations/'.$id,303);}
        if($handler==='admin-investigation-resolve'){$this->app->investigations->resolve($id,$uid);return new RedirectResponse('/admin/investigations/'.$id,303);}
        if($handler==='admin-expected-actual'){$comparison=$this->app->investigations->expectedActual($id);if(!$comparison)throw new BillingError('Подписка не найдена.');return $this->render('admin-expected-actual',['comparison'=>$comparison]);}
        if($handler==='admin-why-not-renewed')return $this->render('admin-why-not-renewed',$this->app->investigations->whyNotRenewed($id));
        if($handler==='admin-flags')return $this->render('admin-flags',['flags'=>(new \App\Observability\FeatureFlags($db))->all()]);
        if($handler==='admin-flag-save'){(new \App\Observability\FeatureFlags($db))->set($input->get('name',''),$input->get('enabled')==='1',$input->getInt('rollout',100),$uid);$this->app->billing->audit($uid,'feature_flag.updated',$input->get('name',''));return new RedirectResponse('/admin/flags',303);}
        if($handler==='admin-switches')return $this->render('admin-switches',['data'=>$this->app->killSwitch->all(),'breakers'=>array_map(fn($name)=>$this->app->circuitBreaker->state($name),['remnawave_api'])]);
        if($handler==='admin-switch-save'){
            $name=$input->get('name','');
            if($name==='__safe_mode__'){$this->app->killSwitch->setSafeMode($input->get('enabled')==='1',$uid,$input->get('reason',''));}
            else{$this->app->killSwitch->set($name,$input->get('enabled')==='1',$uid,$input->get('reason',''));}
            $this->app->billing->audit($uid,'kill_switch.updated',$name);return new RedirectResponse('/admin/switches',303);
        }
        if($handler==='admin-breaker-reset'){$this->app->circuitBreaker->reset($input->get('name',''));$this->app->billing->audit($uid,'circuit_breaker.reset',$input->get('name',''));return new RedirectResponse('/admin/switches',303);}
        if($handler==='admin-approvals')return $this->render('admin-approvals',['data'=>['pending'=>$this->app->fourEyes->pending(),'history'=>$this->app->fourEyes->history()]]);
        if($handler==='admin-approval-decision'){
            $decision=$input->get('decision','');$actor=$this->user['email']??'admin';
            if($decision==='approve')$this->app->fourEyes->approve($id,$actor);
            elseif($decision==='reject')$this->app->fourEyes->reject($id,$actor);
            else throw new \App\Billing\BillingError('Недопустимое решение.');
            $this->app->billing->audit($uid,'approval.' . $decision,$id);return new RedirectResponse('/admin/approvals',303);
        }
        if($handler==='admin-incidents')return $this->render('admin-incidents',['incidents'=>(new \App\Observability\IncidentService($db))->list()]);
        if($handler==='admin-incident-create'){(new \App\Observability\IncidentService($db))->create($input->get('title',''),$uid);return new RedirectResponse('/admin/incidents',303);}
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
