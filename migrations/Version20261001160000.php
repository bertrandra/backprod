<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The index the scheduler reads, once a minute, for ever (ADR-067).
 *
 * The platform's recurring work is now put on the queue by the runner's own
 * pass, and deciding what is due means asking when each type was last
 * scheduled:
 *
 *     SELECT type, max(created_at) FROM jobs
 *      WHERE idempotency_key = 'schedule' AND type IN (…)
 *      GROUP BY type
 *
 * `jobs` had no index on `type` at all — the two it has serve claiming
 * (`status, run_after, priority, created_at`) and a tenant's own list
 * (`tenant_id, product_id, created_at`). So that read was a sequential scan of
 * every job ever enqueued, run every minute by cron, on a table nothing prunes.
 * Harmless on a new deployment and quietly quadratic on an old one: the cost
 * grows with history, and the thing paying it is the pass that sends
 * notifications.
 *
 * **Unfiltered on purpose.** A partial index `WHERE idempotency_key =
 * 'schedule'` would be smaller and match the query exactly — and would put
 * `Schedule::KEY` in a second place, where a migration cannot follow a constant
 * that moves (ADR-016: what is applied is history). The filter is also the half
 * worth least: `type` is what makes the scan selective, and the descending
 * `created_at` is what lets `max()` stop at the first row of each group.
 *
 * Reversible, unlike most of this platform's migrations: dropping an index
 * destroys no data and loses no fact. A deployment that rolls this back gets
 * its sequential scan and nothing worse.
 */
final class Version20261001160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index jobs by type and recency, for the scheduler that reads it every minute.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX jobs_type_recent_idx ON jobs (type, created_at DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX jobs_type_recent_idx');
    }
}
