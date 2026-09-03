<?php

declare(strict_types=1);

namespace App\Job\Domain;

/**
 * §27's five states.
 *
 * QUEUED and RUNNING are the only ones a job can leave. The other three are
 * terminal, and a job that failed its last attempt does not go back to QUEUED
 * — a retry that has run out of attempts is a failure, and pretending
 * otherwise would leave work looking pending forever.
 */
final class JobStatus
{
    public const QUEUED = 'QUEUED';
    public const RUNNING = 'RUNNING';
    public const SUCCEEDED = 'SUCCEEDED';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';

    public static function isTerminal(string $status): bool
    {
        return $status === self::SUCCEEDED || $status === self::FAILED || $status === self::CANCELLED;
    }
}
