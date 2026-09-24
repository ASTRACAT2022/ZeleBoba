<?php
declare(strict_types=1);
namespace App\Web;
use Symfony\Component\HttpFoundation\{Response,RedirectResponse,JsonResponse};
use App\Billing\BillingError;
trait LoginActions
{
    private function guestCsrf():string
    {
        $value=$this->request->cookies->get('zb_guest','');
        return preg_match('/^[a-f0-9]{64}$/D',$value)?$value:bin2hex(random_bytes(32));
    }
    private function verifyGuest():void
    {
        $cookie=$this->request->cookies->get('zb_guest','');
        if(strlen($cookie)!==64 || !hash_equals($cookie,$this->request->request->get('_csrf',''))) throw new BillingError('Обновите страницу входа и повторите.');
    }
    private function telegramAuth(string $handler):Response
    {
        if($this->user)return new RedirectResponse('/');
        if(!$this->app->config['TELEGRAM_BOT_TOKEN']||!$this->app->config['TELEGRAM_BOT_USERNAME'])throw new BillingError('Вход через Telegram ещё не настроен.');
        if($this->request->isMethod('POST')){$this->verifyGuest();$this->app->auth->throttle('telegram-login:'.$this->request->getClientIp(),20,300);}
        $csrf=$this->guestCsrf();
        if($handler==='tg-start'){
            $challenge=$this->app->telegramLogin->begin();
            $r=$this->render('telegram-login',['guest_csrf'=>$csrf,'deep_link'=>'https://t.me/'.$this->app->config['TELEGRAM_BOT_USERNAME'].'?start=login_'.$challenge['token']]);
            $r->headers->setCookie($this->cookie('zb_tg',$challenge['browser'],time()+300));return $r;
        }
        if($handler==='tg-status')return new JsonResponse(['ready'=>$this->app->telegramLogin->ready($this->request->cookies->get('zb_tg',''))]);
        if($handler==='tg-finish' || ($handler==='tg-magic' && $this->request->isMethod('POST'))){
            $proof=$handler==='tg-finish'?$this->request->cookies->get('zb_tg',''):$this->request->request->get('token','');
            $session=$this->app->telegramLogin->consume($proof,$handler==='tg-finish'?'browser':'magic');
            return $this->signedIn($session);
        }
        $r=$this->render('telegram-magic',['guest_csrf'=>$csrf]);$r->headers->setCookie($this->cookie('zb_guest',$csrf,time()+3600));return $r;
    }
    private function signedIn(string $session):Response
    {
        $old=$this->request->cookies->get('zb_session','');if($old)$this->app->auth->logout($old);
        $r=new RedirectResponse('/',303);$r->headers->setCookie($this->cookie('zb_session',$session,time()+86400));$r->headers->clearCookie('zb_tg');return $r;
    }
    private function security(string $handler):Response
    {
        $uid=$this->user['id'];$secret=null;$codes=[];
        if($handler==='mfa-begin')$secret=$this->app->mfa->begin($uid);
        if($handler==='mfa-enroll'){$this->app->auth->throttle('mfa:'.$uid,10,300);$codes=$this->app->mfa->enroll($uid,$this->request->request->get('code',''));$this->app->mfa->stepUp($this->request->cookies->get('zb_session',''));}
        if($handler==='mfa-verify'){$this->app->auth->throttle('mfa:'.$uid,10,300);$this->app->mfa->verify($uid,$this->request->request->get('code',''));$this->app->mfa->stepUp($this->request->cookies->get('zb_session',''));return new RedirectResponse($this->user['role']==='admin'?'/admin':'/settings',303);}
        $this->user=$this->app->auth->session($this->request->cookies->get('zb_session',''));
        return $this->render('security',['secret'=>$secret,'recovery_codes'=>$codes]);
    }
}
