<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An entitlement the platform gives, without a sale (docs/tenant-roots.md §2.8).
 *
 * A tenant *holds* a product by staff assignment (ADR-047) and *uses* it by
 * subscription: `entitlements` rows written when an offer version is
 * activated. A pilot, a partner, an internal organisation or the operator's
 * own default tenant has nothing to buy — so the platform administrator may
 * grant the entitlement directly: which features, with what limits, until
 * when, and by whom.
 *
 * `GRANT` is a third source beside SUBSCRIPTION and OVERRIDE. It names no
 * subscription (the constraint says so), records who granted it, and is
 * read by the resolver exactly as the other two are — the §10.6 chain asks
 * "what is in force", not "who paid". One grant per feature per tenant and
 * product, so replacing a grant is a delete and an insert rather than a
 * merge.
 */
final class Version20260917180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'entitlements.source GRANT, with granted_by';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entitlements DROP CONSTRAINT entitlements_source_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE entitlements
                ADD CONSTRAINT entitlements_source_known CHECK (source IN ('SUBSCRIPTION', 'OVERRIDE', 'GRANT')),
                ADD COLUMN granted_by UUID REFERENCES users (id) ON DELETE SET NULL,
                ADD CONSTRAINT entitlements_grant_names_no_subscription
                    CHECK (source <> 'GRANT' OR subscription_id IS NULL)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX entitlements_one_grant_per_feature
                ON entitlements (tenant_id, product_id, feature_id)
                WHERE source = 'GRANT'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM entitlements WHERE source = 'GRANT'");
        $this->addSql('DROP INDEX entitlements_one_grant_per_feature');
        $this->addSql(<<<'SQL'
            ALTER TABLE entitlements
                DROP CONSTRAINT entitlements_grant_names_no_subscription,
                DROP COLUMN granted_by,
                DROP CONSTRAINT entitlements_source_known,
                ADD CONSTRAINT entitlements_source_known CHECK (source IN ('SUBSCRIPTION', 'OVERRIDE'))
            SQL);
    }
}
