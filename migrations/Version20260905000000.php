<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Request counters, for the rate limiting §31 asks for.
 *
 * In PostgreSQL because that is what this platform has. D3 rules out a
 * daemon, and with it Redis: the deployment target is shared hosting where a
 * process starts, does its work and exits. A limiter that needed a resident
 * store would be a limiter that did not run.
 *
 * **A fixed window, not a sliding log.** A log of every request timestamp
 * answers "how many in the last minute" exactly, and costs a row per request
 * to do it. A fixed window costs one row per key per window and is wrong only
 * at the boundary, where a client can spend two windows' worth across the
 * seam. For abuse protection that is an acceptable trade and a well
 * understood one; for anything where it is not, the answer is a limiter in
 * front of the application rather than a cleverer table.
 *
 * **The count is an upsert, never a read-then-write.** Two requests arriving
 * together would both read the same number and both write it back, and the
 * limit would be quietly twice what it says. `ON CONFLICT DO UPDATE ...
 * RETURNING` makes the increment and the answer one statement — the same
 * reasoning as every other counter in this schema.
 */
final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fixed-window request counters for rate limiting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE rate_limit_counters (
                -- Who is being counted and under which rule, as one opaque
                -- string. Composing it here rather than in columns keeps the
                -- primary key a single comparison and lets a caller choose
                -- what "who" means without a migration.
                bucket       TEXT NOT NULL,
                window_start TIMESTAMPTZ NOT NULL,
                hits         INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, window_start),
                CONSTRAINT rate_limit_counters_hits_positive CHECK (hits > 0)
            )
            SQL);

        // What the sweep reads: everything whose window has passed. Windows
        // are short and the table is written on every request, so old rows
        // outnumber live ones within minutes.
        $this->addSql(<<<'SQL'
            CREATE INDEX rate_limit_counters_expiry ON rate_limit_counters (window_start)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS rate_limit_counters');
    }
}
