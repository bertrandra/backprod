<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestDatabase;
use Doctrine\DBAL\Connection;

/**
 * The real HTTP pipeline over the real database.
 *
 * Most endpoint tests replace the repositories with doubles, because what
 * they assert is about routing, context and refusals. Subscriptions are not
 * like that: entitlements are rewritten transactionally, they lapse on a
 * window, and a quota is enforced against a count that another endpoint
 * changes. A double would be a second implementation of all that, and the
 * test would then be checking that my two versions agree rather than that
 * either is right.
 *
 * Only the identity and product doubles stay — those keep the fixtures
 * readable and are not what these tests are about.
 */
abstract class DatabaseApiTestCase extends ApiTestCase
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
