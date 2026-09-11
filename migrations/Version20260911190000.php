<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The catalogue belongs to the platform, and a tenant edits it only when the
 * platform says so.
 *
 * `offers` have always been keyed on `product_id` — they are the platform's
 * price list, not a customer's document — and `catalog.manage` has always
 * been a *tenant* permission held by every TENANT_ADMIN. Those two facts
 * together meant any customer's administrator could rewrite the prices every
 * other customer of that product is sold on. Nothing in the schema stopped
 * it; the platform simply had one tenant and nobody had noticed.
 *
 * **`tenants.may_author_offers`, default false.** The catalogue is the
 * platform's until the platform delegates it, and the delegation is per
 * tenant because "our reseller maintains their own price list" is a real
 * arrangement and "every customer may edit everyone's prices" is not.
 *
 * The flag is not a second check bolted beside `catalog.manage` — it decides
 * whether that permission is *resolved at all*, in the one query that turns
 * a membership into permissions. A check placed beside the permission would
 * have to be repeated on every authoring route and remembered on the next
 * one; a permission that is simply absent refuses every route that asks for
 * it, including routes not yet written, and hides the authoring controls in
 * the UI without a line of frontend code.
 *
 * `staff.tenants.manage` is what may flip it: PLATFORM_ADMIN alone, because
 * a support engineer able to hand a customer the price list is a support
 * engineer able to change what every other customer pays.
 */
final class Version20260911190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Offer authoring is delegated per tenant, by the platform, and off by default';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT false, so every tenant that exists when this runs loses an
        // authority it should never have had. On a single-tenant deployment
        // that is the owner's own tenant, and the console hands it back in one
        // click — which is the point of the flag being visible rather than a
        // column somebody has to know about.
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
                ADD COLUMN may_author_offers boolean NOT NULL DEFAULT false
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.tenants.manage', 'Change what a tenant is allowed to do')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.tenants.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.tenants.manage'
                   )
            SQL);

        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.tenants.manage'");

        // Dropping the column restores the state this migration exists to fix
        // — every tenant administrator authoring the platform's catalogue —
        // so it is dropped only as part of undoing the whole change.
        $this->addSql('ALTER TABLE tenants DROP COLUMN IF EXISTS may_author_offers');
    }
}
