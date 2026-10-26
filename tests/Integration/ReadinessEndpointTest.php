<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Health\Controller\ReadinessController;
use App\Job\Domain\JobRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

/**
 * `/health/ready`: the database, and the queue when the operator has said how
 * often it runs (2026-10-26). A verdict and nothing else.
 */
#[CoversNothing]
final class ReadinessEndpointTest extends DatabaseApiTestCase
{
    public function testReadyWhenTheDatabaseAnswersAndNoThresholdIsSet(): void
    {
        $response = $this->request('GET', '/api/v1/health/ready');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ready'], $this->decode($response));
    }

    public function testAQueueThatNeverRanIsNotReadyOnceAThresholdIsSet(): void
    {
        $this->withThreshold(600);

        $response = $this->request('GET', '/api/v1/health/ready');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['status' => 'unavailable'], $this->decode($response));
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
    }

    public function testAQueueThatRanRecentlyIsReady(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO job_runs (started_at, finished_at) VALUES (now() - interval '2 minutes', now() - interval '1 minute')",
        );
        $this->withThreshold(600);

        self::assertSame(200, $this->request('GET', '/api/v1/health/ready')->getStatusCode());
    }

    public function testAQueueThatStoppedIsNotReady(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO job_runs (started_at, finished_at) VALUES (now() - interval '3 hours', now() - interval '3 hours')",
        );
        $this->withThreshold(600);

        self::assertSame(503, $this->request('GET', '/api/v1/health/ready')->getStatusCode());
    }

    /**
     * Nothing about why: the probe is unauthenticated, and an error message
     * from the database is exactly what it must not repeat.
     */
    public function testADatabaseThatDoesNotAnswerIsNotReadyAndSaysNothingMore(): void
    {
        $jobs = $this->createStub(JobRepository::class);
        $jobs->method('liveness')->willThrowException(new RuntimeException('SQLSTATE[08006] connection to 10.0.0.5 refused'));
        $this->override([ReadinessController::class => new ReadinessController($jobs)]);

        $response = $this->request('GET', '/api/v1/health/ready');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"unavailable"}', (string) $response->getBody());
    }

    private function withThreshold(int $seconds): void
    {
        $jobs = $this->container()->get(JobRepository::class);
        self::assertInstanceOf(JobRepository::class, $jobs);

        $this->override([ReadinessController::class => new ReadinessController($jobs, $seconds)]);
    }
}
