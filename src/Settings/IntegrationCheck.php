<?php
declare(strict_types=1);
namespace App\Settings;
use App\Container;
use App\Billing\BillingError;
use Symfony\Component\HttpClient\HttpClient;
final class IntegrationCheck
{
    public function __construct(private Container $app) {}
    public function check(string $name,bool $register=false):void
    {
        $c=$this->app->config;$http=HttpClient::create(['timeout'=>8,'max_duration'=>15,'max_redirects'=>0]);
        try{
            if($name==='telegram'){
                if(!$c['TELEGRAM_BOT_TOKEN'] || !$c['TELEGRAM_BOT_USERNAME'])throw new BillingError('Сначала сохраните токен и username бота.');
                $base='https://api.telegram.org/bot'.$c['TELEGRAM_BOT_TOKEN'];
                $data=$http->request('GET',$base.'/getMe')->toArray();
                if(!($data['ok']??false)||strcasecmp($data['result']['username']??'',$c['TELEGRAM_BOT_USERNAME'])!==0)throw new BillingError('Username не соответствует токену бота.');
                if($register){
                    if(!str_starts_with($c['APP_URL'],'https://')||strlen($c['TELEGRAM_WEBHOOK_SECRET'])<32)throw new BillingError('Для webhook нужны HTTPS и секрет минимум 32 символа.');
                    $r=$http->request('POST',$base.'/setWebhook',['json'=>['url'=>rtrim($c['APP_URL'],'/').'/webhooks/telegram','secret_token'=>$c['TELEGRAM_WEBHOOK_SECRET'],'allowed_updates'=>['message','callback_query'],'drop_pending_updates'=>false]])->toArray();
                    if(!($r['ok']??false))throw new BillingError('Telegram не принял webhook.');
                }
                $info=$http->request('GET',$base.'/getWebhookInfo')->toArray();
                if(($info['result']['url']??'')!==rtrim($c['APP_URL'],'/').'/webhooks/telegram')throw new BillingError('Бот доступен, но webhook не зарегистрирован на этот кабинет.');
            }elseif($name==='yookassa'){
                if(!$c['YOOKASSA_SHOP_ID']||!$c['YOOKASSA_SECRET'])throw new BillingError('Заполните магазин и ключ.');
                $data=$http->request('GET','https://api.yookassa.ru/v3/me',['auth_basic'=>[$c['YOOKASSA_SHOP_ID'],$c['YOOKASSA_SECRET']]])->toArray();
                if((string)($data['account_id']??'')!==$c['YOOKASSA_SHOP_ID'])throw new BillingError('Идентификатор магазина не совпал.');
                if($c['APP_ENV']==='prod' && ($data['test']??true)!==false)throw new BillingError('Магазин работает в тестовом режиме.');
            }elseif($name==='freekassa'){
                if(!$c['FREEKASSA_SHOP_ID']||!$c['FREEKASSA_API_KEY'])throw new BillingError('Заполните ID магазина и API ключ FreeKassa.');
                $shopId=(int)$c['FREEKASSA_SHOP_ID']; $apiKey=(string)$c['FREEKASSA_API_KEY'];
                $nonce=(int)(microtime(true)*1000);
                $params=['shopId'=>$shopId,'nonce'=>$nonce];
                ksort($params);
                $sign=hash_hmac('sha256', implode('|', array_map('strval', array_values($params))), $apiKey);
                $params['signature']=$sign;
                $data=$http->request('POST','https://api.fk.life/v1/balance',['json'=>$params])->toArray(false);
                if(($data['type']??'')==='error') throw new BillingError('FreeKassa: '.($data['error']??'ошибка авторизации'));
                if(($data['type']??'')!=='success' && !isset($data['balance'])) throw new BillingError('FreeKassa не вернула баланс.');
            }elseif($name==='remnawave'){
                if(!$c['REMNAWAVE_URL']||!$c['REMNAWAVE_TOKEN']||!$c['REMNAWAVE_SQUAD_UUID'])throw new BillingError('Заполните URL, токен и UUID группы.');
                $data=$http->request('GET',rtrim($c['REMNAWAVE_URL'],'/').'/api/internal-squads',['auth_bearer'=>$c['REMNAWAVE_TOKEN']])->toArray();
                $squads=$data['response']['internalSquads']??[];
                if(!in_array($c['REMNAWAVE_SQUAD_UUID'],array_column($squads,'uuid'),true))throw new BillingError('Панель ответила, но выбранная группа не найдена.');
            }else throw new BillingError('Неизвестная интеграция.');
            $this->record($name,'ok');
        }catch(\Throwable $e){$this->record($name,'failed');if($e instanceof BillingError)throw $e;throw new BillingError('Проверка не прошла: проверьте адрес, ключ и доступность сервиса. Секреты в журнал не записываются.');}
    }
    private function record(string $name,string $status):void
    {
        $this->app->db->execute('INSERT INTO integration_checks VALUES(?,?,?,?) ON CONFLICT(integration) DO UPDATE SET config_hash=excluded.config_hash,status=excluded.status,checked_at=excluded.checked_at',[$name,self::fingerprint($this->app->config,$name),$status,time()]);
    }
    public static function fingerprint(array $config,string $name):string
    {
        $keys=match($name){'telegram'=>['APP_URL','TELEGRAM_BOT_TOKEN','TELEGRAM_BOT_USERNAME','TELEGRAM_WEBHOOK_SECRET'],'yookassa'=>['APP_ENV','YOOKASSA_SHOP_ID','YOOKASSA_SECRET'],'freekassa'=>['FREEKASSA_SHOP_ID','FREEKASSA_API_KEY','FREEKASSA_SECRET2','FREEKASSA_PAYMENT_ID'],'remnawave'=>['REMNAWAVE_URL','REMNAWAVE_TOKEN','REMNAWAVE_SQUAD_UUID'],default=>[]};
        return hash('sha256',json_encode(array_intersect_key($config,array_flip($keys)),JSON_THROW_ON_ERROR));
    }
}
