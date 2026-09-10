<?php
declare(strict_types=1);
namespace App\Settings;
use App\Infrastructure\Database;
use App\Billing\BillingError;
final class Settings
{
    public const DEFAULTS=[
        'APP_ENV'=>'dev','APP_URL'=>'http://127.0.0.1:8080','SITE_NAME'=>'ZeleBoba','SUPPORT_URL'=>'',
        'BRAND_LOGO'=>'','BRAND_FAVICON'=>'','BRAND_COLOR'=>'#135d45','BRAND_COLOR_ACCENT'=>'#d8f784','BRAND_FOOTER_TEXT'=>'',
        'BRAND_WELCOME_TEXT'=>'','BRAND_HELP_TEXT'=>'',
        'PURCHASES_ENABLED'=>'0','REGISTRATION_ENABLED'=>'1',
        'PAYMENT_DRIVER'=>'demo','PROVISION_DRIVER'=>'demo',
        'YOOKASSA_SHOP_ID'=>'','YOOKASSA_SECRET'=>'',
        'YOOKASSA_RECEIPT'=>'0','YOOKASSA_VAT_CODE'=>'1','YOOKASSA_TAX_SYSTEM'=>'',
        'FREEKASSA_SHOP_ID'=>'','FREEKASSA_API_KEY'=>'','FREEKASSA_SECRET2'=>'','FREEKASSA_PAYMENT_ID'=>'44',
        'CRYPTOBOT_ENABLED'=>'0','CRYPTOBOT_API_TOKEN'=>'','CRYPTOBOT_WEBHOOK_SECRET'=>'','CRYPTOBOT_BASE_URL'=>'https://pay.crypt.bot','CRYPTOBOT_DEFAULT_ASSET'=>'USDT','CRYPTOBOT_INVOICE_EXPIRES_HOURS'=>'24',
        'TELEGRAM_STARS_ENABLED'=>'0','STARS_RATE_KOPEKS'=>'',
        'LAVA_ENABLED'=>'0','LAVA_SHOP_ID'=>'','LAVA_SECRET_KEY'=>'',
        'WATA_ENABLED'=>'0','WATA_ACCESS_TOKEN'=>'','WATA_TERMINAL_PUBLIC_ID'=>'',
        'HELEKET_ENABLED'=>'0','HELEKET_MERCHANT_ID'=>'','HELEKET_API_KEY'=>'',
        'PLATEGA_ENABLED'=>'0','PLATEGA_MERCHANT_ID'=>'','PLATEGA_SECRET'=>'',
        'TRIBUTE_ENABLED'=>'0','TRIBUTE_API_KEY'=>'','TRIBUTE_DONATE_LINK'=>'',
        'MULENPAY_ENABLED'=>'0','MULENPAY_API_KEY'=>'','MULENPAY_SECRET_KEY'=>'','MULENPAY_SHOP_ID'=>'',
        'PAL24_ENABLED'=>'0','PAL24_API_TOKEN'=>'','PAL24_SHOP_ID'=>'','PAL24_SIGNATURE_TOKEN'=>'',
        'CLOUDPAYMENTS_ENABLED'=>'0','CLOUDPAYMENTS_PUBLIC_ID'=>'','CLOUDPAYMENTS_API_SECRET'=>'',
        'KASSA_AI_ENABLED'=>'0','KASSA_AI_SHOP_ID'=>'','KASSA_AI_API_KEY'=>'','KASSA_AI_SECRET_WORD_2'=>'','KASSA_AI_PAYMENT_SYSTEM_ID'=>'44',
        'RIOPAY_ENABLED'=>'0','RIOPAY_API_TOKEN'=>'','RIOPAY_WEBHOOK_SECRET'=>'',
        'SEVERPAY_ENABLED'=>'0','SEVERPAY_MID'=>'','SEVERPAY_TOKEN'=>'',
        'PAYPEAR_ENABLED'=>'0','PAYPEAR_SHOP_ID'=>'','PAYPEAR_SECRET_KEY'=>'',
        'ROLLYPAY_ENABLED'=>'0','ROLLYPAY_API_KEY'=>'','ROLLYPAY_SIGNING_SECRET'=>'',
        'OVERPAY_ENABLED'=>'0','OVERPAY_PROJECT_ID'=>'','OVERPAY_PASSWORD'=>'',
        'AURAPAY_ENABLED'=>'0','AURAPAY_API_KEY'=>'','AURAPAY_SHOP_ID'=>'','AURAPAY_SECRET_KEY'=>'',
        'ETOPLATEZHI_ENABLED'=>'0','ETOPLATEZHI_PROJECT_ID'=>'','ETOPLATEZHI_SECRET_KEY'=>'',
        'ANTILOPAY_ENABLED'=>'0','ANTILOPAY_SECRET_ID'=>'','ANTILOPAY_PROJECT_ID'=>'',
        'JUPITER_ENABLED'=>'0','JUPITER_TOKEN'=>'','JUPITER_SECRET'=>'','JUPITER_METHOD_ID'=>'',
        'DONUT_ENABLED'=>'0','DONUT_TOKEN'=>'','DONUT_SECRET'=>'','DONUT_METHOD_ID'=>'',
        'CISPAY_ENABLED'=>'0','CISPAY_SHOP_ID'=>'','CISPAY_API_KEY'=>'',
        'TABPAY_ENABLED'=>'0','TABPAY_API_KEY'=>'','TABPAY_WEBHOOK_SECRET'=>'',
        'PARITYPAY_ENABLED'=>'0','PARITYPAY_SHOP_ID'=>'','PARITYPAY_SECRET_KEY'=>'','PARITYPAY_CALLBACK_SECRET'=>'',
        'REFERRAL_PROGRAM_ENABLED'=>'1','REFERRAL_MINIMUM_TOPUP_KOPEKS'=>'10000','REFERRAL_FIRST_TOPUP_BONUS_KOPEKS'=>'10000','REFERRAL_INVITER_BONUS_KOPEKS'=>'10000','REFERRAL_COMMISSION_PERCENT'=>'25','REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT'=>'','REFERRAL_RECURRING_COMMISSION_TIERS'=>'','REFERRAL_MAX_COMMISSION_PAYMENTS'=>'0',
        'REFERRAL_WITHDRAWAL_ENABLED'=>'0','REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS'=>'100000','REFERRAL_WITHDRAWAL_COOLDOWN_DAYS'=>'30','REFERRAL_WITHDRAWAL_SUSPICIOUS_MIN_DEPOSIT_KOPEKS'=>'50000','REFERRAL_WITHDRAWAL_SUSPICIOUS_MAX_DEPOSITS_PER_MONTH'=>'10',
        'CABINET_GIFT_ENABLED'=>'0',
        'TRIAL_DURATION_DAYS'=>'3','TRIAL_TRAFFIC_LIMIT_GB'=>'10','TRIAL_DEVICE_LIMIT'=>'2','TRIAL_ADD_REMAINING_DAYS_TO_PAID'=>'0','TRIAL_PAYMENT_ENABLED'=>'0','TRIAL_ACTIVATION_PRICE'=>'0',
        'AUTORENEW_ENABLED'=>'0','AUTORENEW_DAYS_BEFORE'=>'3','AUTORENEW_MAX_FAILS'=>'3',
        'REMNAWAVE_URL'=>'','REMNAWAVE_TOKEN'=>'','REMNAWAVE_SQUAD_UUID'=>'',
        'TELEGRAM_BOT_TOKEN'=>'','TELEGRAM_BOT_USERNAME'=>'','TELEGRAM_WEBHOOK_SECRET'=>'','TELEGRAM_API_BASE'=>'https://astracattg.netlify.app',
        'SMTP_ENABLED'=>'0','SMTP_HOST'=>'smtp.gmail.com','SMTP_PORT'=>'587','SMTP_USER'=>'','SMTP_PASSWORD'=>'','SMTP_FROM'=>'','SMTP_FROM_NAME'=>'ASTRACAT',
    ];
    public const SECRETS=['YOOKASSA_SECRET','FREEKASSA_API_KEY','FREEKASSA_SECRET2','REMNAWAVE_TOKEN','TELEGRAM_BOT_TOKEN','TELEGRAM_WEBHOOK_SECRET','CRYPTOBOT_API_TOKEN','CRYPTOBOT_WEBHOOK_SECRET','LAVA_SECRET_KEY','WATA_ACCESS_TOKEN','HELEKET_API_KEY','PLATEGA_SECRET','TRIBUTE_API_KEY','MULENPAY_API_KEY','MULENPAY_SECRET_KEY','PAL24_API_TOKEN','PAL24_SIGNATURE_TOKEN','CLOUDPAYMENTS_API_SECRET','KASSA_AI_API_KEY','KASSA_AI_SECRET_WORD_2','RIOPAY_API_TOKEN','RIOPAY_WEBHOOK_SECRET','SEVERPAY_TOKEN','PAYPEAR_SECRET_KEY','ROLLYPAY_API_KEY','ROLLYPAY_SIGNING_SECRET','OVERPAY_PASSWORD','AURAPAY_API_KEY','AURAPAY_SECRET_KEY','ETOPLATEZHI_SECRET_KEY','ANTILOPAY_SECRET_ID','JUPITER_TOKEN','JUPITER_SECRET','DONUT_TOKEN','DONUT_SECRET','CISPAY_API_KEY','TABPAY_API_KEY','TABPAY_WEBHOOK_SECRET','PARITYPAY_SECRET_KEY','PARITYPAY_CALLBACK_SECRET','SMTP_PASSWORD'];
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
        foreach(['APP_ENV'=>['dev','prod'],'PAYMENT_DRIVER'=>['demo','yookassa','freekassa'],'PROVISION_DRIVER'=>['demo','remnawave'],'PURCHASES_ENABLED'=>['0','1'],'REGISTRATION_ENABLED'=>['0','1'],'YOOKASSA_RECEIPT'=>['0','1'],'AUTORENEW_ENABLED'=>['0','1']] as $key=>$allowed)if(!in_array($v[$key],$allowed,true))throw new BillingError('Некорректная настройка '.$key);
        if (!filter_var($v['APP_URL'],FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\/[^\s]+$/D',$v['APP_URL']) || parse_url($v['APP_URL'],PHP_URL_USER)!==null || parse_url($v['APP_URL'],PHP_URL_QUERY)!==null || parse_url($v['APP_URL'],PHP_URL_FRAGMENT)!==null || !in_array(parse_url($v['APP_URL'],PHP_URL_PATH),[null,'','/'],true)) throw new BillingError('Укажите корневой URL кабинета, без пути и параметров.');
        if (mb_strlen($v['SITE_NAME'])<1 || mb_strlen($v['SITE_NAME'])>60) throw new BillingError('Название: от 1 до 60 символов.');
        if ($v['BRAND_LOGO']!=='' && !preg_match('/^https:\/\/[^\s]+$/D',$v['BRAND_LOGO'])) throw new BillingError('Логотип: нужен HTTPS URL картинки.');
        if ($v['BRAND_FAVICON']!=='' && !preg_match('/^https:\/\/[^\s]+$/D',$v['BRAND_FAVICON'])) throw new BillingError('Favicon: нужен HTTPS URL картинки.');
        if ($v['BRAND_COLOR']!=='' && !preg_match('/^#[0-9a-fA-F]{6}$/D',$v['BRAND_COLOR'])) throw new BillingError('Основной цвет: формат #RRGGBB.');
        if ($v['BRAND_COLOR_ACCENT']!=='' && !preg_match('/^#[0-9a-fA-F]{6}$/D',$v['BRAND_COLOR_ACCENT'])) throw new BillingError('Акцентный цвет: формат #RRGGBB.');
        if (mb_strlen($v['BRAND_FOOTER_TEXT'])>200) throw new BillingError('Текст подвала: до 200 символов.');
        if (mb_strlen($v['BRAND_WELCOME_TEXT'])>2000) throw new BillingError('Приветствие бота: до 2000 символов.');
        if (mb_strlen($v['BRAND_HELP_TEXT'])>2000) throw new BillingError('Справка бота: до 2000 символов.');
        foreach(['REMNAWAVE_URL','SUPPORT_URL'] as $key) if($v[$key]!=='' && (!filter_var($v[$key],FILTER_VALIDATE_URL) || !str_starts_with($v[$key],'https://') || parse_url($v[$key],PHP_URL_USER)!==null || parse_url($v[$key],PHP_URL_FRAGMENT)!==null))throw new BillingError($key.': нужен HTTPS URL без логина и фрагмента.');
        if ($v['REMNAWAVE_URL']!=='' && (!in_array(parse_url($v['REMNAWAVE_URL'],PHP_URL_PATH),[null,'','/'],true) || parse_url($v['REMNAWAVE_URL'],PHP_URL_QUERY)!==null)) throw new BillingError('URL панели указывается без /api и параметров.');
        if ($v['TELEGRAM_BOT_USERNAME']!=='' && !preg_match('/^[a-zA-Z0-9_]{2,29}bot$/iD',$v['TELEGRAM_BOT_USERNAME'])) throw new BillingError('Введите username бота без @.');
        if ($v['TELEGRAM_BOT_TOKEN']!=='' && !preg_match('/^[0-9]+:[a-zA-Z0-9_-]{20,}$/D',$v['TELEGRAM_BOT_TOKEN'])) throw new BillingError('Некорректный токен Telegram.');
        if ($v['TELEGRAM_WEBHOOK_SECRET']!=='' && !preg_match('/^[a-zA-Z0-9_-]{32,256}$/D',$v['TELEGRAM_WEBHOOK_SECRET'])) throw new BillingError('Секрет webhook: 32–256 латинских символов, цифр, _ или -.');
        if ($v['TELEGRAM_API_BASE']!=='' && (!filter_var($v['TELEGRAM_API_BASE'],FILTER_VALIDATE_URL) || !str_starts_with($v['TELEGRAM_API_BASE'],'https://') || parse_url($v['TELEGRAM_API_BASE'],PHP_URL_USER)!==null || parse_url($v['TELEGRAM_API_BASE'],PHP_URL_FRAGMENT)!==null || parse_url($v['TELEGRAM_API_BASE'],PHP_URL_QUERY)!==null)) throw new BillingError('TELEGRAM_API_BASE: нужен HTTPS URL без параметров и фрагмента.');
        if ($v['TELEGRAM_API_BASE']!=='' && !in_array(parse_url($v['TELEGRAM_API_BASE'],PHP_URL_PATH),[null,'','/'],true)) throw new BillingError('TELEGRAM_API_BASE: укажите корневой URL без пути, например https://astracattg.netlify.app');
        if (!in_array($v['SMTP_ENABLED'],['0','1'],true)) throw new BillingError('Некорректная настройка SMTP_ENABLED.');
        if ($v['SMTP_ENABLED']==='1' && ($v['SMTP_HOST']==='' || $v['SMTP_USER']==='' || $v['SMTP_PASSWORD']==='')) throw new BillingError('SMTP включён, но не заполнены хост, логин и пароль.');
        if ($v['SMTP_PORT']!=='' && (!preg_match('/^[0-9]{1,5}$/D',$v['SMTP_PORT']) || (int)$v['SMTP_PORT']<1 || (int)$v['SMTP_PORT']>65535)) throw new BillingError('SMTP_PORT: число от 1 до 65535.');
        if ($v['SMTP_FROM']!=='' && !filter_var($v['SMTP_FROM'],FILTER_VALIDATE_EMAIL)) throw new BillingError('SMTP_FROM: нужен корректный email.');
        if (mb_strlen($v['SMTP_FROM_NAME'])>60) throw new BillingError('SMTP_FROM_NAME: до 60 символов.');
        if (!in_array($v['YOOKASSA_VAT_CODE'],array_map('strval',range(1,12)),true) || !in_array($v['YOOKASSA_TAX_SYSTEM'],['','1','2','3','4','5','6'],true)) throw new BillingError('Проверьте параметры чека.');
        if ($v['FREEKASSA_SHOP_ID']!=='' && !preg_match('/^[0-9]{1,10}$/D',$v['FREEKASSA_SHOP_ID'])) throw new BillingError('ID магазина FreeKassa: только цифры.');
        if ($v['FREEKASSA_PAYMENT_ID']!=='' && !preg_match('/^[0-9]{1,5}$/D',$v['FREEKASSA_PAYMENT_ID'])) throw new BillingError('ID платёжной системы FreeKassa: только цифры.');
        foreach (['CRYPTOBOT_ENABLED','TELEGRAM_STARS_ENABLED','LAVA_ENABLED','WATA_ENABLED','HELEKET_ENABLED','PLATEGA_ENABLED','TRIBUTE_ENABLED'] as $key) if (!in_array($v[$key],['0','1'],true)) throw new BillingError('Некорректная настройка '.$key);
        if ($v['CRYPTOBOT_ENABLED']==='1' && $v['CRYPTOBOT_API_TOKEN']==='') throw new BillingError('CryptoBot включён, но не указан API-токен.');
        if ($v['TELEGRAM_STARS_ENABLED']==='1' && ($v['STARS_RATE_KOPEKS']==='' || !preg_match('/^[0-9]{1,4}(\.[0-9]{1,2})?$/D',$v['STARS_RATE_KOPEKS']) || (float)$v['STARS_RATE_KOPEKS']<=0)) throw new BillingError('Для Telegram Stars укажите курс: сколько звёзд за 1 ₽.');
        if ($v['LAVA_ENABLED']==='1' && ($v['LAVA_SHOP_ID']==='' || $v['LAVA_SECRET_KEY']==='')) throw new BillingError('Lava включён, но не заполнены ключи.');
        if ($v['WATA_ENABLED']==='1' && ($v['WATA_ACCESS_TOKEN']==='' || $v['WATA_TERMINAL_PUBLIC_ID']==='')) throw new BillingError('WATA включён, но не заполнены ключи.');
        if ($v['HELEKET_ENABLED']==='1' && ($v['HELEKET_MERCHANT_ID']==='' || $v['HELEKET_API_KEY']==='')) throw new BillingError('Heleket включён, но не заполнены ключи.');
        if ($v['PLATEGA_ENABLED']==='1' && ($v['PLATEGA_MERCHANT_ID']==='' || $v['PLATEGA_SECRET']==='')) throw new BillingError('Platega включена, но не заполнены ключи.');
        if ($v['TRIBUTE_ENABLED']==='1' && ($v['TRIBUTE_API_KEY']==='' || $v['TRIBUTE_DONATE_LINK']==='')) throw new BillingError('Tribute включён, но не заполнены ключи.');
        if ($v['TRIBUTE_DONATE_LINK']!=='' && !str_starts_with($v['TRIBUTE_DONATE_LINK'],'https://')) throw new BillingError('TRIBUTE_DONATE_LINK: нужен HTTPS URL.');
        foreach (['MULENPAY_ENABLED','PAL24_ENABLED','CLOUDPAYMENTS_ENABLED','KASSA_AI_ENABLED','RIOPAY_ENABLED','SEVERPAY_ENABLED','PAYPEAR_ENABLED','ROLLYPAY_ENABLED','OVERPAY_ENABLED','AURAPAY_ENABLED','ETOPLATEZHI_ENABLED','ANTILOPAY_ENABLED','JUPITER_ENABLED','DONUT_ENABLED','CISPAY_ENABLED','TABPAY_ENABLED','PARITYPAY_ENABLED'] as $key) if (!in_array($v[$key],['0','1'],true)) throw new BillingError('Некорректная настройка '.$key);
        if ($v['MULENPAY_ENABLED']==='1' && ($v['MULENPAY_API_KEY']==='' || $v['MULENPAY_SECRET_KEY']==='' || $v['MULENPAY_SHOP_ID']==='')) throw new BillingError('MulenPay включён, но не заполнены ключи.');
        if ($v['PAL24_ENABLED']==='1' && ($v['PAL24_API_TOKEN']==='' || $v['PAL24_SHOP_ID']==='')) throw new BillingError('Pal24 включён, но не заполнены ключи.');
        if ($v['CLOUDPAYMENTS_ENABLED']==='1' && ($v['CLOUDPAYMENTS_PUBLIC_ID']==='' || $v['CLOUDPAYMENTS_API_SECRET']==='')) throw new BillingError('CloudPayments включён, но не заполнены ключи.');
        if ($v['KASSA_AI_ENABLED']==='1' && ($v['KASSA_AI_SHOP_ID']==='' || $v['KASSA_AI_API_KEY']==='')) throw new BillingError('Kassa AI включён, но не заполнены ключи.');
        if ($v['RIOPAY_ENABLED']==='1' && $v['RIOPAY_API_TOKEN']==='') throw new BillingError('RioPay включён, но не указан API-токен.');
        if ($v['SEVERPAY_ENABLED']==='1' && ($v['SEVERPAY_MID']==='' || $v['SEVERPAY_TOKEN']==='')) throw new BillingError('SeverPay включён, но не заполнены ключи.');
        if ($v['PAYPEAR_ENABLED']==='1' && ($v['PAYPEAR_SHOP_ID']==='' || $v['PAYPEAR_SECRET_KEY']==='')) throw new BillingError('PayPear включён, но не заполнены ключи.');
        if ($v['ROLLYPAY_ENABLED']==='1' && ($v['ROLLYPAY_API_KEY']==='' || $v['ROLLYPAY_SIGNING_SECRET']==='')) throw new BillingError('RollyPay включён, но не заполнены ключи.');
        if ($v['OVERPAY_ENABLED']==='1' && ($v['OVERPAY_PROJECT_ID']==='' || $v['OVERPAY_PASSWORD']==='')) throw new BillingError('Overpay включён, но не заполнены ключи.');
        if ($v['AURAPAY_ENABLED']==='1' && ($v['AURAPAY_API_KEY']==='' || $v['AURAPAY_SHOP_ID']==='' || $v['AURAPAY_SECRET_KEY']==='')) throw new BillingError('AuraPay включён, но не заполнены ключи.');
        if ($v['ETOPLATEZHI_ENABLED']==='1' && ($v['ETOPLATEZHI_PROJECT_ID']==='' || $v['ETOPLATEZHI_SECRET_KEY']==='')) throw new BillingError('Etoplatezhi включён, но не заполнены ключи.');
        if ($v['ANTILOPAY_ENABLED']==='1' && ($v['ANTILOPAY_SECRET_ID']==='' || $v['ANTILOPAY_PROJECT_ID']==='')) throw new BillingError('Antilopay включён, но не заполнены ключи.');
        if ($v['JUPITER_ENABLED']==='1' && ($v['JUPITER_TOKEN']==='' || $v['JUPITER_SECRET']==='')) throw new BillingError('Jupiter включён, но не заполнены ключи.');
        if ($v['DONUT_ENABLED']==='1' && ($v['DONUT_TOKEN']==='' || $v['DONUT_SECRET']==='')) throw new BillingError('Donut включён, но не заполнены ключи.');
        if ($v['CISPAY_ENABLED']==='1' && ($v['CISPAY_SHOP_ID']==='' || $v['CISPAY_API_KEY']==='')) throw new BillingError('CisPay включён, но не заполнены ключи.');
        if ($v['TABPAY_ENABLED']==='1' && ($v['TABPAY_API_KEY']==='' || $v['TABPAY_WEBHOOK_SECRET']==='')) throw new BillingError('TabPay включён, но не заполнены ключи.');
        if ($v['PARITYPAY_ENABLED']==='1' && ($v['PARITYPAY_SHOP_ID']==='' || $v['PARITYPAY_SECRET_KEY']==='')) throw new BillingError('ParityPay включён, но не заполнены ключи.');
        foreach (['REFERRAL_PROGRAM_ENABLED','REFERRAL_WITHDRAWAL_ENABLED','CABINET_GIFT_ENABLED','TRIAL_ADD_REMAINING_DAYS_TO_PAID','TRIAL_PAYMENT_ENABLED'] as $key) if (!in_array($v[$key],['0','1'],true)) throw new BillingError('Некорректная настройка '.$key);
        foreach (['TRIAL_DURATION_DAYS','TRIAL_TRAFFIC_LIMIT_GB','TRIAL_DEVICE_LIMIT','TRIAL_ACTIVATION_PRICE'] as $key) if (!preg_match('/^[0-9]{1,6}$/D',$v[$key])) throw new BillingError('Некорректная настройка '.$key);
        foreach (['REFERRAL_MINIMUM_TOPUP_KOPEKS','REFERRAL_FIRST_TOPUP_BONUS_KOPEKS','REFERRAL_INVITER_BONUS_KOPEKS','REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS','REFERRAL_WITHDRAWAL_COOLDOWN_DAYS','REFERRAL_WITHDRAWAL_SUSPICIOUS_MIN_DEPOSIT_KOPEKS','REFERRAL_WITHDRAWAL_SUSPICIOUS_MAX_DEPOSITS_PER_MONTH'] as $key) if (!preg_match('/^[0-9]{1,10}$/D',$v[$key])) throw new BillingError('Некорректная настройка '.$key);
        if (!preg_match('/^[0-9]{1,3}$/D',$v['REFERRAL_COMMISSION_PERCENT']) || (int)$v['REFERRAL_COMMISSION_PERCENT']>100) throw new BillingError('Комиссия: 0–100%.');
        if ($v['REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT']!=='' && (!preg_match('/^[0-9]{1,3}$/D',$v['REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT']) || (int)$v['REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT']>100)) throw new BillingError('Комиссия за первый платёж: 0–100%.');
        if ($v['REFERRAL_RECURRING_COMMISSION_TIERS']!=='' && !preg_match('/^([0-9]{1,6}:[0-9]{1,3})(,[0-9]{1,6}:[0-9]{1,3})*$/D',$v['REFERRAL_RECURRING_COMMISSION_TIERS'])) throw new BillingError('Ступени комиссии: формат "0:10,10:15,50:20".');
        if (!preg_match('/^[0-9]{1,2}$/D',$v['AUTORENEW_DAYS_BEFORE']) || (int)$v['AUTORENEW_DAYS_BEFORE']<1 || (int)$v['AUTORENEW_DAYS_BEFORE']>14) throw new BillingError('Автопродление: за сколько дней — от 1 до 14.');
        if (!preg_match('/^[0-9]{1,2}$/D',$v['AUTORENEW_MAX_FAILS']) || (int)$v['AUTORENEW_MAX_FAILS']<1 || (int)$v['AUTORENEW_MAX_FAILS']>10) throw new BillingError('Автопродление: максимум попыток — от 1 до 10.');
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
