<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that run against a real PostgreSQL.
 *
 * The adapters under test exist to run SQL, and the isolation they enforce is
 * only meaningfully verified by the database that will enforce it in
 * production — so these do not use a double.
 *
 * CI always provides DATABASE_DSN (see .github/workflows/ci.yml), so these
 * tests always execute there. Locally they are skipped rather than failed,
 * because a missing database is a missing tool, not a broken change.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = TestDatabase::dsn();

        if ($dsn === null) {
            self::markTestSkipped('DATABASE_DSN is not set; skipping database tests.');
        }

        $this->connection = TestDatabase::connect($dsn);
        TestDatabase::reset($this->connection);
    }
}
