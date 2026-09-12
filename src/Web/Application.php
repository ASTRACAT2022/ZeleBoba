<?php
declare(strict_types=1);
namespace App\Web;
use App\Container;
use App\Billing\BillingError;
use App\Infrastructure\Database;
use App\Infrastructure\Telemetry;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse,RedirectResponse,Cookie};
use Symfony\Component\Routing\{Route,RouteCollection,RequestContext};
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Exception\{ResourceNotFoundException,MethodNotAllowedException};
use Twig\{Environment,Loader\FilesystemLoader};
final class Application
{
    use LoginActions, AdminActions;
    private Environment $twig;
    private ?array $user=null;
    private Request $request;
    private string $styleNonce;
    public function __construct(private Container $app)
    {
        $this->twig=new Environment(new FilesystemLoader(dirname(__DIR__,2).'/templates'),['strict_variables'=>true,'autoescape'=>'html']);
        $this->twig->addFilter(new \Twig\TwigFilter('rub',fn($n)=>number_format((int)$n/100,(int)$n%100===0?0:2,',',' ').' ₽'));
    }
    public function handle(Request $r): Response
    {
        $this->styleNonce=bin2hex(random_bytes(16));
        $span=Telemetry::start('billing.http.request',['http.request.method'=>$r->getMethod()]);
        try {
            $response=$this->handleRequest($r);
            $response->headers->set('Content-Security-Policy',"default-src 'self'; style-src 'self' 'nonce-".$this->styleNonce."'; script-src 'self'; img-src 'self' data: https:; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('Referrer-Policy','no-referrer');
            $response->headers->set('Cache-Control','no-store');
            if ($this->app->config['APP_ENV']==='prod') $response->headers->set('Strict-Transport-Security','max-age=31536000');
            Telemetry::complete($span,['http.response.status_code'=>$response->getStatusCode()]);
            return $response;
        } catch (\Throwable $e) {
            Telemetry::fail($span,$e);
            throw $e;
        }
    }
    private function handleRequest(Request $r): Response
    {
        $this->request=$r; $this->user=null; $requestId=Database::id();
        try {
            $routes=new RouteCollection();
            foreach ([['tg-start','/telegram/start',['POST']],['tg-status','/telegram/status',['GET']],['tg-finish','/telegram/finish',['POST']],['tg-magic','/telegram/magic',['GET','POST']],['security','/security',['GET']],['mfa-begin','/security/begin',['POST']],['mfa-enroll','/security/enroll',['POST']],['mfa-verify','/security/verify',['POST']],['admin-config','/admin/config',['GET']],['admin-config-save','/admin/config',['POST']],['admin-check','/admin/check/{id}',['POST']],['admin-readiness','/admin/readiness',['GET']],['admin-plans','/admin/plans',['GET']],['admin-plan-save','/admin/plans/{id}',['POST']],['admin-users','/admin/users',['GET']],['admin-user-search','/admin/users/search',['GET']],['admin-user-toggle','/admin/users/{id}/toggle',['POST']],['admin-user','/admin/users/{id}',['GET']],['admin-user-balance','/admin/users/{id}/balance',['POST']],['admin-user-days','/admin/users/{id}/days',['POST']],['admin-user-discount','/admin/users/{id}/discount',['POST']],['admin-user-discount-clear','/admin/users/{id}/discount/clear',['POST']],['admin-user-subscription-remove','/admin/users/{id}/subscriptions/{sid}/remove',['POST']],['admin-subscription','/admin/subscriptions/{sid}',['GET']],['admin-subscription-traffic','/admin/users/{id}/subscriptions/{sid}/traffic',['POST']],['admin-subscription-devices','/admin/users/{id}/subscriptions/{sid}/devices',['POST']],['admin-subscription-extend','/admin/users/{id}/subscriptions/{sid}/extend',['POST']],['admin-subscription-expiry','/admin/users/{id}/subscriptions/{sid}/expiry',['POST']],['admin-subscription-reset-traffic','/admin/users/{id}/subscriptions/{sid}/reset-traffic',['POST']],['admin-sync','/admin/sync',['POST']],['admin-promocodes','/admin/promocodes',['GET']],['admin-promocode-create','/admin/promocodes',['POST']],['admin-promocode-toggle','/admin/promocodes/{id}/toggle',['POST']],['admin-withdrawals','/admin/withdrawals',['GET']],['admin-withdrawal-process','/admin/withdrawals/{id}',['POST']],['admin-broadcasts','/admin/broadcasts',['GET']],['admin-broadcast-create','/admin/broadcasts',['POST']],['admin-compensations','/admin/compensations',['GET']],['admin-compensation-create','/admin/compensations',['POST']],['admin-channels','/admin/channels',['GET']],['admin-channel-add','/admin/channels',['POST']],['admin-channel-toggle','/admin/channels/{id}/toggle',['POST']],['admin-channel-remove','/admin/channels/{id}/remove',['POST']],['admin-landings','/admin/landings',['GET']],['admin-landing-save','/admin/landings',['POST']],['admin-landing-toggle','/admin/landings/{id}/toggle',['POST']],['admin-contests','/admin/contests',['GET']],['admin-contest-create','/admin/contests',['POST']],['admin-contest-round','/admin/contests/{id}/round',['POST']],['admin-polls','/admin/polls',['GET']],['admin-poll-create','/admin/polls',['POST']],['admin-campaigns','/admin/campaigns',['GET']],['admin-campaign-create','/admin/campaigns',['POST']],['admin-reports','/admin/reports',['GET']],['admin-monitoring','/admin/monitoring',['GET']],['admin-monitoring-clear','/admin/monitoring/clear',['POST']],['admin-backups','/admin/backups',['GET']],['admin-backup-create','/admin/backups',['POST']],['admin-backup-restore','/admin/backups/{id}/restore',['POST']],['admin-roles','/admin/roles',['GET']],['admin-role-create','/admin/roles',['POST']],['admin-role-assign','/admin/roles/assign',['POST']],['admin-role-revoke','/admin/roles/revoke',['POST']],['admin-audit','/admin/audit',['GET']],['admin-maintenance','/admin/maintenance',['POST']],['landing','/l/{id}',['GET']],['health','/health/live',['GET']],['ready','/health/ready',['GET']],['login','/login',['GET','POST']],['register','/register',['GET','POST']],['forgot','/forgot',['GET','POST']],['reset','/reset/{token}',['GET','POST']],['logout','/logout',['POST']],['home','/',['GET']],['plans','/plans',['GET']],['orders','/orders',['GET']],['buy','/orders',['POST']],['order','/orders/{id}',['GET']],['demo','/orders/{id}/demo-pay',['POST']],['autorenew','/subscriptions/{id}/autorenew',['POST']],['trial','/trial',['POST']],['trial-convert','/trial/{id}/convert',['POST']],['settings','/settings',['GET']],['link','/settings/telegram',['POST']],['promo','/promo',['POST']],['referral','/referral',['GET']],['withdraw','/referral/withdraw',['POST']],['gifts','/gifts',['GET']],['gift-buy','/gifts/buy',['POST']],['gift-claim','/gifts/claim',['POST']],['gift-claim-page','/gifts/claim',['GET']],['gift-buy-page','/buy/gift/{id}',['GET']],['balance','/balance',['GET']],['topup','/balance/topup',['POST']],['topup-order','/balance/topup/{id}',['GET']],['topup-demo','/balance/topup/{id}/demo-pay',['POST']],['buy-balance','/orders/balance',['POST']],['admin','/admin',['GET']],['retry','/admin/jobs/{id}/retry',['POST']],['plan','/admin/plans',['POST']],['yookassa','/webhooks/yookassa',['POST']],['freekassa','/webhooks/freekassa',['GET','POST']],['cryptobot','/webhooks/cryptobot',['POST']],['lava','/webhooks/lava',['POST']],['wata','/webhooks/wata',['POST']],['heleket','/webhooks/heleket',['POST']],['platega','/webhooks/platega',['POST']],['tribute','/webhooks/tribute',['POST']],['mulenpay','/webhooks/mulenpay',['POST']],['pal24','/webhooks/pal24',['POST']],['cloudpayments','/webhooks/cloudpayments',['POST']],['kassa_ai','/webhooks/kassa_ai',['POST']],['riopay','/webhooks/riopay',['POST']],['severpay','/webhooks/severpay',['POST']],['paypear','/webhooks/paypear',['POST']],['rollypay','/webhooks/rollypay',['POST']],['overpay','/webhooks/overpay',['POST']],['aurapay','/webhooks/aurapay',['POST']],['etoplatezhi','/webhooks/etoplatezhi',['POST']],['antilopay','/webhooks/antilopay',['POST']],['jupiter','/webhooks/jupiter',['POST']],['donut','/webhooks/donut',['POST']],['cispay','/webhooks/cispay',['POST']],['tabpay','/webhooks/tabpay',['POST']],['paritypay','/webhooks/paritypay',['POST']],['telegram','/webhooks/telegram',['POST']]] as [$name,$path,$methods]) $routes->add($name,new Route($path,['_handler'=>$name],[],[], '',[],$methods));
            $match=(new UrlMatcher($routes,(new RequestContext())->fromRequest($r)))->match($r->getPathInfo());
            $handler=$match['_handler'];
            if ($handler==='health') return new JsonResponse(['status'=>'ok']);
            if ($handler==='ready') { $this->app->db->one('SELECT version FROM migrations LIMIT 1'); return new JsonResponse(['status'=>'ready']); }
            if (strlen($r->getContent())>65536) return new JsonResponse(['error'=>'Request too large'],413);
            if (in_array($handler,['yookassa','freekassa','telegram','cryptobot','lava','wata','heleket','platega','tribute','mulenpay','pal24','cloudpayments','kassa_ai','riopay','severpay','paypear','rollypay','overpay','aurapay','etoplatezhi','antilopay','jupiter','donut','cispay','tabpay','paritypay'],true)) return $this->webhook($handler);
            $this->user=$this->app->auth->session($r->cookies->get('zb_session',''));
            if(str_starts_with($handler,'tg-')) return $this->telegramAuth($handler);
            if (in_array($handler,['login','register','forgot','reset'],true)) return $this->authentication($handler,$match['token']??'');
            if ($handler==='landing') return $this->landing($match['id']);
            if (!$this->user) return new RedirectResponse('/login');
            if ($r->isMethod('POST')) {
                if (!hash_equals($this->user['csrf'],$r->request->get('_csrf',''))) return $this->render('error',['message'=>'Сессия формы устарела. Обновите страницу.'],403);
                $this->app->auth->throttle('mutate:'.$this->user['id'],60,60);
            }
            if (in_array($handler,['security','mfa-begin','mfa-enroll','mfa-verify'],true))return $this->security($handler);
            if(str_starts_with($handler,'admin') || in_array($handler,['retry','plan'],true)){
                if(!$this->app->rbac->can($this->user['id'],self::adminPermission($handler)))return $this->render('error',['message'=>'Недостаточно прав.'],403);
                // Every admin endpoint requires an enrolled and recently
                // verified second factor. Previously an admin without MFA
                // could access the whole admin area without being redirected
                // to the enrollment flow.
                if(!(int)$this->user['mfa_enabled'] || (int)$this->user['admin_verified_until']<time())return new RedirectResponse('/security',303);
            }
            if(str_starts_with($handler,'admin-'))return $this->administration($handler,$match['id']??'',$match['sid']??'');
            return $this->dispatch($handler,$match['id']??'');
        } catch (ResourceNotFoundException) { return $this->render('error',['message'=>'Страница не найдена.'],404); }
        catch (MethodNotAllowedException) { return new Response('Method not allowed',405); }
        catch (BillingError $e) { return $this->render('error',['message'=>$e->getMessage()],422); }
        catch (\JsonException|\Symfony\Component\HttpFoundation\Exception\BadRequestException $e) { return new JsonResponse(['error'=>'Malformed request'],400); }
        catch (\Throwable $e) {
            error_log(json_encode(['event'=>'request.failed','request_id'=>$requestId,'type'=>get_class($e)]));
            return $this->render('error',['message'=>'Не удалось выполнить запрос. Повторите позже. Код: '.$requestId],503);
        }
    }
    private static function adminPermission(string $handler): string
    {
        return match (true) {
            $handler==='admin' => 'admin.view',
            $handler==='plan', str_starts_with($handler,'admin-plan') => 'admin.plans',
            str_starts_with($handler,'admin-user'), str_starts_with($handler,'admin-subscription'), str_starts_with($handler,'admin-compensation') => 'admin.users',
            str_starts_with($handler,'admin-config'), $handler==='admin-check', $handler==='admin-readiness' => 'admin.settings',
            $handler==='admin-sync' => 'admin.sync',
            str_starts_with($handler,'admin-promocode') => 'admin.promocodes',
            str_starts_with($handler,'admin-withdrawal') => 'admin.withdrawals',
            str_starts_with($handler,'admin-broadcast') => 'admin.broadcasts',
            str_starts_with($handler,'admin-channel') => 'admin.channels',
            str_starts_with($handler,'admin-landing') => 'admin.landings',
            str_starts_with($handler,'admin-contest') => 'admin.contests',
            str_starts_with($handler,'admin-poll') => 'admin.polls',
            str_starts_with($handler,'admin-campaign') => 'admin.campaigns',
            $handler==='admin-reports' => 'admin.reports',
            $handler==='retry', str_starts_with($handler,'admin-monitoring') => 'admin.monitoring',
            str_starts_with($handler,'admin-backup') => 'admin.backup',
            str_starts_with($handler,'admin-role') => 'admin.roles',
            $handler==='admin-audit' => 'admin.audit',
            $handler==='admin-maintenance' => 'admin.maintenance',
            default => throw new \LogicException('Missing admin permission'),
        };
    }
    private function dispatch(string $handler,string $id): Response
    {
        $db=$this->app->db; $uid=$this->user['id']; $input=$this->request->request;
        switch ($handler) {
            case 'logout': $this->app->auth->logout($this->request->cookies->get('zb_session','')); $response=new RedirectResponse('/login'); $response->headers->clearCookie('zb_session'); return $response;
            case 'home':
                return $this->render('home',['subscriptions'=>$db->all('SELECT s.*,COALESCE(o.plan_name,p.name) AS plan_name,COALESCE(o.devices,s.device_limit) AS devices,COALESCE(o.traffic_bytes,0) AS traffic_bytes FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 50',[$uid]),'orders'=>$db->all('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 5',[$uid]),'autorenew_enabled'=>$this->app->config['AUTORENEW_ENABLED']==='1']);
            case 'plans':
                $plans=$db->all('SELECT * FROM plans WHERE active=1 ORDER BY price_minor');
                foreach ($plans as &$plan) $plan['price_minor']=$this->app->billing->priceFor($uid,$plan);
                unset($plan);
                return $this->render('plans',['plans'=>$plans,'key'=>Database::id(),'trial_available'=>$this->app->trials->available($uid)]);
            case 'buy': $order=$this->app->billing->order($uid,$input->get('plan_id',''),$input->get('idempotency_key',''),$input->get('receipt_email'),self::clientIp($this->request),null,$input->get('landing_slug')); return new RedirectResponse('/orders/'.$order['id'],303);
            case 'orders': return $this->render('orders',['orders'=>$db->all('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 100',[$uid])]);
            case 'order':
            case 'demo':
                $order=$handler==='order' && $this->user['role']==='admin' && (int)$this->user['admin_verified_until']>time() ? $db->one('SELECT * FROM orders WHERE id=?',[$id]) : $db->one('SELECT * FROM orders WHERE id=? AND user_id=?',[$id,$uid]);
                if (!$order) return $this->render('error',['message'=>'Заказ не найден.'],404);
                if ($handler==='demo') {
                    if ($this->app->config['APP_ENV']==='prod' || $order['provider']!=='demo') return new Response('Not found',404);
                    $this->app->billing->settle($id,'demo','demo_'.$id,(int)$order['price_minor'],$order['currency']);
                    return new RedirectResponse('/orders/'.$id,303);
                }
                return $this->render('order',['order'=>$order,'subscription'=>$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$id])]);
            case 'autorenew':
                $enable=$input->get('enable','')==='1';
                $this->app->billing->setAutoRenew($uid,$id,$enable);
                return new RedirectResponse('/',303);
            case 'trial':
                $this->app->trials->start($uid,$input->get('plan_id',''));
                return new RedirectResponse('/',303);
            case 'trial-convert':
                $planId=$input->get('plan_id','');
                $plan=$db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
                if (!$plan) throw new BillingError('Тариф недоступен.');
                $this->app->trials->convertToPaid($uid,$id,$planId,(int)$plan['price_minor']);
                return new RedirectResponse('/',303);
            case 'promo':
                $result=$this->app->promocodes->activate($uid,$input->get('code',''));
                if(!$result['success']) throw new BillingError($this->promoError($result['error']));
                return $this->render('promo',['result'=>$result]);
            case 'balance':
                $balance=$this->app->wallet->balance($uid);
                $history=$this->app->wallet->history($uid,50);
                $topups=$db->all('SELECT * FROM topups WHERE user_id=? ORDER BY created_at DESC LIMIT 10',[$uid]);
                $providers=[];
                foreach ($this->app->providers->enabled() as $id=>$provider) $providers[$id]=$provider->name();
                if ($this->app->config['PAYMENT_DRIVER']==='demo') $providers=['demo'=>'Демо'];
                return $this->render('balance',['balance'=>$balance['balance_kopeks'],'history'=>$history,'topups'=>$topups,'key'=>Database::id(),'providers'=>$providers]);
            case 'topup':
                $amount=filter_var($input->get('amount'),FILTER_VALIDATE_INT);
                if ($amount===false || $amount < -1000000 || $amount > 1000000) throw new BillingError('Некорректная сумма.');
                $key=$input->get('idempotency_key','');
                $provider=$input->get('provider','');
                $topup=$this->app->topups->create($uid,($amount??0)*100,$key,$provider);
                return new RedirectResponse('/balance/topup/'.$topup['id'],303);
            case 'topup-order':
            case 'topup-demo':
                $topup=$db->one('SELECT * FROM topups WHERE id=? AND user_id=?',[$id,$uid]);
                if (!$topup) return $this->render('error',['message'=>'Пополнение не найдено.'],404);
                if ($handler==='topup-demo') {
                    if ($this->app->config['APP_ENV']==='prod' || $topup['provider']!=='demo') return new Response('Not found',404);
                    $this->app->topups->settle($id,'demo','demo_'.$id,(int)$topup['amount_kopeks'],$topup['currency']);
                    return new RedirectResponse('/balance',303);
                }
                return $this->render('topup',['topup'=>$topup]);
            case 'buy-balance':
                $this->app->billing->purchaseFromBalance($uid,$input->get('plan_id',''),$input->get('idempotency_key',''));
                return new RedirectResponse('/',303);
            case 'settings': return $this->render('settings',['link_token'=>null]);
            case 'link':
                $token=bin2hex(random_bytes(24));
                $db->transaction(function () use ($db,$uid,$token) {
                    $db->execute('DELETE FROM telegram_links WHERE user_id=?',[$uid]);
                    $db->execute('INSERT INTO telegram_links VALUES(?,?,?)',[hash('sha256',$token),$uid,time()+600]);
                });
                return $this->render('settings',['link_token'=>$token]);
            case 'promo':
                $result=$this->app->promocodes->activate($uid,$input->get('code',''));
                if(!$result['success']) throw new BillingError($this->promoError($result['error']));
                return $this->render('promo',['result'=>$result]);
            case 'referral':
                $stats=$this->app->referrals->stats($uid);
                $withdrawals=$this->app->referrals->withdrawals($uid);
                $withdrawalEnabled=($this->app->config['REFERRAL_WITHDRAWAL_ENABLED']??'0')==='1';
                $minWithdrawal=(int)($this->app->config['REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS']??100000);
                return $this->render('referral',['stats'=>$stats,'withdrawals'=>$withdrawals,'withdrawal_enabled'=>$withdrawalEnabled,'min_withdrawal'=>$minWithdrawal,'telegram_username'=>$this->app->config['TELEGRAM_BOT_USERNAME']]);
            case 'withdraw':
                $amount=filter_var($input->get('amount'),FILTER_VALIDATE_INT);
                if ($amount===false || $amount < -1000000 || $amount > 1000000) throw new BillingError('Некорректная сумма.');
                $details=$input->get('payment_details','');
                $this->app->referrals->requestWithdrawal($uid,($amount??0)*100,$details);
                return new RedirectResponse('/referral',303);
            case 'gifts':
                $bought=$this->app->gifts->boughtBy($uid);
                $received=$this->app->gifts->receivedBy($uid);
                $plans=$db->all('SELECT * FROM plans WHERE active=1 ORDER BY price_minor');
                return $this->render('gifts',['bought'=>$bought,'received'=>$received,'plans'=>$plans,'gift_enabled'=>$this->app->gifts->enabled(),'key'=>Database::id()]);
            case 'gift-buy':
                $planId=$input->get('plan_id','');
                $key=$input->get('idempotency_key','');
                $recipientType=$input->get('recipient_type','');
                $recipientValue=$input->get('recipient_value','');
                $message=$input->get('gift_message','');
                $purchase=$this->app->gifts->purchaseFromBalance($uid,$planId,$key,$recipientType?:null,$recipientValue?:null,$message?:null,'cabinet');
                return new RedirectResponse('/gifts',303);
            case 'gift-claim':
                $purchase=$this->app->gifts->claim($uid,$input->get('code',''));
                return $this->render('gift-claimed',['purchase'=>$purchase]);
            case 'gift-claim-page':
                return $this->render('gift-claim',['key'=>Database::id()]);
            case 'gift-buy-page':
                if (!preg_match('/^[a-zA-Z0-9_-]{64}$/D',$id)) return $this->render('error',['message'=>'Подарок не найден.'],404);
                return $this->render('gift-claim',['code'=>$id]);
            case 'admin': return $this->render('admin',['jobs'=>$db->all("SELECT id,topic,status,attempts,last_error FROM outbox WHERE status!='done' ORDER BY created_at LIMIT 100"),'recent'=>$db->all('SELECT o.*,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 30'),'plans'=>$db->all('SELECT * FROM plans ORDER BY price_minor'),'audit'=>$db->all('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 20'),'maintenance'=>$this->app->maintenance->isMaintenance()]);
            case 'retry':
                $db->transaction(function () use ($db,$id,$uid) {
                    if ($db->execute("UPDATE outbox SET status='pending',attempts=0,available_at=?,locked_until=NULL,lock_token=NULL WHERE id=? AND status='dead'",[time(),$id])) $this->app->billing->audit($uid,'job.retried',$id);
                });
                return new RedirectResponse('/admin',303);
            case 'plan':
                $name=trim($input->get('name','')); $price=filter_var($input->get('price_minor'),FILTER_VALIDATE_INT); $days=filter_var($input->get('duration_days'),FILTER_VALIDATE_INT); $devices=filter_var($input->get('devices'),FILTER_VALIDATE_INT); $traffic=filter_var($input->get('traffic_gb'),FILTER_VALIDATE_INT);
                if (!$name || mb_strlen($name)>100 || $price<100 || $price>100000000 || $days<1 || $days>3650 || $devices<1 || $devices>20 || $traffic===false || $traffic<0 || $traffic>100000) throw new BillingError('Проверьте параметры тарифа.');
                $db->transaction(function () use ($db,$uid,$name,$price,$days,$devices,$traffic) {
                    $id=Database::id(); $db->execute('INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES(?,?,?,?,?,?,?,1)',[$id,$name,$price,'RUB',$days,$traffic*1073741824,$devices]);
                    $this->app->billing->audit($uid,'plan.created',$id);
                });
                return new RedirectResponse('/admin',303);
        }
        throw new \LogicException('Unhandled route');
    }
    private function landing(string $id): Response
    {
                $landing=$this->app->landings->get($id);
                if (!$landing) return $this->render('error',['message'=>'Лендинг не найден.'],404);
                $plans=$this->app->db->all('SELECT * FROM plans WHERE active=1 ORDER BY price_minor');
                $prices=[];
                foreach ($plans as $p) $prices[$p['id']]=min($this->app->landings->effectivePrice($landing,$p),$this->user?$this->app->billing->priceFor($this->user['id'],$p):(int)$p['price_minor']);
                return $this->render('landing',['landing'=>$landing,'plans'=>$plans,'prices'=>$prices,'key'=>Database::id()]);
    }
    private function authentication(string $mode,string $token=''): Response
    {
        if ($this->user) return new RedirectResponse('/');
        if($mode==='register' && $this->app->config['REGISTRATION_ENABLED']!=='1')throw new BillingError('Регистрация временно закрыта.');
        $csrf=$this->request->cookies->get('zb_guest','');
        if (!preg_match('/^[a-f0-9]{64}$/D',$csrf)) $csrf=bin2hex(random_bytes(32));
        if ($this->request->isMethod('POST')) {
            if (!hash_equals($csrf,$this->request->request->get('_csrf',''))) return $this->render('error',['message'=>'Обновите страницу входа.'],403);
            if ($mode==='forgot') {
                $this->app->auth->throttle('auth-ip:'.$this->request->getClientIp(),20);
                $email=$this->request->request->get('email','');
                $token=$this->app->auth->createPasswordReset($email);
                if ($token!==null && $this->app->mailer->enabled()) {
                    $url=$this->app->config['APP_URL'].'/reset/'.$token;
                    $this->app->mailer->queue($email,'ASTRACAT — восстановление пароля',$this->resetEmailHtml($email,$url));
                }
                return $this->render('auth',['mode'=>'forgot-done','guest_csrf'=>$csrf]);
            }
            if ($mode==='reset') {
                $this->app->auth->throttle('reset-ip:'.$this->request->getClientIp(),20);
                $password=$this->request->request->get('password','');
                if (!preg_match('/^[a-f0-9]{64}$/D',$token) || !$this->app->auth->applyPasswordReset($token,$password)) {
                    return $this->render('error',['message'=>'Ссылка недействительна или истекла. Запросите восстановление заново.'],400);
                }
                return $this->render('auth',['mode'=>'reset-done','guest_csrf'=>$csrf]);
            }
            $email=$this->request->request->get('email',''); $password=$this->request->request->get('password','');
            $this->app->auth->throttle('auth-ip:'.$this->request->getClientIp(),20);
            $this->app->auth->throttle('auth-email:'.mb_strtolower(trim($email)),10);
            $uid=$mode==='register'?$this->app->auth->register($email,$password):$this->app->auth->login($email,$password);
            if ($mode==='register' && $this->app->mailer->enabled()) {
                $this->app->mailer->queue($email,'ASTRACAT — добро пожаловать!',$this->welcomeEmailHtml($email));
            }
            $user=$this->app->db->one('SELECT totp_secret FROM users WHERE id=?',[$uid]);
            if($user['totp_secret'])$this->app->mfa->verify($uid,$this->request->request->get('code',''));
            $session=$this->app->auth->issue($uid);if($user['totp_secret'])$this->app->mfa->stepUp($session);
            return $this->signedIn($session);
        }
        $response=$this->render('auth',['mode'=>$mode,'guest_csrf'=>$csrf]);
        $response->headers->setCookie($this->cookie('zb_guest',$csrf,time()+3600));
        return $response;
    }
    private function welcomeEmailHtml(string $email): string
    {
        $url=$this->app->config['APP_URL'].'/login';
        return '<div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:auto;padding:24px"><h2 style="color:#0f1c2e;margin:0 0 16px">Добро пожаловать в ASTRACAT!</h2><p>Вы успешно зарегистрировались.</p><p><b>Email:</b> '.htmlspecialchars($email,ENT_QUOTES).'</p><p><a href="'.htmlspecialchars($url,ENT_QUOTES).'" style="display:inline-block;background:#135d45;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none">Войти в кабинет</a></p><hr style="border:none;border-top:1px solid #e6ebf0;margin:24px 0"><p style="color:#9aa5b4;font-size:12px">Команда ASTRACAT</p></div>';
    }
    private function resetEmailHtml(string $email, string $url): string
    {
        return '<div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:auto;padding:24px"><h2 style="color:#0f1c2e;margin:0 0 16px">Восстановление пароля</h2><p>Вы запросили восстановление пароля для аккаунта <b>'.htmlspecialchars($email,ENT_QUOTES).'</b>.</p><p>Перейдите по ссылке, чтобы задать новый пароль (действительна 24 часа):</p><p><a href="'.htmlspecialchars($url,ENT_QUOTES).'" style="display:inline-block;background:#135d45;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none">Сбросить пароль</a></p><p style="color:#9aa5b4;font-size:12px">Если вы не запрашивали восстановление — просто проигнорируйте это письмо.</p><hr style="border:none;border-top:1px solid #e6ebf0;margin:24px 0"><p style="color:#9aa5b4;font-size:12px">Команда ASTRACAT</p></div>';
    }
    private function webhook(string $handler): Response
    {
        if ($handler==='telegram') {
            $secret=$this->app->config['TELEGRAM_WEBHOOK_SECRET'];
            if (strlen($secret)<32 || !hash_equals($secret,$this->request->headers->get('X-Telegram-Bot-Api-Secret-Token',''))) return new JsonResponse(['error'=>'Forbidden'],403);
            $this->app->telegram->receive($this->request->toArray());
            return new JsonResponse(['ok'=>true]);
        }
        if (in_array($handler,['cryptobot','lava','wata','heleket','platega','tribute','mulenpay','pal24','cloudpayments','kassa_ai','riopay','severpay','paypear','rollypay','overpay','aurapay','etoplatezhi','antilopay','jupiter','donut','cispay','tabpay','paritypay'],true)) {
            $this->app->auth->throttle('webhook:'.$this->request->getClientIp(),240,60);
            $handled=$this->app->paymentService->handleWebhook($handler,$this->request);
            return new JsonResponse(['ok'=>$handled]);
        }
        if ($handler==='freekassa') {
            return $this->freekassaWebhook();
        }
        if ($this->app->config['PAYMENT_DRIVER']!=='yookassa') return new JsonResponse(['error'=>'Disabled'],404);
        $this->app->auth->throttle('webhook:'.$this->request->getClientIp(),120,60);
        $data=$this->request->toArray();
        if (!in_array($data['event']??'',['payment.succeeded','payment.canceled'],true)) return new JsonResponse(['ok'=>true]);
        // Webhook content only identifies a payment. Authoritative status is fetched with merchant credentials.
        $paymentId=$data['object']['id']??'';
        if(!is_string($paymentId)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$paymentId))return new JsonResponse(['error'=>'Invalid payment id'],400);
        $hint=$data['object']['metadata']['order_id']??$data['object']['metadata']['topup_id']??'';
        if(!is_string($hint))return new JsonResponse(['error'=>'Invalid order id'],400);
        $known=$this->app->db->one("SELECT id FROM orders WHERE provider='yookassa' AND (provider_payment_id=? OR (id=? AND provider_payment_id IS NULL AND status='pending'))",[$paymentId,$hint]);
        if(!$known){
            $knownTopup=$this->app->db->one("SELECT id FROM topups WHERE provider='yookassa' AND (provider_payment_id=? OR (id=? AND provider_payment_id IS NULL AND status='pending'))",[$paymentId,$hint]);
            if($knownTopup) $this->app->db->transaction(function()use($paymentId,$data){$this->app->outbox->enqueue('payment.verify','verify:'.$paymentId.':'.$data['event'],['payment_id'=>$paymentId,'provider'=>'yookassa']);});
            return new JsonResponse(['ok'=>true]);
        }
        $this->app->db->transaction(function()use($paymentId,$data){$this->app->outbox->enqueue('payment.verify','verify:'.$paymentId.':'.$data['event'],['payment_id'=>$paymentId,'provider'=>'yookassa']);});
        return new JsonResponse(['ok'=>true]);
    }
    private function freekassaWebhook(): Response
    {
        if ($this->app->config['PAYMENT_DRIVER']!=='freekassa') return new JsonResponse(['error'=>'Disabled'],404);
        $this->app->auth->throttle('webhook-fk:'.$this->request->getClientIp(),240,60);
        // FreeKassa sends form-data (POST) or query (GET) depending on merchant settings
        $data=array_merge($this->request->query->all(),$this->request->request->all());
        $merchantId=(string)($data['MERCHANT_ID']??'');
        $amount=(string)($data['AMOUNT']??'');
        $intid=(string)($data['intid']??'');
        $orderId=(string)($data['MERCHANT_ORDER_ID']??'');
        $sign=(string)($data['SIGN']??'');
        if ($merchantId===''||$amount===''||$orderId===''||$sign==='') return new Response('wrong sign',400);
        if ((string)($this->app->config['FREEKASSA_SHOP_ID']??'')!==$merchantId) return new Response('wrong merchant',403);
        // Nginx resolves the trusted proxy address into REMOTE_ADDR.
        $allowed=['168.119.157.136','168.119.60.227','178.154.197.79','51.250.54.238'];
        if (!in_array($this->request->getClientIp(),$allowed,true)) return new Response('hacking attempt!',403);
        if (!\App\Integration\Payments::verifyFreekassaNotification(['MERCHANT_ID'=>$merchantId,'AMOUNT'=>$amount,'MERCHANT_ORDER_ID'=>$orderId,'SIGN'=>$sign],(string)($this->app->config['FREEKASSA_SECRET2']??''))) return new Response('wrong sign',403);
        if (!preg_match('/^[a-f0-9]{32}$/D',$orderId)) return new Response('YES');
        $order=$this->app->db->one("SELECT * FROM orders WHERE id=? AND provider='freekassa'",[$orderId]);
        if (!$order) {
            $topup=$this->app->db->one("SELECT * FROM topups WHERE id=? AND provider='freekassa'",[$orderId]);
            if (!$topup) return new Response('YES');
            try {
                $amountMinor=\App\Integration\Payments::minor(\App\Integration\Payments::normalizeAmount($amount));
            } catch (\Throwable) { return new Response('wrong amount',400); }
            if ((int)$topup['amount_kopeks']!==$amountMinor) return new Response('wrong amount',400);
            if ($topup['status']!=='pending') return new Response('YES');
            $verifyId=$intid!==''?$intid:$orderId;
            $this->app->db->transaction(function()use($verifyId,$intid,$orderId){$this->app->outbox->enqueue('payment.verify','verify-fk:'.$orderId.':'.$intid,['payment_id'=>$orderId,'provider'=>'freekassa']);});
            return new Response('YES');
        }
        try {
            $amountMinor=\App\Integration\Payments::minor(\App\Integration\Payments::normalizeAmount($amount));
        } catch (\Throwable) { return new Response('wrong amount',400); }
        if ((int)$order['price_minor']!==$amountMinor) return new Response('wrong amount',400);
        if ($order['status']!=='pending') return new Response('YES');
        // Store intid for reconcile, then verify authoritatively via API
        if ($intid!=='' && ($order['freekassa_intid']??null)===null) {
            try { $this->app->db->execute('UPDATE orders SET freekassa_intid=? WHERE id=?',[$intid,$orderId]); } catch (\Throwable) {}
        }
        $verifyId=$intid!==''?$intid:$orderId;
        $this->app->db->transaction(function()use($verifyId,$intid,$orderId){$this->app->outbox->enqueue('payment.verify','verify-fk:'.$orderId.':'.$intid,['payment_id'=>$orderId,'provider'=>'freekassa']);});
        return new Response('YES');
    }
    private function cookie(string $name,string $value,int $expires): Cookie
    {
        return Cookie::create($name)->withValue($value)->withExpires($expires)->withHttpOnly(true)->withSecure($this->app->config['APP_ENV']==='prod'||$this->request->isSecure())->withSameSite('lax')->withPath('/');
    }
    private static function clientIp(Request $r): string
    {
        return $r->getClientIp() ?? '';
    }

    private function promoError(string $key): string
    {
        return match ($key) {
            'not_found' => 'Промокод не найден.',
            'inactive' => 'Промокод неактивен.',
            'used' => 'Промокод уже использован.',
            'not_yet_valid' => 'Промокод ещё не действует.',
            'expired' => 'Срок действия промокода истёк.',
            'already_used_by_user' => 'Вы уже использовали этот промокод.',
            'daily_limit' => 'Слишком много активаций за сутки.',
            'not_first_purchase' => 'Промокод действует только для первой покупки.',
            'active_discount_exists' => 'У вас уже есть активная скидка.',
            'no_subscription_for_days' => 'Нет подписки для начисления дней.',
            'trial_subscription_exists' => 'Триал недоступен: у вас уже есть подписка.',
            'traffic_not_applicable' => 'Трафик не начислен: у подписки безлимит.',
            default => 'Не удалось активировать промокод.',
        };
    }
    private function render(string $view,array $data=[],int $status=200): Response
    {
        $freekassaEmail=$this->app->config['PAYMENT_DRIVER']==='freekassa';
        $balance=$this->user?$this->app->wallet->balance($this->user['id'])['balance_kopeks']:0;
        $branding=$this->app->branding;
        return new Response($this->twig->render($view.'.html.twig',array_merge(['style_nonce'=>$this->styleNonce,'is_staff'=>$this->user && $this->app->rbac->permissions($this->user['id'])!==[],'user'=>$this->user,'path'=>$this->request->getPathInfo(),'demo'=>$this->app->config['PAYMENT_DRIVER']==='demo'||$this->app->config['PROVISION_DRIVER']==='demo','now'=>time(),'site_name'=>$branding->name(),'support_url'=>$this->app->config['SUPPORT_URL'],'telegram_enabled'=>$this->app->config['TELEGRAM_BOT_TOKEN']!==''&&$this->app->config['TELEGRAM_BOT_USERNAME']!=='','purchases_enabled'=>$this->app->config['PURCHASES_ENABLED']==='1','receipt_required'=>$this->app->config['YOOKASSA_RECEIPT']==='1','freekassa_email_required'=>$freekassaEmail,'payment_driver'=>$this->app->config['PAYMENT_DRIVER'],'balance_kopeks'=>$balance,'logo'=>$branding->logo(),'favicon'=>$branding->favicon(),'css_vars'=>$branding->cssVars(),'footer_text'=>$branding->footerText()],$data)),$status);
    }
}
