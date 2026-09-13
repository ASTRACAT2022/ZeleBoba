<?php
declare(strict_types=1);
namespace Tests;

use App\Identity\{Auth,TelegramWebApp};
use App\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

final class TelegramWebAppTest extends TestCase
{
    public function testSignedInitDataCreatesSessionForTelegramAccount(): void
    {
        $db=new Database('sqlite::memory:');
        $db->migrate(__DIR__.'/../migrations');
        $token='123456:abcdefghijklmnopqrstuvwxyz';
        $data=['auth_date'=>(string)time(),'query_id'=>'AAH-1','user'=>'{"id":123456,"first_name":"Ada"}'];
        ksort($data);
        $check=implode("\n",array_map(static fn($key,$value)=>$key.'='.$value,array_keys($data),$data));
        $secret=hash_hmac('sha256',$token,'WebAppData',true);
        $data['hash']=hash_hmac('sha256',$check,$secret);
        $raw=http_build_query($data,'','&',PHP_QUERY_RFC3986);
        $auth=new Auth($db);
        $session=(new TelegramWebApp($db,$auth,$token))->authenticate($raw);
        self::assertSame('123456',$db->one('SELECT telegram_id FROM users')['telegram_id']);
        self::assertNotNull($auth->session($session));
    }

    public function testRejectsForgedInitData(): void
    {
        $db=new Database('sqlite::memory:');
        $db->migrate(__DIR__.'/../migrations');
        $this->expectException(\App\Billing\BillingError::class);
        (new TelegramWebApp($db,new Auth($db),'token'))->authenticate('auth_date='.time().'&user=%7B%22id%22%3A1%7D&hash='.str_repeat('0',64));
    }
}
