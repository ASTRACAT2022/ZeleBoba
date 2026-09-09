<?php
declare(strict_types=1);
namespace App\Integration;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class RemnawaveProvisioner implements Provisioner
{
    public function __construct(private HttpClientInterface $http, private string $baseUrl, private string $token, private string $squad) {}
    public function provision(array $subscription): array
    {
        if (!str_starts_with($this->baseUrl,'https://') || !$this->token || !($subscription['squad_uuid']??$this->squad)) throw new \RuntimeException('Remnawave configuration missing');
        $username='zb_'.$subscription['id'];
        // Deterministic username recovers a create that succeeded remotely but timed out locally.
        $response=$this->request('GET','/api/users/by-username/'.$username);
        if ($response->getStatusCode()===404) {
            $response=$this->request('POST','/api/users',['username'=>$username,'status'=>'ACTIVE',
                'expireAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$subscription['expires_at']),
                'trafficLimitBytes'=>(int)$subscription['traffic_bytes'],'trafficLimitStrategy'=>'NO_RESET',
                'hwidDeviceLimit'=>(int)$subscription['devices'],'activeInternalSquads'=>[$subscription['squad_uuid']??$this->squad]]);
            if ($response->getStatusCode()===409) $response=$this->request('GET','/api/users/by-username/'.$username);
        }
        $user=$response->toArray()['response'];
        if (($user['username']??'')!==$username || empty($user['uuid']) || empty($user['subscriptionUrl']) || !str_starts_with($user['subscriptionUrl'],'https://')) throw new \RuntimeException('Invalid Remnawave response');
        return ['id'=>$user['uuid'],'url'=>$user['subscriptionUrl']];
    }
    private function request(string $method,string $path,?array $body=null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options=['auth_bearer'=>$this->token,'timeout'=>10,'max_duration'=>20,'max_redirects'=>0];
        if ($body!==null) $options['json']=$body;
        return $this->http->request($method,rtrim($this->baseUrl,'/').$path,$options);
    }
}
