<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Container;
use App\Settings\Branding;
use App\Identity\Auth;
use Symfony\Component\HttpFoundation\Request;
final class BrandingTest extends TestCase
{
    private Container $c;
    private string $uid;
    private string $session;
    private string $dbFile;
    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir().'/zb-brand-'.bin2hex(random_bytes(4)).'.sqlite';
        $config = [
            'APP_ENV' => 'test', 'PURCHASES_ENABLED' => '1', 'APP_URL' => 'http://localhost',
            'DATABASE_DSN' => 'sqlite:'.$this->dbFile, 'DATABASE_USER' => '', 'DATABASE_PASSWORD' => '',
            'PAYMENT_DRIVER' => 'demo', 'PROVISION_DRIVER' => 'demo',
            'YOOKASSA_SHOP_ID' => '', 'YOOKASSA_SECRET' => '',
            'REMNAWAVE_URL' => '', 'REMNAWAVE_TOKEN' => '', 'REMNAWAVE_SQUAD_UUID' => '',
            'TELEGRAM_BOT_TOKEN' => '123456:abcdefghijklmnopqrstuvwxyz', 'TELEGRAM_BOT_USERNAME' => 'example_bot',
            'TELEGRAM_WEBHOOK_SECRET' => str_repeat('s', 32),
        ];
        $this->c = new Container($config);
        $this->c->db->migrate(__DIR__.'/../migrations');
        $this->c->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
        $this->uid = (new Auth($this->c->db))->register('a@example.org','correct-horse-battery');
        $this->session = $this->c->auth->issue($this->uid);
    }
    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }
    private function freshContainer(): Container
    {
        return new Container(['APP_ENV' => 'test', 'DATABASE_DSN' => 'sqlite:'.$this->dbFile, 'DATABASE_USER' => '', 'DATABASE_PASSWORD' => '']);
    }
    public function testDefaults(): void
    {
        $b = new Branding($this->c->config);
        self::assertSame('ZeleBoba', $b->name());
        self::assertSame('#135d45', $b->color());
        self::assertSame('#d8f784', $b->accent());
        self::assertSame('', $b->logo());
        self::assertStringContainsString('дублер', $b->welcomeText());
        self::assertStringContainsString('/plans', $b->helpText());
        self::assertStringContainsString('--green:#135d45', $b->cssVars());
    }
    public function testCustomBrandingApplied(): void
    {
        $this->c->settings->save([
            'SITE_NAME' => 'MyVPN', 'BRAND_LOGO' => 'https://cdn.example.com/logo.png',
            'BRAND_FAVICON' => 'https://cdn.example.com/favicon.ico',
            'BRAND_COLOR' => '#ff0000', 'BRAND_COLOR_ACCENT' => '#00ff00',
            'BRAND_FOOTER_TEXT' => 'Мой сервис', 'BRAND_WELCOME_TEXT' => 'Добро пожаловать в MyVPN!',
            'BRAND_HELP_TEXT' => 'Справка MyVPN',
        ], $this->uid, 0);
        $c2 = $this->freshContainer();
        $b = $c2->branding;
        self::assertSame('MyVPN', $b->name());
        self::assertSame('#ff0000', $b->color());
        self::assertSame('#00ff00', $b->accent());
        self::assertSame('https://cdn.example.com/logo.png', $b->logo());
        self::assertSame('Добро пожаловать в MyVPN!', $b->welcomeText());
        self::assertSame('Справка MyVPN', $b->helpText());
        self::assertStringContainsString('--green:#ff0000', $b->cssVars());
    }
    public function testInvalidBrandingRejected(): void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->c->settings->save(['SITE_NAME' => 'X', 'BRAND_COLOR' => 'red'], $this->uid, 0);
    }
    public function testWebPageUsesBranding(): void
    {
        $this->c->settings->save([
            'SITE_NAME' => 'MyVPN', 'BRAND_LOGO' => 'https://cdn.example.com/logo.png',
            'BRAND_COLOR' => '#ff0000', 'BRAND_COLOR_ACCENT' => '#00ff00', 'BRAND_FOOTER_TEXT' => 'Мой сервис',
        ], $this->uid, 0);
        $c2 = $this->freshContainer();
        $uid = (new Auth($c2->db))->register('b@example.org','correct-horse-battery');
        $session = $c2->auth->issue($uid);
        $web = new \App\Web\Application($c2);
        $r = $web->handle(Request::create('/', 'GET', [], ['zb_session' => $session]));
        $body = $r->getContent();
        self::assertStringContainsString('MyVPN', $body);
        self::assertStringContainsString('https://cdn.example.com/logo.png', $body);
        self::assertStringContainsString('--green:#ff0000', $body);
        self::assertStringContainsString('Мой сервис', $body);
    }
    public function testBotUsesBrandingTexts(): void
    {
        $this->c->settings->save([
            'SITE_NAME' => 'MyVPN', 'BRAND_WELCOME_TEXT' => 'Добро пожаловать в MyVPN!',
            'BRAND_HELP_TEXT' => 'Справка MyVPN',
        ], $this->uid, 0);
        $c2 = $this->freshContainer();
        $c2->telegram->receive(['update_id' => 900001, 'message' => ['from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private'], 'text' => '/start']]);
        $row = $c2->db->one("SELECT payload FROM outbox WHERE topic='telegram.send' ORDER BY rowid DESC LIMIT 1");
        $json = $c2->settings->vault->open('outbox:telegram.send', substr($row['payload'], 4));
        $p = json_decode($json, true);
        self::assertStringContainsString('Добро пожаловать в MyVPN!', $p['text']);
        $c2->telegram->receive(['update_id' => 900002, 'message' => ['from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private'], 'text' => '/help']]);
        $row = $c2->db->one("SELECT payload FROM outbox WHERE topic='telegram.send' ORDER BY rowid DESC LIMIT 1");
        $json = $c2->settings->vault->open('outbox:telegram.send', substr($row['payload'], 4));
        $p = json_decode($json, true);
        self::assertStringContainsString('Справка MyVPN', $p['text']);
    }
}
