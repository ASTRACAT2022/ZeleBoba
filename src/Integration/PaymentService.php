<?php
declare(strict_types=1);
namespace App\Integration;
use App\Billing\{BillingError,BillingService};
use App\Infrastructure\{Database,JobDeferred};
use App\Infrastructure\WebhookGuard;
use App\Integration\Payment\ProviderRegistry;
use App\Payments\PaymentEventStore;
use App\Payments\PaymentAttemptStore;
use App\Observability\OperationsService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
/**
 * Unified payment service. Routes order/topup checkouts and verification
 * through the provider registry. Keeps the legacy Payments API for
 * compatibility while delegating to providers.
 */
final class PaymentService
{
    public function __construct(
        private Database $db,
        private BillingService $billing,
        private HttpClientInterface $http,
        private array $config,
        private ProviderRegistry $registry,
        private ?PaymentEventStore $events=null,
        private ?WebhookGuard $webhookGuard=null,
        private ?\App\Integration\Mailer $mailer=null,
        private ?PaymentAttemptStore $attempts=null,
    ) {}
    public function registry(): ProviderRegistry { return $this->registry; }
    /** Create checkout for an order. Returns [payment_id, checkout_url]. */
    public function createOrder(string $orderId): array
    {
        $order = $this->db->one('SELECT * FROM orders WHERE id=?', [$orderId]);
        if (!$order || $order['status'] !== 'pending' || $order['checkout_url']) return ['payment_id' => $order['provider_payment_id'] ?? '', 'checkout_url' => $order['checkout_url'] ?? ''];
        $attempt=$this->attempts?->begin('order',$order);
        if ($attempt && !($attempt['created']??false)) {
            if (!empty($attempt['provider_payment_id']) && !empty($attempt['checkout_url'])) {
                // A prior worker owns this durable attempt and completed the
                // provider call. Restore the legacy projection if needed.
                $this->db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=? AND checkout_url IS NULL',[$attempt['provider_payment_id'],$attempt['checkout_url'],$orderId]);
                return ['payment_id'=>$attempt['provider_payment_id'],'checkout_url'=>$attempt['checkout_url']];
            }
            // Do not issue a second mutating provider request while its result
            // is unknown. Recovery/verification must resolve the first intent.
            throw new JobDeferred(60);
        }
        if ($order['provider'] === 'demo') {
            if (($this->config['APP_ENV'] ?? 'dev') === 'prod') throw new BillingError('Демоплатёж запрещён.');
            $this->db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=?',['demo_'.$orderId,'/orders/'.$orderId,$orderId]);
            if($attempt)$this->attempts->attached($attempt['id'],'demo_'.$orderId,'/orders/'.$orderId);
            return ['payment_id' => 'demo_'.$orderId, 'checkout_url' => '/orders/'.$orderId];
        }
        $this->requireCheckoutSupport($order['provider']);
        $provider = $this->registry->get($order['provider']);
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$order['user_id']]);
        try {$result = $provider->createOrder($order, $user ?? []);} catch (\Throwable $e) { if($attempt)$this->attempts->unknown($attempt['id'],$e); throw $e; }
        $this->db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)', [$result['payment_id'], $result['checkout_url'], $orderId, $result['payment_id']]);
        if($attempt)$this->attempts->attached($attempt['id'],$result['payment_id'],$result['checkout_url']);
        return $result;
    }
    /** Create checkout for a topup. Returns [payment_id, checkout_url]. */
    public function createTopup(string $topupId): array
    {
        $topup = $this->db->one('SELECT * FROM topups WHERE id=?', [$topupId]);
        if (!$topup || $topup['status'] !== 'pending' || $topup['checkout_url']) return ['payment_id' => $topup['provider_payment_id'] ?? '', 'checkout_url' => $topup['checkout_url'] ?? ''];
        $attempt=$this->attempts?->begin('topup',$topup);
        if ($attempt && !($attempt['created']??false)) {
            if (!empty($attempt['provider_payment_id']) && !empty($attempt['checkout_url'])) {
                $this->db->execute('UPDATE topups SET provider_payment_id=?,checkout_url=? WHERE id=? AND checkout_url IS NULL',[$attempt['provider_payment_id'],$attempt['checkout_url'],$topupId]);
                return ['payment_id'=>$attempt['provider_payment_id'],'checkout_url'=>$attempt['checkout_url']];
            }
            throw new JobDeferred(60);
        }
        if ($topup['provider'] === 'demo') {
            if (($this->config['APP_ENV'] ?? 'dev') === 'prod') throw new BillingError('Демоплатёж запрещён.');
            $this->db->execute('UPDATE topups SET provider_payment_id=?,checkout_url=? WHERE id=?',['demo_'.$topupId,'/balance/topup/'.$topupId,$topupId]);
            if($attempt)$this->attempts->attached($attempt['id'],'demo_'.$topupId,'/balance/topup/'.$topupId);
            return ['payment_id' => 'demo_'.$topupId, 'checkout_url' => '/balance/topup/'.$topupId];
        }
        $this->requireCheckoutSupport($topup['provider']);
        $provider = $this->registry->get($topup['provider']);
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$topup['user_id']]);
        try {$result = $provider->createTopup($topup, $user ?? []);} catch (\Throwable $e) { if($attempt)$this->attempts->unknown($attempt['id'],$e); throw $e; }
        $this->db->execute('UPDATE topups SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)', [$result['payment_id'], $result['checkout_url'], $topupId, $result['payment_id']]);
        if($attempt)$this->attempts->attached($attempt['id'],$result['payment_id'],$result['checkout_url']);
        return $result;
    }
    private function requireCheckoutSupport(string $provider): void
    {
        // Only Platega is supported after the provider cleanup.
    }
    /** Verify a payment with the provider and settle if paid. */
    public function verify(string $paymentId, ?string $providerId=null, ?string $correlationId=null): void
    {
        if ($paymentId==='' || strlen($paymentId)>100) throw new BillingError('Некорректный платёж.');
        $params=[$paymentId,$paymentId];
        // Keep the provider restriction around *both* lookup alternatives.
        // Without parentheses SQL applies AND only to `id=?`, allowing a
        // colliding provider_payment_id from another provider to be selected.
        $scope=$providerId!==null?' AND provider=?':'';
        if ($providerId!==null) $params[]=$providerId;
        $orders=$this->db->all("SELECT * FROM orders WHERE (provider_payment_id=? OR id=?)".$scope,$params);
        $topups=$this->db->all("SELECT * FROM topups WHERE (provider_payment_id=? OR id=?)".$scope,$params);
        if (count($orders)+count($topups)>1) throw new BillingError('Неоднозначная привязка платежа.');
        $entity=$orders[0]??$topups[0]??null;
        $providerId??=$entity['provider']??null;
        if ($providerId===null || $providerId==='demo') return;
        $result=$this->registry->get($providerId)->verify($paymentId);
        if (!in_array($result['status']??'',['paid','canceled'],true)) {
            if ($correlationId!==null) throw new JobDeferred(60);
            return;
        }
        $metadata=$result['metadata']??[];
        // Recover a checkout that succeeded remotely before its id was stored locally.
        if (!$entity && $providerId==='platega') {
            $orderId=$metadata['order_id']??$metadata['merchant_order_id']??'';
            $topupId=$metadata['topup_id']??$metadata['merchant_order_id']??'';
            $order=$this->db->one('SELECT * FROM orders WHERE id=? AND provider=?',[$orderId,$providerId]);
            $topup=$this->db->one('SELECT * FROM topups WHERE id=? AND provider=?',[$topupId,$providerId]);
            if ($order && $topup) throw new BillingError('Неоднозначная привязка платежа.');
            $entity=$order??$topup;
            $orders=$order?[$order]:[];
        }
        if (!$entity) throw new BillingError('Платёж пока не привязан к локальному заказу. Требуется сверка.');
        $isOrder=$orders!==[];
        // When the entity was resolved by its own stored provider_payment_id,
        // the binding is already authoritative (payment belongs to this entity).
        // Metadata-id checks only matter for the recover-checkout path (entity
        // matched via metadata, not by provider_payment_id). A canceled/expired
        // Platega tx returns no orderId, so an empty metadata id must not reject
        // a topup/order whose provider_payment_id already equals the payment.
        $boundById = ($entity['provider_payment_id'] ?? null) === (string)$paymentId;
        $expected = $metadata[$isOrder ? 'order_id' : 'topup_id'] ?? null;
        if (!$boundById
            && $providerId==='platega'
            && $expected !== $entity['id']) throw new BillingError('Платёж относится к другому заказу.');
        $account=match($providerId){'platega'=>($this->config['PLATEGA_MERCHANT_ID']??''),default=>''};
        if (isset($entity['provider_account']) && $entity['provider_account']!=='' && $entity['provider_account']!==$account) throw new BillingError('Несовпадение магазина.');
        $actualId=$result['payment_id']??'';
        if (!is_string($actualId) || $actualId==='' || strlen($actualId)>100 || ($entity['provider_payment_id']!==null && $entity['provider_payment_id']!==$actualId)) throw new BillingError('Несовпадение платежа.');
        if (($result['status']??'')==='paid') {
            if (($this->config['APP_ENV']??'dev')==='prod' && !empty($result['test'])) throw new BillingError('Тестовый платёж запрещён в production.');
            if ($isOrder) $this->billing->settle($entity['id'],$providerId,$actualId,(int)$result['amount_kopeks'],$result['currency'],$correlationId);
            else $this->billing->settleTopup($entity['id'],$providerId,$actualId,(int)$result['amount_kopeks'],$result['currency']);
            $this->attempts?->completed($providerId,$actualId,'paid');
        } elseif (($result['status']??'')==='canceled') {
            $table=$isOrder?'orders':'topups';
            $this->db->execute("UPDATE ".$table." SET status='canceled' WHERE id=? AND provider=? AND status='pending'",[$entity['id'],$providerId]);
            $this->attempts?->completed($providerId,$actualId,'canceled');
        }
    }
    /** Handle a provider webhook. Returns true if the webhook was ours. */
    public function handleWebhook(string $providerId, \Symfony\Component\HttpFoundation\Request $request): bool
    {
        if (!$this->registry->has($providerId)) return false;
        $provider = $this->registry->get($providerId);
        // A webhook must never wake a retry loop for a provider whose own
        // verification credentials are absent. Bad JSON is an invalid
        // delivery, not an application error worthy of a 500 response.
        if (!$provider->configured()) return false;
        try {
            $result = $provider->handleWebhook($request);
        } catch (\Throwable) {
            return false;
        }
        if ($result === null) return false;
        $paymentId = $result['payment_id'];
        $status = $result['status'];
        if (!is_string($paymentId) || $paymentId==='' || strlen($paymentId)>100) return false;
        if (in_array($status,['paid','canceled'],true)) {
            // Acknowledge only after a durable inbox row and its outbox command
            // are committed. The provider API remains authoritative for settlement.
            $this->db->transaction(function () use ($paymentId,$providerId,$status,$result,$request) {
                $eventId=(string)($result['event_id'] ?? ($paymentId.':'.$status));
                // Tamper guard (only defense in depth since Platega webhooks
                // carry no HMAC/timestamp): same provider_event_id but changed
                // payload content is a tamper attempt. WebhookGuard::claim()
                // returns 'new' | 'duplicate'(same payload, already processed)
                // | 'same_payload'(not yet processed) and THROWS on an id reuse
                // with different content — that exception is caught below and
                // surfaced without touching money.
                if ($this->webhookGuard !== null) {
                    try {
                        $claim = $this->webhookGuard->claim($providerId, $eventId, $result);
                    } catch (\RuntimeException $e) {
                        // Tamper: same event id, different payload. Log and raise
                        // an Ops alarm (read-only) but acknowledge the webhook so
                        // an attacker can't force a 500-loop; money is untouched.
                        error_log('webhook.tamper '.$providerId.'/'.$eventId.' '.$e->getMessage());
                        try {
                            $ops=(new OperationsService($this->db));
                            $corr='webhook-tamper:'.$providerId.':'.$eventId;
                            $op=$ops->start('webhook.tamper',['metadata'=>['provider'=>$providerId,'event_id'=>$eventId]],$corr);
                            if(!$op['existing']){
                                $ops->event($op['id'],'webhook.tamper.detected','failed','Webhook payload mismatch (same id, different content) for '.$providerId.'/'.substr($eventId,0,40).' — possible tamper.',['metadata'=>['provider'=>$providerId,'event_id'=>$eventId]]);
                                $ops->complete($op['id'],'failed','Проверить руками');
                                // Deliver a human-visible alert through the working
                                // SMTP pipeline (queue -> Reconciler flushQueue -> send).
                                if ($this->mailer !== null) {
                                    $adminEmail=$this->db->one("SELECT email FROM users WHERE role='admin' AND email IS NOT NULL ORDER BY created_at LIMIT 1")['email']??'';
                                    if ($adminEmail !== '') {
                                        try {
                                            $this->mailer->queue($adminEmail,
                                                '[ASTRACAT] Webhook tamper: '.$providerId,
                                                'Webhook payload mismatch (same id, different content) for '.$providerId.'/'.substr($eventId,0,40).'<br>Событие помечено processed=2 (tamper). Проверьте, не идёт ли атака на вебхуки.'
                                            );
                                        } catch (\Throwable) {}
                                    }
                                }
                            }
                        } catch (\Throwable) {}
                        return;
                    }
                    if ($claim === 'duplicate') {
                        return; // same payload, already processed => benign replay
                    }
                }
                $id=$this->events?->receive($providerId,$eventId,$paymentId,$result,true,$this->webhookHeaders($request)) ?? '';
                if ($id!=='') {
                    // Replay guard: if this event was already fully processed, a
                    // repeated delivery of the same provId/eventId is a benign
                    // duplicate (or a replay) — ack it but do NOT re-enqueue a
                    // second outbox command, or settlement state could be
                    // re-evaluated on the same event. Only first-seen (not yet
                    // processed) events spawn a processing job.
                    $already = $this->db->one(
                        "SELECT status FROM payment_events WHERE id=?", [$id]
                    );
                    if ($already !== null && $already['status'] === 'processed') {
                        return; // benign replay/duplicate: already handled
                    }
                    $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at,correlation_id) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING',[
                        Database::id(),'payment.event.process','payment-event:'.$id,json_encode(['event_id'=>$id],JSON_THROW_ON_ERROR),100,time(),time(),$id
                    ]);
                } else $this->outboxEnqueueVerify($paymentId,$providerId,$status);
            });
        }
        return true;
    }
    /** Worker-only: process one durable webhook event exactly once locally. */
    public function processEvent(string $eventId): void
    {
        $event=$this->events
            ? $this->events->claim($eventId)
            : $this->db->one('SELECT * FROM payment_events WHERE id=? AND processed_at IS NULL AND signature_valid=1',[$eventId]);
        if (!$event) return;
        $operations=new OperationsService($this->db);
        $correlation='cor_'.substr(hash('sha256',$event['provider'].':'.($event['payment_id']?:$event['provider_event_id'])),0,40);
        $op=$this->db->one('SELECT id FROM operations WHERE correlation_id=?',[$correlation]);
        try {
            // Webhooks are hints, including cancellations and events arriving
            // before checkout persistence. Always consult the provider before
            // changing state; API metadata can recover a lost local binding.
            $pid=(string)$event['payment_id'];
            $this->verify($pid,$event['provider'],$correlation);
            if ($this->events) $this->events->processed($eventId,(string)$event['lock_token']);
            if($op){$operations->event($op['id'],'payment.verified','success','Payment verified with provider');$operations->complete($op['id']);}
        } catch (\Throwable $e) {
            if ($this->events) $this->events->failed($eventId,(string)$event['lock_token'],$e);
            if($op)$operations->fail($op['id'],$e);
            throw $e;
        }
    }
    /** Reconciliation recovery for inbox events whose outbox delivery died or lease expired. */
    public function requeueStaleEvents(int $limit=100): int
    {
        // Unknown checkout creation is deliberately never replayed: the
        // provider might have accepted the original request. Surface stale
        // outcomes for manual reconciliation instead of silently deferring
        // them forever.
        $this->attempts?->escalateUnknown(900,$limit);
        $now=time();
        $rows=$this->db->all("SELECT id FROM payment_events WHERE signature_valid=1 AND ((status IN ('pending','retry') AND COALESCE(next_attempt_at,received_at)<=?) OR (status='processing' AND locked_until<=?)) ORDER BY received_at LIMIT ?",[$now,$now,$limit]);
        $bucket=intdiv($now,300);
        foreach($rows as $row) $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at,correlation_id) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING',[Database::id(),'payment.event.process','payment-event-recover:'.$row['id'].':'.$bucket,json_encode(['event_id'=>$row['id']],JSON_THROW_ON_ERROR),100,$now,$now,$row['id']]);
        return count($rows);
    }
    private function outboxEnqueueVerify(string $paymentId,string $providerId,string $status): void
    {
        // Reuse the outbox for authoritative verification (webhook is only a hint).
        // payment.verify is the highest-priority job; hardcode 100 to match Outbox::PRIORITY.
        $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING', [
            Database::id(), 'payment.verify', 'verify:'.$providerId.':'.$paymentId.':'.$status.':'.intdiv(time(),60), json_encode(['payment_id' => $paymentId,'provider'=>$providerId], JSON_THROW_ON_ERROR), 100, time(), time(),
        ]);
    }

    /** Evidence only: never retain Authorization, cookies, or provider secrets. */
    private function webhookHeaders(\Symfony\Component\HttpFoundation\Request $request): array
    {
        $allowed=['content-type','x-request-id','x-signature','x-signature-sha256','x-webhook-id'];
        $headers=[];
        foreach($allowed as $name) if($request->headers->has($name)) $headers[$name]=$request->headers->all($name);
        return $headers;
    }
}
