<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An organisation says which product its people open on (2026-09-26).
 *
 * Which product a signed-in person lands in has been decided by four things,
 * in this order:
 *
 * ```text
 * ?product=                      an address saying which one is meant now
 * localStorage                   what this browser last used
 * users.default_product_id       the person's own, from their profile
 * VITE_DEFAULT_PRODUCT           a constant compiled into the bundle
 * ```
 *
 * The last one is the problem, and the operator met it: a bundle built
 * without `DEFAULT_PRODUCT` opened on whichever product sorts first, so a
 * deployment that leads with Plan showed Atlas, and the only way to change it
 * was to rebuild. A tenant holds several products (ADR-047) and only its
 * administrator knows which one it leads with — that is a setting, not a
 * build flag.
 *
 * So `tenants.default_product_id`, between the person's own and the
 * constant: an administrator's answer for everybody who has not given their
 * own, and never over one who has. A person who chose a product on their
 * profile keeps it, and an address still wins over both.
 *
 * **A plain key to `products`, and not the composite one to
 * `tenant_products`.** The composite — `(id, default_product_id)` referencing
 * `(tenant_id, product_id)` — was written first, because it makes "only a
 * product this tenant holds" a thing the database refuses rather than a
 * thing a service remembers. It also makes `tenants` and `tenant_products`
 * reference each other, and a cycle in the schema is not free: the test
 * harness computes a deletion order from the foreign keys and falls back to
 * `TRUNCATE … CASCADE` when it cannot find one. With the composite key that
 * order comes back **empty**, so every reset in the suite takes the slow
 * path — the one measured at twenty minutes against three on the operator's
 * machine and deliberately abandoned on 2026-09-20.
 *
 * The invariant is kept without it, in three places that each fail safe:
 *
 * ```text
 * writing    the UPDATE is guarded by EXISTS on tenant_products, so a
 *            product the tenant does not hold changes nothing and the
 *            service answers 400
 * unassign   clears the default in the same transaction, so it cannot
 *            outlive the assignment
 * reading    the shell only honours a default among the products the
 *            person actually holds, so a stale one is ignored, never obeyed
 * ```
 *
 * `ON DELETE SET NULL` on the product itself: deleting a product clears
 * every default that named it, rather than refusing the deletion. A
 * preference must not block an administrative act.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A tenant names the product its people open on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
                ADD COLUMN default_product_id uuid REFERENCES products (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN tenants.default_product_id IS
                'The product this organisation opens on when neither the address nor the person says otherwise (2026-09-26). Written only when the tenant holds it, cleared when the assignment goes, and ignored on read if it ever names one the person does not hold. NULL means no answer, and the deployment''s own default decides.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible: nothing is renamed and no row is destroyed. Going back
        // takes away an administrator's answer, which is the state this
        // migration found — the bundle's constant decides again.
        $this->addSql('ALTER TABLE tenants DROP COLUMN IF EXISTS default_product_id');
    }
}
