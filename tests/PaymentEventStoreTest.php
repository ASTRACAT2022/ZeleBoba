<?php
declare(strict_types=1);
namespace Tests;
use App\Infrastructure\Database;
use App\Payments\PaymentEventStore;
use PHPUnit\Framework\TestCase;

final class PaymentEventStoreTest extends TestCase
{
    private Database $db;
    private PaymentEventStore $events;

    protected function setUp(): void
    {
        $this->db=new Database('sqlite::memory:');
        $this->db->migrate(__DIR__.'/../migrations');
        $this->events=new PaymentEventStore($this->db);
    }

    public function testOnlyOneConsumerCanClaimAndCompleteAnEvent(): void
    {
        $id=$this->events->receive('demo','evt-1','payment-1',['payment_id'=>'payment-1'],true);
        $first=$this->events->claim($id);
        self::assertNotNull($first);
        self::assertNull($this->events->claim($id));
        $this->events->processed($id,$first['lock_token']);
        self::assertSame('processed',$this->db->one('SELECT status FROM payment_events WHERE id=?',[$id])['status']);
        self::assertSame('processed',$this->db->one("SELECT status FROM incoming_webhooks WHERE provider='demo' AND provider_event_id='evt-1'")['status']);
        self::assertNull($this->events->claim($id));
    }

    public function testFailureProducesDurableRetryState(): void
    {
        $id=$this->events->receive('demo','evt-2','payment-2',['payment_id'=>'payment-2'],true);
        $claimed=$this->events->claim($id);
        $this->events->failed($id,$claimed['lock_token'],new \RuntimeException('provider timeout'));
        $row=$this->db->one('SELECT status,processing_error,next_attempt_at FROM payment_events WHERE id=?',[$id]);
        self::assertSame('retry',$row['status']);
        self::assertSame(\RuntimeException::class,$row['processing_error']);
        self::assertGreaterThanOrEqual(time(),(int)$row['next_attempt_at']);
        self::assertSame('retry',$this->db->one("SELECT status FROM incoming_webhooks WHERE provider='demo' AND provider_event_id='evt-2'")['status']);
    }

    public function testInvalidSignatureIsTerminalInBothInboxes(): void
    {
        $id=$this->events->receive('demo','evt-invalid','payment-3',[],false);
        self::assertSame('dead',$this->db->one('SELECT status FROM payment_events WHERE id=?',[$id])['status']);
        self::assertSame('dead',$this->db->one("SELECT status FROM incoming_webhooks WHERE provider='demo' AND provider_event_id='evt-invalid'")['status']);
        self::assertNull($this->events->claim($id));
    }
}
