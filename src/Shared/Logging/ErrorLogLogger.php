<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal PSR-3 logger writing to the SAPI error log.
 *
 * Deliberately unambitious: M0 must not swallow a 500 silently, but real
 * structured logging and error tracking are M8 (Architecture V2 §30). Because
 * consumers depend on LoggerInterface, replacing this changes no call site.
 */
final class ErrorLogLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $encoded = json_encode(
            ['level' => (string) $level, 'message' => (string) $message, 'context' => $context],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        error_log($encoded === false ? sprintf('%s: %s', (string) $level, $message) : $encoded);
    }
}
