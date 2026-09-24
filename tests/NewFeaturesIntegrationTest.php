<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Container;
use App\Infrastructure\Database;
use App\Identity\Auth;
final class NewFeaturesIntegrationTest extends TestCase
{
    private Container $c;
    private string $uid;
    private int $updateId = 500000;
    protected function setUp(): void
    {
        $config = [
            'APP_ENV' => 'test', 'PURCHASES_ENABLED' => '1', 'APP_URL' => 'http://localhost',
            'DATABASE_DSN' => 'sqlite::memory:', 'DATABASE_USER' => '', 'DATABASE_PASSWORD' => '',
            'PAYMENT_DRIVER' => 'demo', 'PROVISION_DRIVER' => 'demo',
            'REMNAWAVE_URL' => '', 'REMNAWAVE_TOKEN' => '', 'REMNAWAVE_SQUAD_UUID' => '',
            'TELEGRAM_BOT_TOKEN' => '123456:abcdefghijklmnopqrstuvwxyz', 'TELEGRAM_BOT_USERNAME' => 'example_bot',
            'TELEGRAM_WEBHOOK_SECRET' => str_repeat('s', 32),
            'CABINET_GIFT_ENABLED' => '1', 'REFERRAL_WITHDRAWAL_ENABLED' => '1',
        ];
        $this->c = new Container($config);
        $this->c->db->migrate(__DIR__.'/../migrations');
        $this->c->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,is_trial_available,trial_duration_days) VALUES('basic','Basic',19900,'RUB',30,0,3,1,1,3)");
        $this->uid = (new Auth($this->c->db))->register('a@example.org','correct-horse-battery');
        $this->c->db->execute('UPDATE users SET telegram_id=? WHERE id=?', ['111', $this->uid]);
    }
    private function send(string $text): array
    {
        $this->c->telegram->receive(['update_id' => ++$this->updateId, 'message' => ['from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private'], 'text' => $text]]);
        $row = $this->c->db->one("SELECT payload FROM outbox WHERE topic='telegram.send' ORDER BY rowid DESC LIMIT 1");
        if (!$row) return [];
        $json = $this->c->settings->vault->open('outbox:telegram.send', substr($row['payload'], 4));
        return json_decode($json, true);
    }
    public function testBalanceCommand(): void
    {
        $p = $this->send('/balance');
        self::assertStringContainsString('Баланс', $p['text']);
        self::assertStringContainsString('0.00', $p['text']);
    }
    public function testTopupCommandWithAmount(): void
    {
        $p = $this->send('/topup 500');
        self::assertStringContainsString('500.00', $p['text']);
        self::assertSame(50000, $this->c->wallet->balance($this->uid)['balance_kopeks']);
    }
    public function testPromoCommand(): void
    {
        $this->c->promocodes->create(['code' => 'SUMMER', 'type' => 'balance', 'balance_bonus_kopeks' => '10000', 'subscription_days' => '0', 'traffic_gb' => '0', 'max_uses' => '5', 'valid_from' => (string)time(), 'valid_until' => '', 'first_purchase_only' => '0', 'plan_id' => ''], $this->uid);
        $p = $this->send('/promo SUMMER');
        self::assertStringContainsString('Баланс пополнен', $p['text']);
        self::assertSame(10000, $this->c->wallet->balance($this->uid)['balance_kopeks']);
        $p2 = $this->send('/promo NOPE');
        self::assertStringContainsString('не найден', $p2['text']);
    }
    public function testReferralCommand(): void
    {
        $p = $this->send('/referral');
        self::assertStringContainsString('Реферальная программа', $p['text']);
        self::assertStringContainsString('ref_', $p['text']);
    }
    public function testGiftCommand(): void
    {
        $p = $this->send('/gift');
        self::assertStringContainsString('Подарки', $p['text']);
    }
    public function testGiftClaimCommand(): void
    {
        $p = $this->send('/gift_claim NOPE');
        self::assertStringContainsString('не найден', $p['text']);
    }
    public function testTrialCommand(): void
    {
        $p = $this->send('/trial basic');
        self::assertStringContainsString('Триал активирован', $p['text']);
        $sub = $this->c->db->one('SELECT * FROM subscriptions');
        self::assertSame('active', $sub['status']);
        self::assertSame(1, (int)$sub['is_trial']);
    }
    public function testStatusSubsOrdersPlansHelp(): void
    {
        foreach (['/status', '/subs', '/orders', '/plans', '/help'] as $cmd) {
            $p = $this->send($cmd);
            self::assertNotEmpty($p['text'], $cmd);
        }
    }
    public function testGiftDeepLinkStart(): void
    {
        $this->c->wallet->credit($this->uid, 50000, 'balance_topup', 'Пополнение');
        $purchase = $this->c->gifts->purchaseFromBalance($this->uid, 'basic', 'gift-int-key', null, null, null, 'bot');
        $code = $this->c->gifts->publicCode($purchase['token']);
        $other = (new Auth($this->c->db))->register('other@example.org','correct-horse-battery');
        $this->c->db->execute('UPDATE users SET telegram_id=? WHERE id=?', ['222', $other]);
        $this->c->telegram->receive(['update_id' => ++$this->updateId, 'message' => ['from' => ['id' => 222], 'chat' => ['id' => 222, 'type' => 'private'], 'text' => '/start '.$code]]);
        $sub = $this->c->db->one('SELECT * FROM subscriptions WHERE user_id=?', [$other]);
        self::assertNotNull($sub);
        self::assertSame('provisioning', $sub['status']);
    }
    public function testReferralDeepLinkStart(): void
    {
        $code = $this->c->referrals->ensureCode($this->uid);
        $other = (new Auth($this->c->db))->register('other2@example.org','correct-horse-battery');
        $this->c->db->execute('UPDATE users SET telegram_id=? WHERE id=?', ['333', $other]);
        $this->c->telegram->receive(['update_id' => ++$this->updateId, 'message' => ['from' => ['id' => 333], 'chat' => ['id' => 333, 'type' => 'private'], 'text' => '/start ref_'.$code]]);
        $user = $this->c->db->one('SELECT referred_by_id FROM users WHERE id=?', [$other]);
        self::assertSame($this->uid, $user['referred_by_id']);
    }
    public function testCampaignDeepLinkStart(): void
    {
        $this->c->campaigns->create(['name' => 'Лето', 'start_parameter' => 'summer2026', 'bonus_type' => 'balance', 'balance_bonus_kopeks' => '10000', 'subscription_duration_days' => '', 'subscription_traffic_gb' => '', 'subscription_device_limit' => '', 'plan_id' => '', 'partner_user_id' => ''], $this->uid);
        $other = (new Auth($this->c->db))->register('other3@example.org','correct-horse-battery');
        $this->c->db->execute('UPDATE users SET telegram_id=? WHERE id=?', ['444', $other]);
        $this->c->telegram->receive(['update_id' => ++$this->updateId, 'message' => ['from' => ['id' => 444], 'chat' => ['id' => 444, 'type' => 'private'], 'text' => '/start summer2026']]);
        self::assertSame(10000, $this->c->wallet->balance($other)['balance_kopeks']);
    }
    public function testWorkerTopicsAllHandled(): void
    {
        $http = new \Symfony\Component\HttpClient\MockHttpClient(fn() => new \Symfony\Component\HttpClient\Response\MockResponse(json_encode(['ok' => true])));
        $worker = new \App\Infrastructure\Worker($this->c->db, $this->c->outbox, $this->c->payments, new \App\Integration\DemoProvisioner(), $http, 'TOKEN', true, 'https://astracattg.netlify.app', $this->c->topups, $this->c->autoPurchase, $this->c->paymentService, $this->c->referrals, $this->c->broadcasts);
        $this->c->wallet->credit($this->uid, 50000, 'balance_topup', 'Пополнение');
        $t = $this->c->topups->create($this->uid, 10000, 'worker-topup-key');
        $worker->handle('topup.create', ['topup_id' => $t['id']]);
        $worker->handle('topup.after', ['user_id' => $this->uid]);
        $worker->handle('referral.topup', ['user_id' => $this->uid, 'amount_kopeks' => 10000]);
        $worker->handle('telegram.send', ['chat_id' => '111', 'text' => 'hi']);
        $worker->handle('telegram.answer', ['callback_query_id' => 'q1']);
        $worker->handle('broadcast.run', ['broadcast_id' => 'none']);
        $worker->handle('gift.create', ['user_id' => $this->uid, 'plan_id' => 'basic', 'price_minor' => 19900, 'currency' => 'RUB']);
        self::assertTrue(true);
    }
    public function testWebPagesAllRender(): void
    {
        $session = $this->c->auth->issue($this->uid);
        $this->c->db->execute("UPDATE users SET role='admin' WHERE id=?", [$this->uid]);
        $this->c->db->execute('UPDATE users SET totp_secret=? WHERE id=?', ['fixture', $this->uid]);
        $this->c->mfa->stepUp($session);
        $web = new \App\Web\Application($this->c);
        $pages = ['/', '/plans', '/orders', '/settings', '/balance', '/referral', '/gifts', '/gifts/claim',
            '/admin', '/admin/plans', '/admin/promocodes', '/admin/broadcasts', '/admin/channels', '/admin/landings',
            '/admin/contests', '/admin/polls', '/admin/campaigns', '/admin/withdrawals', '/admin/reports',
            '/admin/monitoring', '/admin/backups', '/admin/roles', '/admin/audit', '/admin/readiness', '/admin/config', '/admin/users'];
        foreach ($pages as $path) {
            $r = $web->handle(\Symfony\Component\HttpFoundation\Request::create($path, 'GET', [], ['zb_session' => $session]));
            self::assertSame(200, $r->getStatusCode(), $path);
        }
    }
    public function testLandingPageRenders(): void
    {
        $this->c->landings->save(['slug' => 'sale', 'title' => 'Распродажа', 'discount_percent' => '20', 'is_active' => '1', 'subtitle' => '', 'features' => '', 'footer_text' => '', 'allowed_plan_ids' => '', 'payment_methods' => '', 'gift_enabled' => '1', 'custom_css' => '', 'meta_title' => '', 'meta_description' => '', 'display_order' => '0', 'discount_starts_at' => '', 'discount_ends_at' => ''], $this->uid);
        $session = $this->c->auth->issue($this->uid);
        $web = new \App\Web\Application($this->c);
        $r = $web->handle(\Symfony\Component\HttpFoundation\Request::create('/l/sale', 'GET', [], ['zb_session' => $session]));
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Распродажа', $r->getContent());
        self::assertStringContainsString('159', $r->getContent());
        self::assertStringContainsString('было 199', $r->getContent());
    }
}
