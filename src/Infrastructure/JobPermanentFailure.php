<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Terminal, non-recoverable job failure (e.g. Telegram permanently refuses a
 *  chat: bot blocked, chat not found, user deactivated). Dead-letter the durable
 *  job immediately instead of burning retry budget, so a mass broadcast of
 *  undeliverable recipients never clogs the queue or stalls the worker. */
final class JobPermanentFailure extends \RuntimeException
{
}
