<?php
declare(strict_types=1);
namespace App\Integration;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class RemnawaveProvisioner implements Provisioner
{
    public function __construct(private HttpClientInterface $http, private string $baseUrl, private string $token, private string $squad, private ?\App\Infrastructure\CircuitBreaker $breaker = null) {}
    public function provision(array $subscription): array
    {
        $squad=($subscription['squad_uuid']??'')!==''?$subscription['squad_uuid']:$this->squad;
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token || !$squad) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        // Deterministic username recovers a create that succeeded remotely but timed out locally.
        $response=$this->request('GET','/api/users/by-username/'.$username);
        $existing=$response->getStatusCode()!==404;
        if ($response->getStatusCode()===404) {
            $response=$this->request('POST','/api/users',['username'=>$username,'status'=>'ACTIVE',
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$subscription['expires_at']),
                'trafficLimitBytes'=>(int)$subscription['traffic_bytes'],'trafficLimitStrategy'=>'NO_RESET',
                'hwidDeviceLimit'=>(int)$subscription['devices'],'activeInternalSquads'=>[$squad]]);
            if ($response->getStatusCode()===409) {
                $response=$this->request('GET','/api/users/by-username/'.$username);
                $existing=true;
            }
        }
        $this->requireSuccess($response);
        $user=$response->toArray()['response'];
        // Panel returns id (int) and shortUuid; subscriptionUrl is the client link.
        $remoteId=$user['id']??$user['shortUuid']??$user['uuid']??null;
        if (($user['username']??'')!==$username || empty($remoteId) || empty($user['subscriptionUrl']) || !str_starts_with($user['subscriptionUrl'],'https://')) throw new \RuntimeException('Invalid Remnawave response');
        // A timed-out create may have reached the panel before local terms
        // changed. Reconcile the existing account to the current desired state.
        if ($existing) {
            $id=$user['id']??null;
            if (!$id) throw new \RuntimeException('Remnawave user id missing for recovery');
            $this->requireSuccess($this->request('PATCH','/api/users',[
                'id'=>(int)$id,
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$subscription['expires_at']),
                'trafficLimitBytes'=>(int)$subscription['traffic_bytes'],
                'hwidDeviceLimit'=>(int)$subscription['devices'],
                'status'=>'ACTIVE',
            ]));
        }
        return ['id'=>(string)$remoteId,'url'=>$user['subscriptionUrl']];
    }
    public function extend(array $subscription): void
    {
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token) throw new \RuntimeException('Remnawave configuration missing');
        // Resolve by canonical zb_<id> username, then fall back to the stored
        // remote_id: legacy/provisioned subs can exist in the panel under a
        // different username (e.g. virgalia). Looking up only by zb_<id> used to
        // 404 on those and burn 8 retries into dead letters. Resolve covers both.
        $user=$this->resolve($subscription);
        if (!$user) throw new \RuntimeException('Remnawave user not found for renewal');
        $id=$user['id']??null;
        if (!$id) throw new \RuntimeException('Invalid Remnawave response for renewal');
        $this->requireSuccess($this->request('PATCH','/api/users',['id'=>(int)$id,
            'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$subscription['expires_at']),
            'trafficLimitBytes'=>(int)($subscription['traffic_bytes']??0),
            'hwidDeviceLimit'=>(int)($subscription['devices']??3),
            'status'=>'ACTIVE',
        ]));
    }
    /**
     * Resolve the panel user for a subscription: try canonical zb_<id> first,
     * then fall back to stored remote_id/remnawave_id (legacy usernames).
     * Returns the panel user array or null when not found anywhere.
     */
    public function resolve(array $subscription): ?array
    {
        $username='zb_'.($subscription['id']??'');
        if ($username!=='zb_') {
            $u=$this->fetch($username);
            if ($u) return $u;
        }
        foreach (['remote_id','remnawave_id'] as $k) {
            $id=(int)($subscription[$k]??0);
            if ($id<=0) continue;
            $u=$this->fetchById($id);
            if ($u) return $u;
        }
        // Some legacy rows store the panel id in remote_id as a string uuid; try once more as raw fetch.
        $raw=(string)($subscription['remote_id']??'');
        if ($raw!=='' && !ctype_digit($raw)) {
            $u=$this->fetch($raw);
            if ($u) return $u;
        }
        return null;
    }
    public function setTraffic(array $subscription, int $trafficGb): void
    {
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        $user=$this->fetch($username) ?? $this->resolve($subscription);
        if (!$user) throw new \RuntimeException('Remnawave user not found for traffic update');
        $id=$user['id']??null;
        if (!$id) throw new \RuntimeException('Invalid Remnawave response for traffic update');
        $this->requireSuccess($this->request('PATCH','/api/users',['id'=>(int)$id,
            'trafficLimitBytes'=>(int)($subscription['traffic_bytes']??0)+$trafficGb*1073741824,
        ]));
    }
    public function setDevices(array $subscription, int $devices): void
    {
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        $user=$this->fetch($username) ?? $this->resolve($subscription);
        if (!$user) throw new \RuntimeException('Remnawave user not found for device update');
        $id=$user['id']??null;
        if (!$id) throw new \RuntimeException('Invalid Remnawave response for device update');
        $this->requireSuccess($this->request('PATCH','/api/users',['id'=>(int)$id,
            'hwidDeviceLimit'=>(int)($subscription['device_limit']??$subscription['devices']??1)+$devices,
        ]));
    }
    public function fetch(string $username): ?array
    {
        $response=$this->request('GET','/api/users/by-username/'.$username);
        if ($response->getStatusCode()===404) return null;
        $this->requireSuccess($response);
        $data=$response->toArray()['response']??null;
        return $data===null||$data===[] ? null : $data;
    }
    public function fetchById(int $id): ?array
    {
        $response=$this->request('GET','/api/users/'.$id);
        if ($response->getStatusCode()===404) return null;
        $this->requireSuccess($response);
        $data=$response->toArray()['response']??null;
        return $data===null||$data===[] ? null : $data;
    }
    /** PATCH a panel user by panel id (legacy subscriptions keep old usernames). */
    public function updateById(int $id, int $trafficBytes, int $devices, int $expiresAt): void
    {
        $this->requireSuccess($this->request('PATCH','/api/users',[
            'id'=>$id,
            'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiresAt),
            'trafficLimitBytes'=>$trafficBytes,
            'hwidDeviceLimit'=>$devices,
            'status'=>'ACTIVE',
        ]));
    }
    public function disable(string $username): void
    {
        $user=$this->fetch($username);
        if (!$user) return;
        $id=$user['id']??null;
        if (!$id) return;
        $this->requireSuccess($this->request('PATCH','/api/users',['id'=>(int)$id,'status'=>'DISABLED']));
    }
    public function disableById(int $panelId): void
    {
        if ($panelId <= 0) return;
        $this->requireSuccess($this->request('PATCH','/api/users',['id'=>$panelId,'status'=>'DISABLED']));
    }
    /**
     * List ACTIVE panel users page by page. Used to import subscriptions that
     * exist on the panel but are missing locally (legacy without order_id).
     * Returns a flat array of decoded user objects.
     */
    public function listActiveUsers(int $pageSize = 200): array
    {
        $pageSize = max(1, min($pageSize, 500));
        $out = []; $page = 1;
        while (true) {
            $r = $this->request('GET', '/api/users?page=' . $page . '&pageSize=' . $pageSize);
            if ($r->getStatusCode() !== 200) throw new \RuntimeException('Remnawave list users failed on page '.$page.': HTTP '.$r->getStatusCode());
            $data = $r->toArray()['response'] ?? null;
            if (!is_array($data)) break;
            $users = $data['users'] ?? [];
            $total = (int)($data['total'] ?? 0);
            if (empty($users)) break;
            foreach ($users as $u) {
                if (($u['status'] ?? '') === 'ACTIVE') $out[] = $u;
            }
            if ($total > 0 && $page * $pageSize >= $total) break;
            if ($total <= 0 && count($users) < $pageSize) break;
            $page++;
            if ($page > 50) break; // hard cap
        }
        return $out;
    }
    public function remove(string $username): void
    {
        $user=$this->fetch($username);
        if (!$user) return;
        $id=$user['id']??null;
        if (!$id) return;
        $response=$this->request('DELETE','/api/users/'.$id);
        if ($response->getStatusCode()!==404) $this->requireSuccess($response);
    }
    /** Remove a panel user by known panel id (works for legacy Django usernames). */
    public function removeById(int $panelId): void
    {
        if ($panelId <= 0) return;
        $response=$this->request('DELETE','/api/users/'.$panelId);
        if ($response->getStatusCode()!==404) $this->requireSuccess($response);
    }
    private function requireSuccess(\Symfony\Contracts\HttpClient\ResponseInterface $response): void
    {
        $status=$response->getStatusCode();
        if ($status<200 || $status>=300) throw new \RuntimeException('Remnawave request failed: HTTP '.$status);
    }
    private function request(string $method,string $path,?array $body=null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options=['auth_bearer'=>$this->token,'timeout'=>10,'max_duration'=>20,'max_redirects'=>0];
        if ($body!==null) $options['json']=$body;
        if ($this->breaker !== null && !$this->breaker->allow('remnawave_api')) {
            throw new \App\Billing\BillingError('Сервис временно недоступен (Remnawave). Попробуйте позже.');
        }
        try {
            $r = $this->http->request($method,rtrim($this->baseUrl,'/').$path,$options);
            // Symfony's client is lazy: no network I/O is guaranteed until a
            // response method is called.  Record breaker success only after
            // receiving an HTTP response, and count 5xx/4xx as failures even
            // though selected callers intentionally handle 404/409 themselves.
            $status=$r->getStatusCode();
            $expected=($status===404 && (($method==='GET' && str_starts_with($path,'/api/users/')) || $method==='DELETE'))
                || ($status===409 && $method==='POST' && $path==='/api/users');
            if (($status >= 200 && $status < 400) || $expected) $this->breaker?->success('remnawave_api');
            else $this->breaker?->failure('remnawave_api');
            return $r;
        } catch (\Throwable $e) {
            $this->breaker?->failure('remnawave_api');
            throw $e;
        }
    }
}
