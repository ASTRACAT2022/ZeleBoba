<?php
declare(strict_types=1);
namespace App;
use App\Infrastructure\{Database,Outbox,Worker};
use App\Billing\BillingService;
use App\Identity\{Auth,TelegramLogin,Mfa};
use App\Settings\{Settings,Vault};
use App\Integration\{Payments,DemoProvisioner,RemnawaveProvisioner,Telegram};
use Symfony\Component\HttpClient\HttpClient;
final class Container
{
    public readonly Database $db;
    public readonly Outbox $outbox;
    public readonly BillingService $billing;
    public readonly Payments $payments;
    public readonly Worker $worker;
    public readonly Auth $auth;
    public readonly Telegram $telegram;
    public readonly TelegramLogin $telegramLogin;
    public readonly Mfa $mfa;
    public readonly Settings $settings;
    public readonly array $config;
    public function __construct(array $config)
    {
        $this->db=new Database($config['DATABASE_DSN'],$config['DATABASE_USER']??'',$config['DATABASE_PASSWORD']??'');
        $this->settings=new Settings($this->db,new Vault(dirname(__DIR__).'/var/master.key'));
        $this->config=$config=array_merge(Settings::DEFAULTS,$config,$this->settings->overrides());
        if (!in_array($config['APP_ENV'],['dev','test','prod'],true)) throw new \RuntimeException('Invalid APP_ENV');
        if (!in_array($config['PAYMENT_DRIVER'],['demo','yookassa','freekassa'],true) || !in_array($config['PROVISION_DRIVER'],['demo','remnawave'],true)) throw new \RuntimeException('Unknown integration driver');
        if ($config['APP_ENV']==='prod' && (!$this->db->postgres() || !str_starts_with($config['APP_URL'],'https://'))) throw new \RuntimeException('Production requires PostgreSQL and HTTPS');
        $this->outbox=new Outbox($this->db,$this->settings->vault);
        $this->billing=new BillingService($this->db,$this->outbox,$config['PAYMENT_DRIVER'],$config);
        $http=HttpClient::create();
        $this->payments=new Payments($this->db,$this->billing,$http,$config);
        $tgBase=rtrim($config['TELEGRAM_API_BASE']??'https://astracattg.netlify.app','/');
        if ($tgBase==='') $tgBase='https://astracattg.netlify.app';
        $this->worker=new Worker($this->db,$this->outbox,$this->payments,new RemnawaveProvisioner($http,$config['REMNAWAVE_URL'],$config['REMNAWAVE_TOKEN'],$config['REMNAWAVE_SQUAD_UUID']),$http,$config['TELEGRAM_BOT_TOKEN'],$config['APP_ENV']!=='prod',$tgBase);
        $this->auth=new Auth($this->db);$this->mfa=new Mfa($this->db,$this->settings->vault);
        $this->telegramLogin=new TelegramLogin($this->db,$this->auth);
        $this->telegram=new Telegram($this->db,$this->outbox,$this->billing,$config['APP_URL'],$this->telegramLogin,$tgBase);
    }
}
