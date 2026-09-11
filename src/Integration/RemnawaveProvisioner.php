<?php
declare(strict_types=1);
namespace App\Integration;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class RemnawaveProvisioner implements Provisioner
{
    public function __construct(private HttpClientInterface $http, private string $baseUrl, private string $token, private string $squad) {}
    public function provision(array $subscription): array
    {
        $squad=($subscription['squad_uuid']??'')!==''?$subscription['squad_uuid']:$this->squad;
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token || !$squad) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        // Deterministic username recovers a create that succeeded remotely but timed out locally.
        $response=$this->request('GET','/api/users/by-username/'.$username);
        if ($response->getStatusCode()===404) {
            $response=$this->request('POST','/api/users',['username'=>$username,'status'=>'ACTIVE',
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$subscription['expires_at']),
                'trafficLimitBytes'=>(int)$subscription['traffic_bytes'],'trafficLimitStrategy'=>'NO_RESET',
                'hwidDeviceLimit'=>(int)$subscription['devices'],'activeInternalSquads'=>[$squad]]);
            if ($response->getStatusCode()===409) $response=$this->request('GET','/api/users/by-username/'.$username);
        }
        $user=$response->toArray()['response'];
        // Panel returns id (int) and shortUuid; subscriptionUrl is the client link.
        $remoteId=$user['id']??$user['shortUuid']??$user['uuid']??null;
        if (($user['username']??'')!==$username || empty($remoteId) || empty($user['subscriptionUrl']) || !str_starts_with($user['subscriptionUrl'],'https://')) throw new \RuntimeException('Invalid Remnawave response');
        return ['id'=>(string)$remoteId,'url'=>$user['subscriptionUrl']];
    }
    public function extend(array $subscription): void
    {
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        $response=$this->request('GET','/api/users/by-username/'.$username);
        if ($response->getStatusCode()===404) throw new \RuntimeException('Remnawave user not found for renewal');
        $user=$response->toArray()['response'];
        $id=$user['id']??null;
        if (!$id) throw new \RuntimeException('Invalid Remnawave response for renewal');
        $this->request('PATCH','/api/users',['id'=>(int)$id,
            'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$subscription['expires_at']),
            'trafficLimitBytes'=>(int)($subscription['traffic_bytes']??0),
            'hwidDeviceLimit'=>(int)($subscription['devices']??3),
            'status'=>'ACTIVE',
        ]);
    }
    public function setTraffic(array $subscription, int $trafficGb): void
    {
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        $user=$this->fetch($username);
        if (!$user) throw new \RuntimeException('Remnawave user not found for traffic update');
        $id=$user['id']??null;
        if (!$id) throw new \RuntimeException('Invalid Remnawave response for traffic update');
        $this->request('PATCH','/api/users',['id'=>(int)$id,
            'trafficLimitBytes'=>(int)($subscription['traffic_bytes']??0)+$trafficGb*1073741824,
        ]);
    }
    public function setDevices(array $subscription, int $devices): void
    {
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        $user=$this->fetch($username);
        if (!$user) throw new \RuntimeException('Remnawave user not found for device update');
        $id=$user['id']??null;
        if (!$id) throw new \RuntimeException('Invalid Remnawave response for device update');
        $this->request('PATCH','/api/users',['id'=>(int)$id,
            'hwidDeviceLimit'=>(int)($subscription['device_limit']??$subscription['devices']??1)+$devices,
        ]);
    }
    public function fetch(string $username): ?array
    {
        $response=$this->request('GET','/api/users/by-username/'.$username);
        if ($response->getStatusCode()===404) return null;
        $data=$response->toArray()['response']??null;
        return $data===null||$data===[] ? null : $data;
    }
    public function disable(string $username): void
    {
        $user=$this->fetch($username);
        if (!$user) return;
        $id=$user['id']??null;
        if (!$id) return;
        $this->request('PATCH','/api/users',['id'=>(int)$id,'status'=>'DISABLED']);
    }
    public function remove(string $username): void
    {
        $user=$this->fetch($username);
        if (!$user) return;
        $id=$user['id']??null;
        if (!$id) return;
        $this->request('DELETE','/api/users/'.$id);
    }
    /** Remove a panel user by known panel id (works for legacy Django usernames). */
    public function removeById(int $panelId): void
    {
        if ($panelId <= 0) return;
        try {
            $this->request('DELETE','/api/users/'.$panelId);
        } catch (\Throwable $e) {
            if (method_exists($e,'getCode') && $e->getCode()!==404) throw $e;
        }
    }
    private function request(string $method,string $path,?array $body=null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options=['auth_bearer'=>$this->token,'timeout'=>10,'max_duration'=>20,'max_redirects'=>0];
        if ($body!==null) $options['json']=$body;
        return $this->http->request($method,rtrim($this->baseUrl,'/').$path,$options);
    }
}
