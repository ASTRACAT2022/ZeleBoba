<?php
declare(strict_types=1);
namespace App\Integration;
final class DemoProvisioner implements Provisioner
{
    public function provision(array $subscription): array { return ['id'=>'demo_'.$subscription['id'],'url'=>null]; }
}
