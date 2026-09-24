<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\Database;
use App\Billing\BillingService;
use App\Integration\RemnawaveSync;
use App\Integration\RemnawaveProvisioner;
use App\Observability\InvestigationService;
use App\Infrastructure\CircuitBreaker;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};

final class RemnawaveSyncTest extends TestCase
{
    private Database $db;
    protected function setUp(): void
    {
        $this->db=new Database('sqlite::memory:');
        $this->db->migrate(__DIR__.'/../migrations');
        $this->db->execute("INSERT INTO users(id,email,created_at) VALUES('u','u@example.test',0)");
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('p','Plan',19900,'RUB',30,0,3)");
    }
    private function seedActiveSubscription(int $expiresAt): array
    {
        $billing=new BillingService($this->db,new \App\Infrastructure\Outbox($this->db),'demo');
        $order=$billing->order('u','p','key-'.bin2hex(random_bytes(4)));
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $this->db->execute("UPDATE subscriptions SET status='active',expires_at=? WHERE id=?",[$expiresAt,$sub['id']]);
        return $this->db->one('SELECT * FROM subscriptions WHERE id=?',[$sub['id']]);
    }
    public function testSyncFixesExpiryDrift(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $expiry=time()+35*86400;
        $http=new MockHttpClient(function($method,$url,$options)use($sub,&$expiry){
            if($method==='PATCH'){
                $body=json_decode((string)$options['body'],true,512,JSON_THROW_ON_ERROR);
                $expiry=strtotime($body['expireAt']);
                return new MockResponse('{}');
            }
            return new MockResponse(json_encode(['response'=>[
                'id'=>100,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE',
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiry),
                'trafficLimitBytes'=>0,'hwidDeviceLimit'=>3,'subscriptionUrl'=>'https://sub.example/k',
            ]]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $sync=new RemnawaveSync($this->db,$p);
        $report=$sync->run(10,true);
        self::assertSame(1,$report['checked']);
        self::assertSame(1,$report['fixed']);
        self::assertSame(0,$report['errors']);
    }
    public function testLegacySyncUsesPanelIdWithoutCreatingDuplicate(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $this->db->execute("UPDATE subscriptions SET remnawave_id=777,remote_id='777' WHERE id=?",[$sub['id']]);
        $expiry=time()+25*86400;$calls=[];
        $http=new MockHttpClient(function($method,$url,$options)use($sub,&$expiry,&$calls){
            $calls[]=$method.' '.$url;
            if($method==='PATCH'){$body=json_decode((string)$options['body'],true,512,JSON_THROW_ON_ERROR);self::assertSame(777,$body['id']);$expiry=strtotime($body['expireAt']);return new MockResponse('{}');}
            self::assertStringEndsWith('/api/users/777',$url);
            return new MockResponse(json_encode(['response'=>['id'=>777,'username'=>'mack_6666','status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiry),'trafficLimitBytes'=>0,'hwidDeviceLimit'=>3]]));
        });
        $report=(new RemnawaveSync($this->db,new RemnawaveProvisioner($http,'https://panel.example','token','squad')))->run(10,true);
        self::assertSame(1,$report['fixed']);
        self::assertSame(0,$report['reprovisioned']);
        self::assertCount(3,$calls);
    }
    public function testSyncRecoversStalePanelIdThroughCanonicalUsername(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $this->db->execute("UPDATE subscriptions SET remnawave_id=777,remote_id='777' WHERE id=?",[$sub['id']]);
        $http=new MockHttpClient(function($method,$url)use($sub){
            self::assertSame('GET',$method);
            if(str_ends_with($url,'/api/users/777'))return new MockResponse('{}',['http_code'=>404]);
            self::assertStringEndsWith('/api/users/by-username/zb_'.$sub['id'],$url);
            return new MockResponse(json_encode(['response'=>['id'=>999,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$sub['expires_at']),'trafficLimitBytes'=>0,'hwidDeviceLimit'=>3,'subscriptionUrl'=>'https://sub.example/new']]));
        });
        $report=(new RemnawaveSync($this->db,new RemnawaveProvisioner($http,'https://panel.example','token','squad')))->run(10,true);
        self::assertSame(0,$report['reprovisioned']);
        self::assertSame(0,$report['errors']);
        self::assertSame(999,(int)$this->db->one('SELECT remnawave_id FROM subscriptions WHERE id=?',[$sub['id']])['remnawave_id']);
    }
    public function testInvestigationShowsPanelExpiryMismatch(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $this->db->execute('UPDATE subscriptions SET remnawave_id=777 WHERE id=?',[$sub['id']]);
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['response'=>['id'=>777,'status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',time()+27*86400),'trafficLimitBytes'=>0]])));
        $result=(new InvestigationService($this->db,new RemnawaveProvisioner($http,'https://panel.example','token','squad')))->expectedActual($sub['id']);
        self::assertSame(0,$result['freshness']);
        self::assertFalse($result['rows'][0]['ok']);
    }
    public function testSyncPreservesPurchasedTrafficAndDeviceAddons(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $this->db->execute('UPDATE subscriptions SET traffic_limit_gb=10,purchased_traffic_gb=20,device_limit=5 WHERE id=?',[$sub['id']]);
        $patched=null;
        $http=new MockHttpClient(function($method,$url,$options)use($sub,&$patched){
            if($method==='PATCH') {
                $patched=json_decode((string)$options['body'],true,512,JSON_THROW_ON_ERROR);
                return new MockResponse('{}');
            }
            return new MockResponse(json_encode(['response'=>[
                'id'=>100,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE',
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$sub['expires_at']),
                'trafficLimitBytes'=>10*1073741824,'hwidDeviceLimit'=>3,'subscriptionUrl'=>'https://sub.example/k',
            ]]));
        });
        $report=(new RemnawaveSync($this->db,new RemnawaveProvisioner($http,'https://panel.example','token','squad')))->run(10,true);
        self::assertSame(1,$report['fixed']);
        self::assertSame(30*1073741824,$patched['trafficLimitBytes']);
        self::assertSame(5,$patched['hwidDeviceLimit']);
    }
    public function testSyncPreservesUnlimitedDeviceSetting(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $this->db->execute('UPDATE subscriptions SET device_limit=0 WHERE id=?',[$sub['id']]);
        $patched=null;
        $http=new MockHttpClient(function($method,$url,$options)use($sub,&$patched){
            if($method==='PATCH') {
                $patched=json_decode((string)$options['body'],true,512,JSON_THROW_ON_ERROR);
                return new MockResponse('{}');
            }
            return new MockResponse(json_encode(['response'=>[
                'id'=>100,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE',
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$sub['expires_at']),
                'trafficLimitBytes'=>0,'hwidDeviceLimit'=>3,'subscriptionUrl'=>'https://sub.example/k',
            ]]));
        });
        $report=(new RemnawaveSync($this->db,new RemnawaveProvisioner($http,'https://panel.example','token','squad')))->run(10,true);
        self::assertSame(1,$report['fixed']);
        self::assertSame(0,$patched['hwidDeviceLimit']);
    }
    public function testListActiveUsersFailsIfLaterPageFails(): void
    {
        $calls=0;
        $http=new MockHttpClient(function()use(&$calls){
            $calls++;
            if($calls===2) return new MockResponse('{}',['http_code'=>503]);
            return new MockResponse(json_encode(['response'=>['users'=>[['id'=>1,'status'=>'ACTIVE']],'total'=>2]]));
        });
        $this->expectException(\RuntimeException::class);
        (new RemnawaveProvisioner($http,'https://panel.example','token','squad'))->listActiveUsers(1);
    }
    public function testPatchFailureIsReportedInsteadOfMarkedSynced(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $this->db->execute('UPDATE subscriptions SET traffic_limit_gb=10 WHERE id=?',[$sub['id']]);
        $http=new MockHttpClient(function($method)use($sub){
            if($method==='PATCH') return new MockResponse('failure',['http_code'=>503]);
            return new MockResponse(json_encode(['response'=>[
                'id'=>100,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE',
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$sub['expires_at']),
                'trafficLimitBytes'=>0,'hwidDeviceLimit'=>3,'subscriptionUrl'=>'https://sub.example/k',
            ]]));
        });
        $report=(new RemnawaveSync($this->db,new RemnawaveProvisioner($http,'https://panel.example','token','squad')))->run(10,true);
        self::assertSame(0,$report['fixed']);
        self::assertSame(1,$report['errors']);
    }
    public function testExpectedMissingUserDoesNotTripCircuitBreaker(): void
    {
        $http=new MockHttpClient(function($method){
            if($method==='GET') return new MockResponse('{}',['http_code'=>404]);
            return new MockResponse(json_encode(['response'=>[
                'id'=>123,'username'=>'zb_new','subscriptionUrl'=>'https://sub.example/new',
            ]]));
        });
        $breaker=new CircuitBreaker($this->db,1,300);
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad',$breaker);
        $result=$p->provision(['id'=>'new','expires_at'=>time()+86400,'traffic_bytes'=>0,'devices'=>1]);
        self::assertSame('123',$result['id']);
        self::assertSame(CircuitBreaker::CLOSED,$breaker->state('remnawave_api')['state']);
    }
    public function testSyncReprovisionsMissingUser(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $calls=0;
        $http=new MockHttpClient(function($method,$url)use(&$calls,$sub){
            $calls++;
            if($method==='GET') return new MockResponse(json_encode(['response'=>null]),['http_code'=>404]);
            self::assertSame('POST',$method);
            return new MockResponse(json_encode(['response'=>['id'=>200,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE','expireAt'=>'2026-10-10T00:00:00.000Z','subscriptionUrl'=>'https://sub.example/new']]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $sync=new RemnawaveSync($this->db,$p);
        $report=$sync->run(10,true);
        self::assertSame(1,$report['reprovisioned']);
        self::assertSame('200',$this->db->one('SELECT remote_id FROM subscriptions')['remote_id']);
    }
    public function testSyncDisablesExpiredOnPanel(): void
    {
        $sub=$this->seedActiveSubscription(time()-100);
        $this->db->execute("UPDATE subscriptions SET status='expired' WHERE id=?",[$sub['id']]);
        $http=new MockHttpClient(function($method,$url)use($sub){
            if($method==='GET') return new MockResponse(json_encode(['response'=>['id'=>300,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE','expireAt'=>'2020-01-01T00:00:00.000Z']]));
            self::assertSame('PATCH',$method);
            return new MockResponse(json_encode(['response'=>['id'=>300,'status'=>'DISABLED']]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $sync=new RemnawaveSync($this->db,$p);
        $report=$sync->run(10,true);
        self::assertSame(1,$report['disabled']);
    }
    public function testSyncDryRunDoesNotModify(): void
    {
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['response'=>[
            'id'=>400,'username'=>'zb_'.$sub['id'],'status'=>'ACTIVE',
            'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',time()+35*86400),
            'trafficLimitBytes'=>0,'hwidDeviceLimit'=>3,'subscriptionUrl'=>'https://sub.example/k',
        ]])));
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $sync=new RemnawaveSync($this->db,$p);
        $report=$sync->run(10,false);
        self::assertSame(1,$report['checked']);
        self::assertSame(0,$report['fixed']);
        self::assertSame('',(string)$this->db->one('SELECT remote_id FROM subscriptions')['remote_id']);
    }
    public function testRunIncludesActiveSubscriptionWithoutOrder(): void
    {
        $now=time();
        $id=Database::id();
        $this->db->execute(
            "INSERT INTO subscriptions(id,order_id,user_id,plan_id,status,expires_at,created_at,traffic_limit_gb,purchased_traffic_gb,device_limit,lifecycle_status,start_date,traffic_limit_bytes) VALUES(?,NULL,?,'p','active',?,?,?,?,?,'active',?,?)",
            [$id,'u',$now+86400,$now,5,2,3,$now,7*1073741824]
        );
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['response'=>[
            'id'=>500,'username'=>'zb_'.$id,'status'=>'ACTIVE',
            'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$now+86400),
            'trafficLimitBytes'=>7*1073741824,'hwidDeviceLimit'=>3,'subscriptionUrl'=>'https://sub.example/imported',
        ]])));
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $sync=new RemnawaveSync($this->db,$p);
        $report=$sync->run(10,true);
        self::assertSame(1,$report['checked']);
        self::assertSame(0,$report['fixed']);
        self::assertSame(0,$report['missing']);
    }
    public function testImportMissingSkipsExistingRemoteId(): void
    {
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['42','u']);
        $sub=$this->seedActiveSubscription(time()+30*86400);
        $this->db->execute('UPDATE subscriptions SET remote_id=?,remnawave_id=NULL WHERE id=?',['777',$sub['id']]);
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['response'=>['users'=>[
            ['id'=>777,'status'=>'ACTIVE','telegramId'=>'42','expireAt'=>'2030-01-01T00:00:00Z','trafficLimitBytes'=>0,'hwidDeviceLimit'=>3],
        ],'total'=>1]])));
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $sync=new RemnawaveSync($this->db,$p);
        $report=$sync->importMissing(200,true);
        self::assertSame(1,$report['scanned']);
        self::assertSame(0,$report['imported']);
        self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) AS c FROM subscriptions')['c']);
    }
    public function testImportMissingCreatesRemnawaveProvisioningAccount(): void
    {
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['43','u']);
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['response'=>['users'=>[
            ['id'=>888,'status'=>'ACTIVE','telegram_id'=>'43','expireAt'=>'2030-01-01T00:00:00Z','trafficLimitBytes'=>10*1073741824,'hwidDeviceLimit'=>2,'shortUuid'=>'short','subscriptionUrl'=>'https://sub.example/888','vlessUuid'=>'uuid'],
        ],'total'=>1]])));
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $sync=new RemnawaveSync($this->db,$p);
        $report=$sync->importMissing(200,true);
        self::assertSame(1,$report['imported']);
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE remnawave_id=?',[888]);
        self::assertNotNull($sub);
        self::assertSame('888',(string)$sub['remote_id']);
        self::assertSame('remnawave',$this->db->one('SELECT provider FROM provisioning_accounts WHERE subscription_id=?',[$sub['id']])['provider']);
    }
    public function testImportMissingRollsBackSubscriptionWhenAccountInsertFails(): void
    {
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['43','u']);
        $this->db->execute("CREATE TRIGGER reject_import_account BEFORE INSERT ON provisioning_accounts BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['response'=>['users'=>[
            ['id'=>889,'status'=>'ACTIVE','telegram_id'=>'43','expireAt'=>'2030-01-01T00:00:00Z','trafficLimitBytes'=>0,'hwidDeviceLimit'=>0],
        ],'total'=>1]])));
        $report=(new RemnawaveSync($this->db,new RemnawaveProvisioner($http,'https://panel.example','token','squad')))->importMissing(200,true);
        self::assertSame(1,$report['errors']);
        self::assertNull($this->db->one('SELECT id FROM subscriptions WHERE remnawave_id=?',[889]));
    }
    public function testListActiveUsersPaginatesAllPages(): void
    {
        $requests=0;
        $http=new MockHttpClient(function($method,$url)use(&$requests){
            $requests++;
            $parts=parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $page=(int)($query['page'] ?? 1);
            $pageSize=(int)($query['pageSize'] ?? 200);
            $start=($page-1)*$pageSize+1;
            $end=min($start+$pageSize-1,250);
            $users=[];
            for($i=$start;$i<=$end;$i++){
                $users[]=['id'=>$i,'status'=>'ACTIVE'];
            }
            return new MockResponse(json_encode(['response'=>['users'=>$users,'total'=>250]]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $users=$p->listActiveUsers(200);
        self::assertCount(250,$users);
        self::assertSame(2,$requests);
    }
}
