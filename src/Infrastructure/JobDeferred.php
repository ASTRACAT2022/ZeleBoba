<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Expected pause: keep the durable job pending without spending retry budget. */
final class JobDeferred extends \RuntimeException
{
    public function __construct(public readonly int $delaySeconds=60)
    {
        parent::__construct('Job deferred by an operational control');
    }
}
