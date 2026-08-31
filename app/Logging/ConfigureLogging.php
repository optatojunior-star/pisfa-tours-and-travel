<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Attaches the redaction processor to a channel.
 *
 * Registered as a `tap` on every channel in `config/logging.php`, so a channel
 * added later has to opt *out* of redaction rather than remember to opt in.
 */
class ConfigureLogging
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        // A channel can be backed by any PSR-3 logger. Pushing the processor
        // onto the Monolog instance rather than each handler means it also
        // covers handlers added after this tap runs.
        if (! $monolog instanceof Monolog) {
            return;
        }

        $monolog->pushProcessor(new RedactSensitiveValues);
    }
}
