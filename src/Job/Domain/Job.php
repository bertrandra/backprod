<?php

declare(strict_types=1);

namespace App\Job\Domain;

use DateTimeImmutable;

/**
 * A unit of work waiting to be done, or being done, or finished.
 *
 * `runAfter` is when it becomes eligible, which makes scheduling and retrying
 * the same mechanism: a job that failed is one whose eligibility has moved
 * into the future. It is also why a late cron makes work late rather than
 * wrong.
 *
 * `leasedUntil` is what a running job holds so that a crashed worker strands
 * nothing. When the lease lapses the job is claimable again — a lapse being a
 * fact about the clock, as everywhere else in this platform.
 */
final class Job
{
    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $status,
        public readonly ?string $tenantId,
        public readonly ?string $productId,
        public readonly array $payload,
        public readonly ?array $result,
        public readonly ?string $idempotencyKey,
        public readonly int $priority,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly DateTimeImmutable $runAfter,
        public readonly ?DateTimeImmutable $leasedUntil,
        public readonly ?string $failureReason,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isTerminal(): bool
    {
        return JobStatus::isTerminal($this->status);
    }

    /**
     * Whether this attempt was the last one available.
     *
     * Asked after a failure, to decide between backing off and giving up.
     */
    public function isExhausted(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }
}
