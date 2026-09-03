<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The job queue §27 requires, in the shape D3 settled on.
 *
 * Shared hosting forbids a resident daemon, so there is no worker waiting on
 * a socket. `bin/run-jobs` starts, claims what is due, runs it, and exits —
 * and the queue is a table, because a table is the only thing both a cron
 * invocation and a future persistent worker can agree on.
 *
 * Three columns carry the design.
 *
 * `run_after` is when a job becomes eligible, which is what makes retries and
 * scheduling the same mechanism: a failed job is one whose `run_after` has
 * moved into the future. It also means a delayed cron makes work *late*
 * rather than wrong.
 *
 * `leased_until` is what stops a crashed worker stranding a job forever. A
 * RUNNING job whose lease has lapsed is claimable again — the platform's
 * recurring rule, that a lapse is a fact about the clock rather than about
 * whether something ran, applied to the runner itself.
 *
 * `idempotency_key` is how "sweep expired quotes" exists at most once while
 * pending. It is a partial unique index rather than a check, for the same
 * reason webhook delivery is: a check can be raced, an index cannot.
 */
final class Version20260903110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The job queue and its runs';
    }

    public function up(Schema $schema): void
    {
        // tenant_id and product_id are nullable because a platform-wide sweep
        // belongs to nobody. Where a job *does* belong to a tenant, it says
        // so, and RESTRICT keeps a queued job from outliving its subject.
        $this->addSql(<<<'SQL'
            CREATE TABLE jobs (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                type TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'QUEUED',
                tenant_id UUID REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID REFERENCES products (id) ON DELETE RESTRICT,
                payload JSONB NOT NULL DEFAULT '{}',
                result JSONB,
                idempotency_key TEXT,
                priority INTEGER NOT NULL DEFAULT 0,
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                run_after TIMESTAMPTZ NOT NULL DEFAULT now(),
                leased_until TIMESTAMPTZ,
                failure_reason TEXT,
                requested_by UUID REFERENCES users (id) ON DELETE SET NULL,
                started_at TIMESTAMPTZ,
                finished_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT jobs_status_known CHECK (status IN (
                    'QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED', 'CANCELLED'
                )),
                CONSTRAINT jobs_type_not_blank CHECK (btrim(type) <> ''),
                CONSTRAINT jobs_payload_is_object CHECK (jsonb_typeof(payload) = 'object'),
                CONSTRAINT jobs_attempts_sane
                    CHECK (attempts >= 0 AND max_attempts >= 1 AND attempts <= max_attempts),
                -- A running job holds a lease; nothing else does. Without
                -- this, a job could sit in RUNNING with no lease and never be
                -- reclaimed, which is precisely the stranding the lease
                -- exists to prevent.
                CONSTRAINT jobs_running_holds_a_lease
                    CHECK ((status = 'RUNNING') = (leased_until IS NOT NULL)),
                CONSTRAINT jobs_finished_has_moment CHECK (
                    status IN ('QUEUED', 'RUNNING') OR finished_at IS NOT NULL
                ),
                CONSTRAINT jobs_failed_has_reason
                    CHECK (status <> 'FAILED' OR failure_reason IS NOT NULL)
            )
            SQL);

        // The claim query's index: eligible work, best first.
        $this->addSql(<<<'SQL'
            CREATE INDEX jobs_claimable_idx
                ON jobs (status, run_after, priority DESC, created_at)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX jobs_tenant_idx ON jobs (tenant_id, product_id, created_at DESC)
            SQL);

        // At most one pending job per key. Partial, so a finished job does not
        // block the next one of its kind — "sweep expired quotes" must be
        // enqueueable tomorrow, just not twice today.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX jobs_pending_key_unique
                ON jobs (type, idempotency_key)
             WHERE idempotency_key IS NOT NULL AND status IN ('QUEUED', 'RUNNING')
            SQL);

        // What R10 asks for: "when did the runner last do anything?" A queue
        // that is quiet and a cron that has stopped firing look identical
        // from the outside, and only this table tells them apart.
        $this->addSql(<<<'SQL'
            CREATE TABLE job_runs (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                finished_at TIMESTAMPTZ,
                claimed INTEGER NOT NULL DEFAULT 0,
                succeeded INTEGER NOT NULL DEFAULT 0,
                failed INTEGER NOT NULL DEFAULT 0,
                CONSTRAINT job_runs_counts_not_negative
                    CHECK (claimed >= 0 AND succeeded >= 0 AND failed >= 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX job_runs_recent_idx ON job_runs (started_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('jobs.read', 'Read the tenant''s jobs'),
                ('jobs.manage', 'Request a job and cancel one')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code IN ('TENANT_ADMIN', 'USER') AND p.code = 'jobs.read')
               OR (r.code = 'TENANT_ADMIN' AND p.code = 'jobs.manage')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.jobs.read', 'Read the queue across the platform'),
                ('staff.jobs.manage', 'Enqueue and cancel platform-wide jobs')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
            FROM platform_roles r
            CROSS JOIN platform_permissions p
            WHERE r.code = 'PLATFORM_ADMIN'
              AND p.code IN ('staff.jobs.read', 'staff.jobs.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
            WHERE platform_permission_id IN (
                SELECT id FROM platform_permissions
                 WHERE code IN ('staff.jobs.read', 'staff.jobs.manage')
            )
            SQL);
        $this->addSql(
            "DELETE FROM platform_permissions WHERE code IN ('staff.jobs.read', 'staff.jobs.manage')",
        );
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('jobs.read', 'jobs.manage')
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('jobs.read', 'jobs.manage')");
        $this->addSql('DROP TABLE IF EXISTS job_runs');
        $this->addSql('DROP TABLE IF EXISTS jobs');
    }
}
