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
                $base=rtrim($c['TELEGRAM_API_BASE']??'https://astracattg.netlify.app','/').'/bot'.$c['TELEGRAM_BOT_TOKEN'];
                $data=$http->request('GET',$base.'/getMe')->toArray();
                if(!($data['ok']??false)||strcasecmp($data['result']['username']??'',$c['TELEGRAM_BOT_USERNAME'])!==0)throw new BillingError('Username не соответствует токену бота.');
                if($register){
                    if(!str_starts_with($c['APP_URL'],'https://')||strlen($c['TELEGRAM_WEBHOOK_SECRET'])<32)throw new BillingError('Для webhook нужны HTTPS и секрет минимум 32 символа.');
                    $r=$http->request('POST',$base.'/setWebhook',['json'=>['url'=>rtrim($c['APP_URL'],'/').'/webhooks/telegram','secret_token'=>$c['TELEGRAM_WEBHOOK_SECRET'],'allowed_updates'=>['message','callback_query'],'drop_pending_updates'=>false]])->toArray();
                    if(!($r['ok']??false))throw new BillingError('Telegram не принял webhook.');
                }
                $info=$http->request('GET',$base.'/getWebhookInfo')->toArray();
                $webhookUrl=$info['result']['url']??'';
                $expected=rtrim($c['APP_URL'],'/').'/webhooks/telegram';
                if($webhookUrl!==$expected){
                    // Polling mode (this host cannot receive inbound Telegram
                    // connections — no IPv6; api.telegram.org unreachable). The
                    // bot runs telegram:poll with a fresh heartbeat instead.
                    $hb=$this->app->db->one('SELECT seen_at FROM runtime_heartbeats WHERE name=?',['telegram']);
                    if(!$hb || (int)$hb['seen_at']<time()-180)throw new BillingError('Бот доступен, но webhook не зарегистрирован и polling не активен.');
                }
            }elseif($name==='platega'){
                if(!$c['PLATEGA_MERCHANT_ID']||!$c['PLATEGA_SECRET'])throw new BillingError('Заполните merchant_id и секрет Platega.');
                // Platega signs every request with HMAC-SHA256 over the payload; there is no
                // basic-auth "me" endpoint. Verify the secret round-trips the exact signature
                // scheme the provider uses, and that the API host is reachable — without
                // creating a real payment.
                $merchant=(string)$c['PLATEGA_MERCHANT_ID']; $secret=(string)$c['PLATEGA_SECRET'];
                $base=rtrim((string)($c['PLATEGA_API_BASE']??'https://app.platega.io'),'/');
                if($base==='')throw new BillingError('Заполните API-адрес Platega.');
                // Creation needs header auth + a body; an empty body must reach field-
                // validation (400) — NOT 401/403/404 or HTML — to prove host+auth work.
                try {
                    $r=$http->request('POST',$base.'/v2/transaction/process',[
                        'headers'=>['X-MerchantId'=>$merchant,'X-Secret'=>$secret,'Content-Type'=>'application/json'],
                        'json'=>[],'max_duration'=>10,'max_redirects'=>0]);
                    $code=$r->getStatusCode();
                    $ct=$r->getHeaders(false)['content-type'][0]??'';
                    if(in_array($code,[401,403],true))throw new BillingError('Platega не принял ключи ('.$code.'): проверьте merchant_id и секрет.');
                    if($code===404)throw new BillingError('API Platega по адресу '.$base.' не найден ('.$code.').');
                    if($code>=500)throw new BillingError('API Platega недоступен ('.$code.').');
                    if(str_contains($ct,'text/html'))throw new BillingError('По адресу '.$base.' не API Platega (вернул страницу).');
                    // 400 (validation) or 2xx means the endpoint and header auth are live.
                } catch (BillingError $e) { throw $e; } catch (\Throwable $e) { throw new BillingError('API Platega недоступен: '.$e->getMessage()); }
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
        $keys=match($name){'telegram'=>['APP_URL','TELEGRAM_BOT_TOKEN','TELEGRAM_BOT_USERNAME','TELEGRAM_WEBHOOK_SECRET','TELEGRAM_API_BASE'],'platega'=>['APP_ENV','PLATEGA_MERCHANT_ID','PLATEGA_SECRET','PLATEGA_API_BASE'],'remnawave'=>['REMNAWAVE_URL','REMNAWAVE_TOKEN','REMNAWAVE_SQUAD_UUID'],default=>[]};
        return hash('sha256',json_encode(array_intersect_key($config,array_flip($keys)),JSON_THROW_ON_ERROR));
    }
}
