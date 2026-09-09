<?php
declare(strict_types=1);
namespace App\Integration;
interface Provisioner { public function provision(array $subscription): array; }
