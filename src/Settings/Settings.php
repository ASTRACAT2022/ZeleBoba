<?php
declare(strict_types=1);
namespace App\Settings;
use App\Infrastructure\Database;
use App\Billing\BillingError;
final class Settings
{
    public const DEFAULTS=[
        'APP_ENV'=>'dev','APP_URL'=>'http://127.0.0.1:8080','SITE_NAME'=>'ZeleBoba','SUPPORT_URL'=>'',
        'PURCHASES_ENABLED'=>'0','REGISTRATION_ENABLED'=>'1',
        'PAYMENT_DRIVER'=>'demo','PROVISION_DRIVER'=>'demo',
        'YOOKASSA_SHOP_ID'=>'','YOOKASSA_SECRET'=>'',
        'YOOKASSA_RECEIPT'=>'0','YOOKASSA_VAT_CODE'=>'1','YOOKASSA_TAX_SYSTEM'=>'',
        'FREEKASSA_SHOP_ID'=>'','FREEKASSA_API_KEY'=>'','FREEKASSA_SECRET2'=>'','FREEKASSA_PAYMENT_ID'=>'44',
        'REMNAWAVE_URL'=>'','REMNAWAVE_TOKEN'=>'','REMNAWAVE_SQUAD_UUID'=>'',
        'TELEGRAM_BOT_TOKEN'=>'','TELEGRAM_BOT_USERNAME'=>'','TELEGRAM_WEBHOOK_SECRET'=>'','TELEGRAM_API_BASE'=>'https://astracattg.netlify.app',
    ];
    public const SECRETS=['YOOKASSA_SECRET','FREEKASSA_API_KEY','FREEKASSA_SECRET2','REMNAWAVE_TOKEN','TELEGRAM_BOT_TOKEN','TELEGRAM_WEBHOOK_SECRET'];
    public function __construct(private Database $db,public readonly Vault $vault) {}
    public function installed():bool
    {
        return $this->db->postgres() ? $this->db->one("SELECT to_regclass('app_settings') AS name")['name']!==null : $this->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='app_settings'")!==null;
    }
    public function values():array
    {
        $values=self::DEFAULTS;
        if (!$this->installed()) return $values;
        foreach($this->db->all('SELECT name,value FROM app_settings') as $row) $values[$row['name']]=in_array($row['name'],self::SECRETS,true)?$this->vault->open($row['name'],$row['value']):$row['value'];
        return $values;
    }
    public function overrides():array
    {
        if (!$this->installed()) return [];
        return array_intersect_key($this->values(),array_column($this->db->all('SELECT name FROM app_settings'),'name','name'));
    }
    public function revision():int{return (int)($this->db->one('SELECT revision FROM settings_revision WHERE id=1')['revision']??0);}
    public function form():array
    {
        $values=$this->values();$set=[];
        foreach(self::SECRETS as $key){$set[$key]=$values[$key]!=='';$values[$key]='';}
        return ['values'=>$values,'secrets_set'=>$set,'revision'=>$this->revision()];
    }
    public function save(array $input,string $actor,int $revision):void
    {
        $this->db->transaction(function()use($input,$actor,$revision){
            $current=$this->db->one('SELECT revision FROM settings_revision WHERE id=1'.$this->db->lock());
            if ((int)$current['revision']!==$revision) throw new BillingError('Настройки изменились в другой вкладке. Обновите страницу.');
            $old=$this->values();$next=$old;
            foreach(self::DEFAULTS as $key=>$default){
                if (!array_key_exists($key,$input)) continue;
                if (!is_string($input[$key]) || strlen($input[$key])>2048) throw new BillingError('Некорректная настройка: '.$key);
                $value=trim($input[$key]);
                if (in_array($key,self::SECRETS,true) && $value==='') continue;
                $next[$key]=$value;
            }
            $this->validate($next);
            // A merchant/panel/bot replacement must not misroute outstanding work or existing access.
            foreach(['YOOKASSA_SHOP_ID','FREEKASSA_SHOP_ID','REMNAWAVE_URL','TELEGRAM_BOT_USERNAME'] as $key){
                if ($old[$key]!=='' && $old[$key]!==$next[$key]) throw new BillingError('Замена магазина, панели или бота требует отдельной миграции. Обновить ключ доступа можно здесь.');
            }
            foreach($next as $key=>$value){
                if ($old[$key]===$value) continue;
                $stored=in_array($key,self::SECRETS,true)?$this->vault->seal($key,$value):$value;
                $this->db->execute('INSERT INTO app_settings VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',[$key,$stored,time()]);
                $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$actor,'setting.changed',$key,time()]);
            }
            $this->db->execute('UPDATE settings_revision SET revision=revision+1 WHERE id=1');
        });
    }
    private function validate(array $v):void
    {
        foreach(['APP_ENV'=>['dev','prod'],'PAYMENT_DRIVER'=>['demo','yookassa','freekassa'],'PROVISION_DRIVER'=>['demo','remnawave'],'PURCHASES_ENABLED'=>['0','1'],'REGISTRATION_ENABLED'=>['0','1'],'YOOKASSA_RECEIPT'=>['0','1']] as $key=>$allowed)if(!in_array($v[$key],$allowed,true))throw new BillingError('Некорректная настройка '.$key);
        if (!filter_var($v['APP_URL'],FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\/[^\s]+$/D',$v['APP_URL']) || parse_url($v['APP_URL'],PHP_URL_USER)!==null || parse_url($v['APP_URL'],PHP_URL_QUERY)!==null || parse_url($v['APP_URL'],PHP_URL_FRAGMENT)!==null || !in_array(parse_url($v['APP_URL'],PHP_URL_PATH),[null,'','/'],true)) throw new BillingError('Укажите корневой URL кабинета, без пути и параметров.');
        if (mb_strlen($v['SITE_NAME'])<1 || mb_strlen($v['SITE_NAME'])>60) throw new BillingError('Название: от 1 до 60 символов.');
        foreach(['REMNAWAVE_URL','SUPPORT_URL'] as $key) if($v[$key]!=='' && (!filter_var($v[$key],FILTER_VALIDATE_URL) || !str_starts_with($v[$key],'https://') || parse_url($v[$key],PHP_URL_USER)!==null || parse_url($v[$key],PHP_URL_FRAGMENT)!==null))throw new BillingError($key.': нужен HTTPS URL без логина и фрагмента.');
        if ($v['REMNAWAVE_URL']!=='' && (!in_array(parse_url($v['REMNAWAVE_URL'],PHP_URL_PATH),[null,'','/'],true) || parse_url($v['REMNAWAVE_URL'],PHP_URL_QUERY)!==null)) throw new BillingError('URL панели указывается без /api и параметров.');
        if ($v['TELEGRAM_BOT_USERNAME']!=='' && !preg_match('/^[a-zA-Z0-9_]{2,29}bot$/iD',$v['TELEGRAM_BOT_USERNAME'])) throw new BillingError('Введите username бота без @.');
        if ($v['TELEGRAM_BOT_TOKEN']!=='' && !preg_match('/^[0-9]+:[a-zA-Z0-9_-]{20,}$/D',$v['TELEGRAM_BOT_TOKEN'])) throw new BillingError('Некорректный токен Telegram.');
        if ($v['TELEGRAM_WEBHOOK_SECRET']!=='' && !preg_match('/^[a-zA-Z0-9_-]{32,256}$/D',$v['TELEGRAM_WEBHOOK_SECRET'])) throw new BillingError('Секрет webhook: 32–256 латинских символов, цифр, _ или -.');
        if ($v['TELEGRAM_API_BASE']!=='' && (!filter_var($v['TELEGRAM_API_BASE'],FILTER_VALIDATE_URL) || !str_starts_with($v['TELEGRAM_API_BASE'],'https://') || parse_url($v['TELEGRAM_API_BASE'],PHP_URL_USER)!==null || parse_url($v['TELEGRAM_API_BASE'],PHP_URL_FRAGMENT)!==null || parse_url($v['TELEGRAM_API_BASE'],PHP_URL_QUERY)!==null)) throw new BillingError('TELEGRAM_API_BASE: нужен HTTPS URL без параметров и фрагмента.');
        if ($v['TELEGRAM_API_BASE']!=='' && !in_array(parse_url($v['TELEGRAM_API_BASE'],PHP_URL_PATH),[null,'','/'],true)) throw new BillingError('TELEGRAM_API_BASE: укажите корневой URL без пути, например https://astracattg.netlify.app');
        if (!in_array($v['YOOKASSA_VAT_CODE'],array_map('strval',range(1,12)),true) || !in_array($v['YOOKASSA_TAX_SYSTEM'],['','1','2','3','4','5','6'],true)) throw new BillingError('Проверьте параметры чека.');
        if ($v['FREEKASSA_SHOP_ID']!=='' && !preg_match('/^[0-9]{1,10}$/D',$v['FREEKASSA_SHOP_ID'])) throw new BillingError('ID магазина FreeKassa: только цифры.');
        if ($v['FREEKASSA_PAYMENT_ID']!=='' && !preg_match('/^[0-9]{1,5}$/D',$v['FREEKASSA_PAYMENT_ID'])) throw new BillingError('ID платёжной системы FreeKassa: только цифры.');
        if ($v['APP_ENV']==='prod' && (!$this->db->postgres() || !str_starts_with($v['APP_URL'],'https://'))) throw new BillingError('Боевой режим требует PostgreSQL и HTTPS.');
        if ($v['PURCHASES_ENABLED']==='1') {
            foreach(self::purchaseErrors($v) as $error) throw new BillingError($error);
            if($v['APP_ENV']==='prod'){
                if(!\App\Infrastructure\Permissions::safe($this->db))throw new BillingError('Ограничьте права runtime-пользователя базы перед включением продаж.');
                foreach([$v['PAYMENT_DRIVER']==='freekassa'?'freekassa':'yookassa','remnawave','telegram'] as $integration){
                    $check=$this->db->one('SELECT * FROM integration_checks WHERE integration=?',[$integration]);
                    if(!$check||$check['status']!=='ok'||!hash_equals($check['config_hash'],IntegrationCheck::fingerprint($v,$integration))||(int)$check['checked_at']<time()-86400)throw new BillingError('Сначала сохраните настройки с выключенными продажами и проверьте '.$integration.'.');
                }
            }
        }
    }
    public static function purchaseErrors(array $v):array
    {
        $errors=[];
        if($v['APP_ENV']==='prod' && ($v['PAYMENT_DRIVER']==='demo'||$v['PROVISION_DRIVER']==='demo'))$errors[]='В боевом режиме демоадаптеры запрещены.';
        if($v['PAYMENT_DRIVER']==='yookassa')foreach(['YOOKASSA_SHOP_ID','YOOKASSA_SECRET'] as $key)if(!$v[$key])$errors[]='Не заполнено: '.$key;
        if($v['PAYMENT_DRIVER']==='freekassa')foreach(['FREEKASSA_SHOP_ID','FREEKASSA_API_KEY','FREEKASSA_SECRET2'] as $key)if(!$v[$key])$errors[]='Не заполнено: '.$key;
        if($v['PROVISION_DRIVER']==='remnawave')foreach(['REMNAWAVE_URL','REMNAWAVE_TOKEN','REMNAWAVE_SQUAD_UUID'] as $key)if(!$v[$key])$errors[]='Не заполнено: '.$key;
        return $errors;
    }
}
