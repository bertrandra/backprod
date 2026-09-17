<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * People arrive at an organisation by themselves, and the organisation
 * decides how (2026-09-17).
 *
 * Sign-up used to make an organisation with the person as its administrator.
 * Now it asks to join the organisation at the URL root, as a USER, and the
 * organisation's **join policy** decides: by invitation only, by the email's
 * domain, or after an administrator's approval. Approval is the default,
 * because an open door at `hostname/acme/` would let any stranger become a
 * member of acme by typing an address — a tenant-isolation hole, not a
 * convenience. There is no OPEN policy.
 *
 * A membership therefore has a status. PENDING is not a membership: the
 * resolver ignores it, the products list ignores it, and the only thing it
 * grants is a place on the administrator's list of requests.
 */
final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Join policies and pending memberships: self-service arrival as USER';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
                ADD COLUMN join_policy TEXT NOT NULL DEFAULT 'APPROVAL',
                ADD CONSTRAINT tenants_join_policy_known CHECK (join_policy IN ('INVITATION', 'DOMAIN', 'APPROVAL'))
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_join_domains (
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                domain TEXT NOT NULL,
                PRIMARY KEY (tenant_id, domain),
                CONSTRAINT tenant_join_domains_is_domain CHECK (domain ~ '^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$')
            )
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_members
                ADD COLUMN status TEXT NOT NULL DEFAULT 'ACTIVE',
                ADD COLUMN requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                ADD CONSTRAINT tenant_members_status_known CHECK (status IN ('ACTIVE', 'PENDING'))
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX tenant_members_pending_idx
                ON tenant_members (tenant_id, requested_at)
             WHERE status = 'PENDING'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS tenant_members_pending_idx');
        $this->addSql("DELETE FROM tenant_members WHERE status = 'PENDING'");
        $this->addSql('ALTER TABLE tenant_members DROP CONSTRAINT tenant_members_status_known, DROP COLUMN status, DROP COLUMN requested_at');
        $this->addSql('DROP TABLE tenant_join_domains');
        $this->addSql('ALTER TABLE tenants DROP CONSTRAINT tenants_join_policy_known, DROP COLUMN join_policy');
    }
}
