<?php
declare(strict_types=1);
namespace App\Integration;
interface Provisioner
{
    public function provision(array $subscription): array;
    public function extend(array $subscription): void;
    public function setTraffic(array $subscription, int $trafficGb): void;
    public function setDevices(array $subscription, int $devices): void;
}
