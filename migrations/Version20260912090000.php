<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A product can be created after the installer has run.
 *
 * Products are the top of this platform's model — a tenant is a tenant *of* a
 * product, an offer is priced *for* one, a membership is per product — and
 * until now exactly one thing could bring one into existence:
 * `deploy/siteground/setup.php`, once, at install. There was no endpoint, no
 * screen and no permission for the act. A deployment that wanted a second
 * product had an `INSERT` typed against production, which is the situation
 * ADR-039 removed for staff and never removed here.
 *
 * Worse for whoever was already running one: nothing anywhere would tell them
 * which products existed. `GET /api/v1/products` resolves through membership
 * (`reachableBy` joins `tenant_members`), and a platform role never grants
 * membership (non-negotiable #22) — so an administrator asking "what is on
 * this platform?" was answered with their own tenant's product or with
 * nothing at all.
 *
 * `staff.products.manage` is what the console holds to answer it, and
 * PLATFORM_ADMIN alone holds that: a product is the unit every tenant,
 * catalogue and invoice hangs from, and switching one off is the widest
 * single act available on this platform.
 */
final class Version20260912090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The console can see and create products, which until now only the installer could';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO platform_permissions (code, description) VALUES
                ('staff.products.manage', 'See every product on the platform, and create or retire one')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO platform_role_permissions (platform_role_id, platform_permission_id)
            SELECT r.id, p.id
              FROM platform_roles r
              CROSS JOIN platform_permissions p
             WHERE r.code = 'PLATFORM_ADMIN'
               AND p.code = 'staff.products.manage'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM platform_role_permissions
             WHERE platform_permission_id IN (
                       SELECT id FROM platform_permissions WHERE code = 'staff.products.manage'
                   )
            SQL);

        $this->addSql("DELETE FROM platform_permissions WHERE code = 'staff.products.manage'");
    }
}
