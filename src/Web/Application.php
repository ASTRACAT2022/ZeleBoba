<?php
declare(strict_types=1);
namespace App\Web;
use App\Container;
use App\Billing\BillingError;
use App\Infrastructure\Database;
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
    public function __construct(private Container $app)
    {
        $this->twig=new Environment(new FilesystemLoader(dirname(__DIR__,2).'/templates'),['strict_variables'=>true,'autoescape'=>'html']);
        $this->twig->addFilter(new \Twig\TwigFilter('rub',fn($n)=>number_format((int)$n/100,0,',',' ').' ₽'));
    }
    public function handle(Request $r): Response
    {
        $this->request=$r; $this->user=null; $requestId=Database::id();
        try {
            $routes=new RouteCollection();
            foreach ([['tg-start','/telegram/start',['POST']],['tg-status','/telegram/status',['GET']],['tg-finish','/telegram/finish',['POST']],['tg-magic','/telegram/magic',['GET','POST']],['security','/security',['GET']],['mfa-begin','/security/begin',['POST']],['mfa-enroll','/security/enroll',['POST']],['mfa-verify','/security/verify',['POST']],['admin-config','/admin/config',['GET']],['admin-config-save','/admin/config',['POST']],['admin-check','/admin/check/{id}',['POST']],['admin-readiness','/admin/readiness',['GET']],['admin-plans','/admin/plans',['GET']],['admin-plan-save','/admin/plans/{id}',['POST']],['admin-users','/admin/users',['GET']],['admin-user-toggle','/admin/users/{id}/toggle',['POST']],['health','/health/live',['GET']],['ready','/health/ready',['GET']],['login','/login',['GET','POST']],['register','/register',['GET','POST']],['logout','/logout',['POST']],['home','/',['GET']],['plans','/plans',['GET']],['orders','/orders',['GET']],['buy','/orders',['POST']],['order','/orders/{id}',['GET']],['demo','/orders/{id}/demo-pay',['POST']],['settings','/settings',['GET']],['link','/settings/telegram',['POST']],['admin','/admin',['GET']],['retry','/admin/jobs/{id}/retry',['POST']],['plan','/admin/plans',['POST']],['yookassa','/webhooks/yookassa',['POST']],['freekassa','/webhooks/freekassa',['GET','POST']],['telegram','/webhooks/telegram',['POST']]] as [$name,$path,$methods]) $routes->add($name,new Route($path,['_handler'=>$name],[],[], '',[],$methods));
            $match=(new UrlMatcher($routes,(new RequestContext())->fromRequest($r)))->match($r->getPathInfo());
            $handler=$match['_handler'];
            if ($handler==='health') return new JsonResponse(['status'=>'ok']);
            if ($handler==='ready') { $this->app->db->one('SELECT version FROM migrations LIMIT 1'); return new JsonResponse(['status'=>'ready']); }
            if (strlen($r->getContent())>65536) return new JsonResponse(['error'=>'Request too large'],413);
            if (in_array($handler,['yookassa','freekassa','telegram'],true)) return $this->webhook($handler);
            $this->user=$this->app->auth->session($r->cookies->get('zb_session',''));
            if(str_starts_with($handler,'tg-')) return $this->telegramAuth($handler);
            if (in_array($handler,['login','register'],true)) return $this->authentication($handler);
            if (!$this->user) return new RedirectResponse('/login');
            if ($r->isMethod('POST')) {
                if (!hash_equals($this->user['csrf'],$r->request->get('_csrf',''))) return $this->render('error',['message'=>'Сессия формы устарела. Обновите страницу.'],403);
                $this->app->auth->throttle('mutate:'.$this->user['id'],60,60);
            }
            if (in_array($handler,['security','mfa-begin','mfa-enroll','mfa-verify'],true))return $this->security($handler);
            if(str_starts_with($handler,'admin') || in_array($handler,['retry','plan'],true)){
                if($this->user['role']!=='admin')return $this->render('error',['message'=>'Недостаточно прав.'],403);
                if(!(int)$this->user['mfa_enabled'] || (int)$this->user['admin_verified_until']<time())return new RedirectResponse('/security',303);
            }
            if(str_starts_with($handler,'admin-'))return $this->administration($handler,$match['id']??'');
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
    private function dispatch(string $handler,string $id): Response
    {
        $db=$this->app->db; $uid=$this->user['id']; $input=$this->request->request;
        switch ($handler) {
            case 'logout': $this->app->auth->logout($this->request->cookies->get('zb_session','')); $response=new RedirectResponse('/login'); $response->headers->clearCookie('zb_session'); return $response;
            case 'home':
                return $this->render('home',['subscriptions'=>$db->all('SELECT s.*,o.plan_name,o.devices,o.traffic_bytes FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 50',[$uid]),'orders'=>$db->all('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 5',[$uid])]);
            case 'plans': return $this->render('plans',['plans'=>$db->all('SELECT * FROM plans WHERE active=1 ORDER BY price_minor'),'key'=>Database::id()]);
            case 'buy': $order=$this->app->billing->order($uid,$input->get('plan_id',''),$input->get('idempotency_key',''),$input->get('receipt_email'),self::clientIp($this->request)); return new RedirectResponse('/orders/'.$order['id'],303);
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
            case 'settings': return $this->render('settings',['link_token'=>null]);
            case 'link':
                $token=bin2hex(random_bytes(24));
                $db->transaction(function () use ($db,$uid,$token) {
                    $db->execute('DELETE FROM telegram_links WHERE user_id=?',[$uid]);
                    $db->execute('INSERT INTO telegram_links VALUES(?,?,?)',[hash('sha256',$token),$uid,time()+600]);
                });
                return $this->render('settings',['link_token'=>$token]);
            case 'admin': return $this->render('admin',['jobs'=>$db->all("SELECT id,topic,status,attempts,last_error FROM outbox WHERE status!='done' ORDER BY created_at LIMIT 100"),'recent'=>$db->all('SELECT o.*,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 30'),'plans'=>$db->all('SELECT * FROM plans ORDER BY price_minor'),'audit'=>$db->all('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 20')]);
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
    private function authentication(string $mode): Response
    {
        if ($this->user) return new RedirectResponse('/');
        if($mode==='register' && $this->app->config['REGISTRATION_ENABLED']!=='1')throw new BillingError('Регистрация временно закрыта.');
        $csrf=$this->request->cookies->get('zb_guest','');
        if (!preg_match('/^[a-f0-9]{64}$/D',$csrf)) $csrf=bin2hex(random_bytes(32));
        if ($this->request->isMethod('POST')) {
            if (!hash_equals($csrf,$this->request->request->get('_csrf',''))) return $this->render('error',['message'=>'Обновите страницу входа.'],403);
            $email=$this->request->request->get('email',''); $password=$this->request->request->get('password','');
            $this->app->auth->throttle('auth-ip:'.$this->request->getClientIp(),20);
            $this->app->auth->throttle('auth-email:'.mb_strtolower(trim($email)),10);
            $uid=$mode==='register'?$this->app->auth->register($email,$password):$this->app->auth->login($email,$password);
            $user=$this->app->db->one('SELECT totp_secret FROM users WHERE id=?',[$uid]);
            if($user['totp_secret'])$this->app->mfa->verify($uid,$this->request->request->get('code',''));
            $session=$this->app->auth->issue($uid);if($user['totp_secret'])$this->app->mfa->stepUp($session);
            return $this->signedIn($session);
        }
        $response=$this->render('auth',['mode'=>$mode,'guest_csrf'=>$csrf]);
        $response->headers->setCookie($this->cookie('zb_guest',$csrf,time()+3600));
        return $response;
    }
    private function webhook(string $handler): Response
    {
        if ($handler==='telegram') {
            $secret=$this->app->config['TELEGRAM_WEBHOOK_SECRET'];
            if (strlen($secret)<32 || !hash_equals($secret,$this->request->headers->get('X-Telegram-Bot-Api-Secret-Token',''))) return new JsonResponse(['error'=>'Forbidden'],403);
            $this->app->telegram->receive($this->request->toArray());
        } elseif ($handler==='freekassa') {
            return $this->freekassaWebhook();
        } else {
            if ($this->app->config['PAYMENT_DRIVER']!=='yookassa') return new JsonResponse(['error'=>'Disabled'],404);
            $this->app->auth->throttle('webhook:'.$this->request->getClientIp(),120,60);
            $data=$this->request->toArray();
            if (!in_array($data['event']??'',['payment.succeeded','payment.canceled'],true)) return new JsonResponse(['ok'=>true]);
            // Webhook content only identifies a payment. Authoritative status is fetched with merchant credentials.
            $paymentId=$data['object']['id']??'';
            if(!is_string($paymentId)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$paymentId))return new JsonResponse(['error'=>'Invalid payment id'],400);
            $hint=$data['object']['metadata']['order_id']??'';
            if(!is_string($hint))return new JsonResponse(['error'=>'Invalid order id'],400);
            $known=$this->app->db->one("SELECT id FROM orders WHERE provider='yookassa' AND (provider_payment_id=? OR (id=? AND provider_payment_id IS NULL AND status='pending'))",[$paymentId,$hint]);
            if(!$known)return new JsonResponse(['ok'=>true]);
            $this->app->db->transaction(function()use($paymentId,$data){$this->app->outbox->enqueue('payment.verify','verify:'.$paymentId.':'.$data['event'],['payment_id'=>$paymentId]);});
        }
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
        // IP whitelist — check X-Real-IP (set by reverse proxy) and X-Forwarded-For chain
        $allowed=['168.119.157.136','168.119.60.227','178.154.197.79','51.250.54.238'];
        $candidates=array_filter([
            $this->request->getClientIp()??'',
            $this->request->headers->get('X-Real-IP',''),
            trim(explode(',',$this->request->headers->get('X-Forwarded-For',''))[0]??''),
            $this->request->server->get('REMOTE_ADDR',''),
        ]);
        $okIp=false; foreach($candidates as $ip){ if(in_array(trim($ip),$allowed,true)){$okIp=true;break;}}
        if (!$okIp) return new Response('hacking attempt!',403);
        if (!\App\Integration\Payments::verifyFreekassaNotification(['MERCHANT_ID'=>$merchantId,'AMOUNT'=>$amount,'MERCHANT_ORDER_ID'=>$orderId,'SIGN'=>$sign],(string)($this->app->config['FREEKASSA_SECRET2']??''))) return new Response('wrong sign',403);
        if (!preg_match('/^[a-f0-9]{32}$/D',$orderId)) return new Response('YES');
        $order=$this->app->db->one("SELECT * FROM orders WHERE id=? AND provider='freekassa'",[$orderId]);
        if (!$order) return new Response('YES');
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
        $this->app->db->transaction(function()use($verifyId,$intid,$orderId){$this->app->outbox->enqueue('payment.verify','verify-fk:'.$orderId.':'.$intid,['payment_id'=>$verifyId]);});
        return new Response('YES');
    }
    private function cookie(string $name,string $value,int $expires): Cookie
    {
        return Cookie::create($name)->withValue($value)->withExpires($expires)->withHttpOnly(true)->withSecure($this->app->config['APP_ENV']==='prod'||$this->request->isSecure())->withSameSite('lax')->withPath('/');
    }
    private static function clientIp(Request $r): string
    {
        // Prefer proxy headers (X-Real-IP / X-Forwarded-For) — internal nginx sets X-Real-IP from the TLS proxy.
        // FreeKassa blocks 127.0.0.1, so never return loopback if a public candidate exists.
        $candidates=[
            $r->headers->get('X-Real-IP',''),
            trim(explode(',',$r->headers->get('X-Forwarded-For',''))[0]??''),
            $r->getClientIp()??'',
            $r->server->get('REMOTE_ADDR',''),
        ];
        $fallback='';
        foreach($candidates as $ip){
            $ip=trim($ip);
            if($ip===''||!filter_var($ip,FILTER_VALIDATE_IP)) continue;
            if($fallback==='') $fallback=$ip;
            if($ip!=='127.0.0.1'&&$ip!=='::1') return $ip;
        }
        return $fallback;
    }
    private function render(string $view,array $data=[],int $status=200): Response
    {
        $freekassaEmail=$this->app->config['PAYMENT_DRIVER']==='freekassa';
        return new Response($this->twig->render($view.'.html.twig',array_merge(['user'=>$this->user,'path'=>$this->request->getPathInfo(),'demo'=>$this->app->config['PAYMENT_DRIVER']==='demo'||$this->app->config['PROVISION_DRIVER']==='demo','now'=>time(),'site_name'=>$this->app->config['SITE_NAME'],'support_url'=>$this->app->config['SUPPORT_URL'],'telegram_enabled'=>$this->app->config['TELEGRAM_BOT_TOKEN']!==''&&$this->app->config['TELEGRAM_BOT_USERNAME']!=='','purchases_enabled'=>$this->app->config['PURCHASES_ENABLED']==='1','receipt_required'=>$this->app->config['YOOKASSA_RECEIPT']==='1','freekassa_email_required'=>$freekassaEmail,'payment_driver'=>$this->app->config['PAYMENT_DRIVER']],$data)),$status);
    }
}
