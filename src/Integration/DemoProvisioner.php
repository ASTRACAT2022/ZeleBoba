<?php
declare(strict_types=1);
namespace App\Integration;
final class DemoProvisioner implements Provisioner
{
    public function provision(array $subscription): array { return ['id'=>'demo_'.$subscription['id'],'url'=>null]; }
    public function extend(array $subscription): void {}
    public function setTraffic(array $subscription, int $trafficGb): void {}
    public function setDevices(array $subscription, int $devices): void {}
}
