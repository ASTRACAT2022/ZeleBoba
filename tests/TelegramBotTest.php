<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox,Worker};
use App\Billing\BillingService;
use App\Identity\{Auth,TelegramLogin};
use App\Integration\{Telegram,Payments,DemoProvisioner};
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
final class TelegramBotTest extends TestCase
{
    private Database $db; private Outbox $outbox; private BillingService $billing; private TelegramLogin $login; private Telegram $bot;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);$this->billing=new BillingService($this->db,$this->outbox,'demo');
        $this->login=new TelegramLogin($this->db,new Auth($this->db));
        $this->bot=new Telegram($this->db,$this->outbox,$this->billing,'https://cabinet.example',$this->login,'https://astracattg.netlify.app');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    private function lastPayload(): array
    {
        $row=$this->db->one("SELECT payload FROM outbox WHERE topic='telegram.send' ORDER BY created_at DESC LIMIT 1");
        if(!$row) return [];
        $json=$row['payload'];
        // Outbox may be encrypted in some setups; here it's plain JSON
        if(str_starts_with($json,'enc:')) return [];
        return json_decode($json,true)??[];
    }
    public function testApiBaseDefaultsToMirror(): void
    {
        self::assertSame('https://astracattg.netlify.app',$this->bot->apiBase());
        $custom=new Telegram($this->db,$this->outbox,$this->billing,'https://cabinet.example',$this->login,'https://mirror.example/');
        self::assertSame('https://mirror.example',$custom->apiBase());
    }
    public function testStartShowsCabinetMirrorMenu(): void
    {
        $this->bot->receive(['update_id'=>100,'message'=>['from'=>['id'=>111],'chat'=>['id'=>111,'type'=>'private'],'text'=>'/start']]);
        $job=$this->lastPayload();
        self::assertStringContainsString('дублер',$job['text']);
        self::assertStringContainsString('https://cabinet.example',$job['text']);
        self::assertNotEmpty($job['reply_markup']['inline_keyboard']);
    }
    public function testPlansListsWithBuyButtons(): void
    {
        $this->bot->receive(['update_id'=>101,'message'=>['from'=>['id'=>111],'chat'=>['id'=>111,'type'=>'private'],'text'=>'/plans']]);
        $job=$this->lastPayload();
        self::assertStringContainsString('Basic',$job['text']);
        self::assertStringContainsString('199.00',$job['text']);
        $buttons=array_merge(...$job['reply_markup']['inline_keyboard']);
        $buy=array_filter($buttons,fn($b)=>str_starts_with($b['callback_data']??'','buy:'));
        self::assertNotEmpty($buy);
    }
    public function testBuyCreatesOrderSharedWithWeb(): void
    {
        $this->bot->receive(['update_id'=>102,'message'=>['from'=>['id'=>222],'chat'=>['id'=>222,'type'=>'private'],'text'=>'/buy basic']]);
        $orders=$this->db->all('SELECT * FROM orders');
        self::assertCount(1,$orders);
        self::assertSame('demo',$orders[0]['provider']);
        // Same order visible via web query path (user_id match)
        $user=$this->db->one('SELECT * FROM users WHERE telegram_id=?',['222']);
        self::assertSame($user['id'],$orders[0]['user_id']);
        $job=$this->lastPayload();
        self::assertStringContainsString('Заказ',$job['text']);
        self::assertStringContainsString('/orders/'.$orders[0]['id'],$job['text']);
    }
    public function testCallbackBuyAndOrderRefresh(): void
    {
        // Direct callback buy without prior /start to avoid lastPayload picking welcome message
        $this->bot->receive(['update_id'=>104,'callback_query'=>['id'=>'q1','data'=>'buy:basic','from'=>['id'=>333],'message'=>['chat'=>['id'=>333,'type'=>'private']]]]);
        $orders=$this->db->all('SELECT * FROM orders');
        self::assertCount(1,$orders);
        // answerCallbackQuery enqueued via mirror
        $answers=$this->db->all("SELECT * FROM outbox WHERE topic='telegram.answer'");
        self::assertCount(1,$answers);
        $orderId=$orders[0]['id'];
        $this->bot->receive(['update_id'=>105,'callback_query'=>['id'=>'q2','data'=>'order:'.$orderId,'from'=>['id'=>333],'message'=>['chat'=>['id'=>333,'type'=>'private']]]]);
        $job=$this->lastPayload();
        self::assertStringContainsString('Заказ',$job['text']);
    }
    public function testStatusShowsSubsAndPendingOrders(): void
    {
        $this->bot->receive(['update_id'=>106,'message'=>['from'=>['id'=>444],'chat'=>['id'=>444,'type'=>'private'],'text'=>'/buy basic']]);
        $this->bot->receive(['update_id'=>107,'message'=>['from'=>['id'=>444],'chat'=>['id'=>444,'type'=>'private'],'text'=>'/status']]);
        $job=$this->lastPayload();
        // Demo driver settles immediately, so /status shows the subscription (tandem with web)
        self::assertTrue(str_contains($job['text'],'Подписка')||str_contains($job['text'],'подписк'));
    }
    public function testWorkerSendsViaMirror(): void
    {
        $http=new MockHttpClient(function($method,$url,$options){
            self::assertStringStartsWith('https://astracattg.netlify.app/botTOKEN/sendMessage',$url);
            return new MockResponse(json_encode(['ok'=>true]));
        });
        $payments=new Payments($this->db,$this->billing,$http,[]);
        $worker=new Worker($this->db,$this->outbox,$payments,new DemoProvisioner(),$http,'TOKEN',true,'https://astracattg.netlify.app');
        $worker->handle('telegram.send',['chat_id'=>'111','text'=>'hi']);
        $http2=new MockHttpClient(function($method,$url)use(&$called){
            $called=$url;
            return new MockResponse(json_encode(['ok'=>true]));
        });
        $worker2=new Worker($this->db,$this->outbox,$payments,new DemoProvisioner(),$http2,'TOKEN',true,'https://astracattg.netlify.app');
        $worker2->handle('telegram.answer',['callback_query_id'=>'q1']);
        self::assertStringContainsString('/botTOKEN/answerCallbackQuery',$called);
    }
    public function testGroupMessagesIgnored(): void
    {
        $this->bot->receive(['update_id'=>108,'message'=>['from'=>['id'=>555],'chat'=>['id'=>999,'type'=>'group'],'text'=>'/buy basic']]);
        self::assertCount(0,$this->db->all('SELECT * FROM orders'));
    }
}
