<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a stranger may see of the catalogue.
 *
 * Until now every route that reads an offer required a membership, which made
 * the question moot: the only people who could see a price were people who
 * already had an account. A public storefront changes that, and "on sale"
 * stops being the same question as "advertised".
 *
 * They are genuinely different. An offer negotiated with one reseller, a
 * grandfathered price a subscription still renews on, a plan sold only by the
 * sales desk — all are legitimately on sale and none belong on a page anybody
 * can open. The window a version carries says *when* it may be sold; it says
 * nothing about *to whom it may be shown*, and conflating the two would put
 * every private arrangement on the front page the moment the storefront
 * shipped.
 *
 * **`offers.publicly_listed`, default false**, so shipping the storefront
 * advertises nothing that was not deliberately advertised. Every offer that
 * exists when this runs stays exactly as reachable as it is today — through
 * the catalogue, to members — and appears publicly only once somebody says so.
 *
 * `staff.catalog.manage` is what says so, and PLATFORM_ADMIN alone holds it.
 * Deliberately *not* `catalog.manage`: ADR-040 lets the platform lend that
 * permission to a tenant, and a tenant authoring its own offers must not
 * thereby decide what the platform's public page advertises. The two
 * authorities separate here for the same reason they separated there.
 */
final class Version20260911210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An offer is advertised publicly only when the platform says so';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE offers
                ADD COLUMN publicly_listed boolean NOT NULL DEFAULT false
            SQL);

        // The storefront asks one question — "what may I show a stranger for
        // this product?" — and asks it on every page load, from callers with
        // no session to rate-limit by account. Partial, because the rows it
        // must not return are the overwhelming majority and there is no point
        // indexing them.
        $this->addSql(<<<'SQL'
            CREATE INDEX offers_publicly_listed_idx
                ON offers (product_id)
             WHERE publicly_listed
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.catalog.manage', 'Decide which offers the public storefront advertises')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.catalog.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.catalog.manage'
                   )
            SQL);

        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.catalog.manage'");

        $this->addSql('DROP INDEX IF EXISTS offers_publicly_listed_idx');

        // Dropping the column makes every offer unadvertised rather than
        // advertised, because the storefront route goes with it. Reversing
        // this migration alone would leave a public page selecting a column
        // that no longer exists, which is why it is reversed as part of
        // undoing the storefront and not on its own.
        $this->addSql('ALTER TABLE offers DROP COLUMN IF EXISTS publicly_listed');
    }
}
