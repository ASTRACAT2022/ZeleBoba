<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,RbacService,ReportingService,MonitoringService,BackupService,MaintenanceService};
use App\Identity\Auth;
final class AdminOpsTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private string $uid; private string $adminUid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $auth=new Auth($this->db);
        $this->uid=$auth->register('user@example.org','correct-horse-battery');
        $this->adminUid=$auth->register('admin@example.org','correct-horse-battery');
        $this->db->execute('UPDATE users SET role=? WHERE id=?',['admin',$this->adminUid]);
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    public function testRoleCreateAssignAndPermissions():void
    {
        $rbac=new RbacService($this->db);
        $role=$rbac->createRole('Модератор','Права на пользователей',1,['admin.users','admin.plans'],$this->adminUid);
        self::assertSame('Модератор',$role['name']);
        $rbac->assignRole($this->uid,$role['id'],$this->adminUid);
        $perms=$rbac->permissions($this->uid);
        self::assertContains('admin.users',$perms);
        self::assertNotContains('admin.settings',$perms);
        self::assertTrue($rbac->can($this->uid,'admin.users'));
        self::assertFalse($rbac->can($this->uid,'admin.settings'));
        $rbac->revokeRole($this->uid,$role['id'],$this->adminUid);
        self::assertFalse($rbac->can($this->uid,'admin.users'));
    }
    public function testLegacyAdminHasAllPermissions():void
    {
        $rbac=new RbacService($this->db);
        self::assertTrue($rbac->can($this->adminUid,'admin.settings'));
        self::assertTrue($rbac->can($this->adminUid,'admin.backup'));
    }
    public function testInvalidPermissionRejected():void
    {
        $rbac=new RbacService($this->db);
        $this->expectException(\App\Billing\BillingError::class);
        $rbac->createRole('Bad','',0,['admin.hack'],$this->adminUid);
    }
    public function testAuditLog():void
    {
        $rbac=new RbacService($this->db);
        $rbac->logAction($this->adminUid,'plan.updated','success','plan','basic',['name'=>'x'],'127.0.0.1','UA','POST','/admin/plans/basic');
        $log=$rbac->auditLog();
        self::assertCount(1,$log);
        self::assertSame('plan.updated',$log[0]['action']);
        self::assertSame('127.0.0.1',$log[0]['ip_address']);
    }
    public function testReportingStats():void
    {
        $this->wallet->credit($this->uid,50000,'balance_topup','Пополнение');
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $order=$billing->order($this->uid,'basic','report-key');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $reporting=new ReportingService($this->db);
        $stats=$reporting->salesStats(30);
        self::assertSame(19900,$stats['revenue_kopeks']);
        self::assertSame(1,$stats['orders']);
        $daily=$reporting->dailyRevenue(14);
        self::assertNotEmpty($daily);
    }
    public function testMonitoringLogAndErrors():void
    {
        $mon=new MonitoringService($this->db);
        $mon->log('payment.verify','Платёж проверен',true);
        $mon->recordError('HttpException','Connection refused');
        $mon->recordError('HttpException','Connection refused');
        self::assertCount(1,$mon->recentEvents());
        $errors=$mon->errors();
        self::assertCount(1,$errors);
        self::assertSame(2,(int)$errors[0]['count']);
        $mon->clearErrors();
        self::assertCount(0,$mon->errors());
    }
    public function testBackupCreateAndList():void
    {
        $dir=sys_get_temp_dir().'/zb-backup-'.bin2hex(random_bytes(4));
        $backups=new BackupService($this->db,$dir);
        $file=$backups->create();
        self::assertFileExists($file);
        $list=$backups->list();
        self::assertCount(1,$list);
        $backups->restore(basename($file));
        self::assertCount(2,$this->db->all('SELECT * FROM users'));
        @array_map('unlink',glob($dir.'/*'));
        @rmdir($dir);
    }
    public function testMaintenanceMode():void
    {
        $maint=new MaintenanceService($this->db);
        self::assertFalse($maint->isMaintenance());
        $maint->setMaintenance(true,$this->adminUid);
        self::assertTrue($maint->isMaintenance());
        $maint->setMaintenance(false,$this->adminUid);
        self::assertFalse($maint->isMaintenance());
    }
    public function testPanelStatus():void
    {
        $maint=new MaintenanceService($this->db);
        $maint->setPanelStatus(false,'Connection timeout');
        $status=$maint->panelStatus();
        self::assertFalse($status['ok']);
        self::assertSame('Connection timeout',$status['detail']);
    }
}
