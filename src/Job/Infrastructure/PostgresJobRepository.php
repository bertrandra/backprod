<?php

declare(strict_types=1);

namespace App\Job\Infrastructure;

use App\Job\Domain\Job;
use App\Job\Domain\JobRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use stdClass;

/**
 * The queue in PostgreSQL.
 *
 * {@see self::claim()} is the reason this design works on hosting that
 * forbids a daemon. `FOR UPDATE SKIP LOCKED` lets two runs overlap without
 * either blocking or colliding: each takes rows the other has not, and a cron
 * firing while the previous pass is still going — the normal case, not an
 * error — costs nothing.
 *
 * Two rules the SQL enforces that a `SELECT` then `UPDATE` could not:
 *
 *   - the selection and the lease happen in one statement, so no window
 *     exists in which a job is chosen but unclaimed;
 *   - `SKIP LOCKED` skips rather than waits, so a slow handler holding a row
 *     does not make every other runner queue behind it.
 */
final class PostgresJobRepository implements JobRepository
{
    private const COLUMNS = <<<'SQL'
        id, type, status, tenant_id, product_id, payload, result, idempotency_key,
        priority, attempts, max_attempts, run_after, leased_until, failure_reason,
        started_at, finished_at, created_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function enqueue(
        string $type,
        array $payload,
        ?string $tenantId,
        ?string $productId,
        ?string $idempotencyKey,
        int $priority,
        int $maxAttempts,
        ?string $requestedBy,
    ): Job {
        try {
            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO jobs
                        (type, payload, tenant_id, product_id, idempotency_key,
                         priority, max_attempts, requested_by)
                    VALUES (:type, CAST(:payload AS jsonb), :tenant, :product, :key,
                            :priority, :maxAttempts, :requestedBy)
                    RETURNING id
                    SQL,
                [
                    'type' => $type,
                    'payload' => self::encode($payload),
                    'tenant' => $tenantId,
                    'product' => $productId,
                    'key' => $idempotencyKey,
                    'priority' => $priority,
                    'maxAttempts' => $maxAttempts,
                    'requestedBy' => $requestedBy,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // The same work is already pending. Handing back the job that
            // exists is the honest answer — the caller asked for this to
            // happen, and it is going to.
            $existing = $this->findPendingByKey($type, $idempotencyKey);

            if ($existing === null) {
                // It finished between the insert failing and this lookup,
                // which means the work has just been done. Enqueue again
                // rather than pretend: the caller asked for a fresh run.
                throw new RuntimeException('A job with that key finished mid-enqueue; retry.');
            }

            return $existing;
        }

        if (!is_string($id)) {
            throw new RuntimeException('Failed to enqueue a job.');
        }

        return $this->requireById($id);
    }

    public function claim(int $limit, int $leaseSeconds): array
    {
        // One statement: choose and lease together. A SELECT followed by an
        // UPDATE would leave a window in which a row is chosen by two runners
        // at once, and no amount of ordering closes it.
        //
        // `attempts + 1` happens here rather than on failure so that a job
        // whose worker dies still consumes an attempt — otherwise a handler
        // that crashes the process would retry for ever.
        $rows = $this->connection->fetchAllAssociative(
            'UPDATE jobs SET status = \'RUNNING\', ' . <<<'SQL'
                       leased_until = now() + make_interval(secs => :lease),
                       started_at = coalesce(started_at, now()),
                       attempts = attempts + 1,
                       updated_at = now()
                 WHERE id IN (
                     SELECT id FROM jobs
                      WHERE attempts < max_attempts
                        AND ((status = 'QUEUED' AND run_after <= now())
                          OR (status = 'RUNNING' AND leased_until < now()))
                      ORDER BY priority DESC, run_after, created_at
                      FOR UPDATE SKIP LOCKED
                      LIMIT :limit
                 )
                SQL . ' RETURNING ' . self::COLUMNS,
            ['lease' => $leaseSeconds, 'limit' => $limit],
            ['lease' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(self::toJob(...), $rows);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function succeed(Job $job, array $result): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE jobs
                   SET status = 'SUCCEEDED',
                       result = CAST(:result AS jsonb),
                       leased_until = NULL,
                       finished_at = now(),
                       updated_at = now()
                 WHERE id = :id
                SQL,
            ['id' => $job->id, 'result' => self::encode($result)],
        );
    }

    public function fail(Job $job, string $reason, int $backoffSeconds): void
    {
        // The row decides, not the caller: `attempts` was already incremented
        // when the job was claimed, so "is this the last one?" is a fact
        // about the row rather than a count the runner has to keep.
        //
        // Note the lease is cleared in both branches. A queued job holding a
        // lease is refused by `jobs_running_holds_a_lease`, and rightly — it
        // would look claimed while waiting.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE jobs
                   SET status = CASE WHEN attempts >= max_attempts THEN 'FAILED' ELSE 'QUEUED' END,
                       failure_reason = :reason,
                       leased_until = NULL,
                       run_after = CASE
                           WHEN attempts >= max_attempts THEN run_after
                           ELSE now() + make_interval(secs => :backoff)
                       END,
                       finished_at = CASE WHEN attempts >= max_attempts THEN now() ELSE NULL END,
                       updated_at = now()
                 WHERE id = :id
                SQL,
            ['id' => $job->id, 'reason' => $reason, 'backoff' => $backoffSeconds],
            ['backoff' => ParameterType::INTEGER],
        );
    }

    public function find(?string $tenantId, ?string $productId, string $jobId): ?Job
    {
        if (!Uuid::isValid($jobId)) {
            return null;
        }

        // A tenant sees only its own jobs; staff pass null and see any. The
        // null is not a hole: the routes that pass it are behind a platform
        // role, and the ones that do not always carry a resolved tenant.
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM jobs
                 WHERE id = :id
                   AND (CAST(:tenant AS UUID) IS NULL OR tenant_id = CAST(:tenant AS UUID))
                   AND (CAST(:product AS UUID) IS NULL OR product_id = CAST(:product AS UUID))
                SQL,
            ['id' => $jobId, 'tenant' => $tenantId, 'product' => $productId],
        );

        return $row === false ? null : self::toJob($row);
    }

