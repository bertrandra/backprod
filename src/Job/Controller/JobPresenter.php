<?php

declare(strict_types=1);

namespace App\Job\Controller;

use App\Job\Domain\Job;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * What a client is told about a job.
 *
 * `leased_until` is deliberately absent. It is the runner's bookkeeping, and
 * a client that saw it would have no use for it beyond guessing at internals.
 */
final class JobPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Job $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'payload' => $job->payload,
            'result' => $job->result,
            'attempts' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'run_after' => self::moment($job->runAfter),
            'failure_reason' => $job->failureReason,
            'started_at' => self::nullableMoment($job->startedAt),
            'finished_at' => self::nullableMoment($job->finishedAt),
            'created_at' => self::moment($job->createdAt),
        ];
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }

    private static function nullableMoment(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::moment($moment);
    }
}
