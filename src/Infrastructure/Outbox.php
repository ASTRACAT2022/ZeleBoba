<?php
declare(strict_types=1);
namespace App\Infrastructure;
final class Outbox
{
    public function __construct(private Database $db, private ?\App\Settings\Vault $vault=null) {}
    /** Called inside the originating business transaction. */
    public function enqueue(string $topic, string $key, array $payload): void
    {
        $json=json_encode($payload, JSON_THROW_ON_ERROR);
        if($this->vault)$json='enc:'.$this->vault->seal('outbox:'.$topic,$json);
        $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,available_at,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING', [Database::id(),$topic,$key,$json,time(),time()]);
    }
    public function runOne(callable $handler): bool
    {
        $job = $this->db->transaction(function () {
            $lock = $this->db->postgres() ? ' FOR UPDATE SKIP LOCKED' : '';
            $job = $this->db->one("SELECT * FROM outbox WHERE (status='pending' AND available_at<=?) OR (status='processing' AND locked_until<=?) ORDER BY created_at,id LIMIT 1".$lock, [time(),time()]);
            if (!$job) return null;
            $job['lock_token'] = Database::id();
            $this->db->execute("UPDATE outbox SET status='processing', attempts=attempts+1, locked_until=?, lock_token=? WHERE id=?", [time()+120,$job['lock_token'],$job['id']]);
            return $job;
        });
        if (!$job) return false;
        try {
            $json=$job['payload'];
            if(str_starts_with($json,'enc:'))$json=$this->vault?->open('outbox:'.$job['topic'],substr($json,4))??throw new \RuntimeException('Outbox decryption unavailable');
            $handler($job['topic'], json_decode($json,true,512,JSON_THROW_ON_ERROR));
            $this->db->execute("UPDATE outbox SET status='done',locked_until=NULL,last_error=NULL,payload='{}' WHERE id=? AND lock_token=?", [$job['id'],$job['lock_token']]);
        } catch (\Throwable $e) {
            $attempt = (int)$job['attempts']+1;
            // Never persist raw HTTP errors: they may contain tokens or subscription URLs.
            $this->db->execute('UPDATE outbox SET status=?, available_at=?, locked_until=NULL,last_error=? WHERE id=? AND lock_token=?', [$attempt>=8?'dead':'pending',time()+min(3600,2**$attempt)+random_int(0,5),get_class($e),$job['id'],$job['lock_token']]);
            error_log(json_encode(['event'=>'job.failed','job_id'=>$job['id'],'type'=>get_class($e),'attempt'=>$attempt]));
        }
        return true;
    }
}