    public function listFor(?string $tenantId, ?string $productId, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM jobs
                 WHERE (CAST(:tenant AS UUID) IS NULL OR tenant_id = CAST(:tenant AS UUID))
                   AND (CAST(:product AS UUID) IS NULL OR product_id = CAST(:product AS UUID))
                 ORDER BY created_at DESC, id
                 LIMIT :limit OFFSET :offset
                SQL,
            ['tenant' => $tenantId, 'product' => $productId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toJob(...), $rows);
    }

    public function countFor(?string $tenantId, ?string $productId): int
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM jobs
                 WHERE (CAST(:tenant AS UUID) IS NULL OR tenant_id = CAST(:tenant AS UUID))
                   AND (CAST(:product AS UUID) IS NULL OR product_id = CAST(:product AS UUID))
                SQL,
            ['tenant' => $tenantId, 'product' => $productId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function cancel(Job $job): bool
    {
        // Conditional on still being QUEUED, so a job that started between
        // the caller's read and this write is not clobbered. The affected
        // count is the answer, which is why this is not a check beforehand.
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE jobs
                   SET status = 'CANCELLED', finished_at = now(), updated_at = now()
                 WHERE id = :id AND status = 'QUEUED'
                SQL,
            ['id' => $job->id],
        );

        // Normalised for the same reason as the quote sweep: executeStatement
        // reports int|string, and leaning on PHP's string-to-number juggling
        // to compare it is the kind of thing that is right until it is not.
        return (is_numeric($affected) ? (int) $affected : 0) > 0;
    }

    public function beginRun(): string
    {
        $id = $this->connection->fetchOne('INSERT INTO job_runs DEFAULT VALUES RETURNING id');

        if (!is_string($id)) {
            throw new RuntimeException('Failed to record the start of a run.');
        }

        return $id;
    }

    public function finishRun(string $runId, int $claimed, int $succeeded, int $failed): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE job_runs
                   SET finished_at = now(), claimed = :claimed,
                       succeeded = :succeeded, failed = :failed
                 WHERE id = :id
                SQL,
            ['id' => $runId, 'claimed' => $claimed, 'succeeded' => $succeeded, 'failed' => $failed],
            [
                'claimed' => ParameterType::INTEGER,
                'succeeded' => ParameterType::INTEGER,
                'failed' => ParameterType::INTEGER,
            ],
        );
    }

    private function findPendingByKey(string $type, ?string $key): ?Job
    {
        if ($key === null) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM jobs
                 WHERE type = :type AND idempotency_key = :key
                   AND status IN ('QUEUED', 'RUNNING')
                SQL,
            ['type' => $type, 'key' => $key],
        );

        return $row === false ? null : self::toJob($row);
    }

    private function requireById(string $id): Job
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM jobs WHERE id = :id',
            ['id' => $id],
        );

        if ($row === false) {
            throw new RuntimeException('The job vanished during the transaction that created it.');
        }

        return self::toJob($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toJob(array $row): Job
    {
        $result = $row['result'] ?? null;

        return new Job(
            Row::string($row, 'id'),
            Row::string($row, 'type'),
            Row::string($row, 'status'),
            Row::nullableString($row, 'tenant_id'),
            Row::nullableString($row, 'product_id'),
            self::decode($row, 'payload'),
            is_string($result) ? self::decodeString($result) : null,
            Row::nullableString($row, 'idempotency_key'),
            Row::integer($row, 'priority'),
            Row::integer($row, 'attempts'),
            Row::integer($row, 'max_attempts'),
            Row::timestamp($row, 'run_after'),
            Row::nullableTimestamp($row, 'leased_until'),
            Row::nullableString($row, 'failure_reason'),
            Row::nullableTimestamp($row, 'started_at'),
            Row::nullableTimestamp($row, 'finished_at'),
            Row::timestamp($row, 'created_at'),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        $encoded = json_encode($value === [] ? new stdClass() : $value);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row, string $column): array
    {
        return self::decodeString(Row::string($row, $column));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeString(string $encoded): array
    {
        $decoded = json_decode($encoded, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
