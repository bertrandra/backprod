<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A tenant has products, and the platform says which (ADR-047).
 *
 * Until now the only link between a tenant and a product was the membership
 * triple `tenant_members (tenant_id, user_id, product_id)`: a tenant "had" a
 * product when somebody happened to be a member of it for that product. No
 * row said so, nobody could assign one, and a tenant wanting a second product
 * had an `INSERT` typed against production — the situation ADR-039 and
 * ADR-042 removed for staff and products, still open here.
 *
 * **`tenant_products` is the assignment**, written by the platform's console
 * behind `staff.tenants.manage`, and every existing (tenant, product) pair a
 * membership already implies is backfilled so nothing that works today stops.
 *
 * **A membership's product must be one the tenant was given.** The foreign
 * key from `tenant_members` onto `tenant_products` is what says so, and it
 * cascades: withdrawing a product from a tenant removes the memberships in
 * it, and `tenant_member_roles` follows through the cascade it already has
 * (Version20260902140000). The schema of `tenant_members` does not change —
 * a membership stays per product, because every table this platform isolates
 * by is keyed on (tenant, product) and the resolver reads it that way — but
 * its meaning does: a person is a member of the *tenant*, and the platform
 * mirrors that membership onto every product the tenant holds.
 */
final class Version20260915090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A tenant has products assigned by the platform, and memberships live inside that assignment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_products (
                tenant_id   UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id  UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                assigned_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                -- NULL when nobody on staff decided it: sign-up and the
                -- installer assign the product the account was created for.
                assigned_by UUID REFERENCES users (id) ON DELETE SET NULL,
                PRIMARY KEY (tenant_id, product_id)
            )
            SQL);

        // The unassign path asks "does anything still hang off this product
        // here?" and the product side asks "which tenants have it?".
        $this->addSql('CREATE INDEX tenant_products_product_idx ON tenant_products (product_id)');

        // Every pair a membership already implies, so the constraint below
        // holds for the rows that exist and nobody loses access on upgrade.
        $this->addSql(<<<'SQL'
            INSERT INTO tenant_products (tenant_id, product_id)
            SELECT DISTINCT tenant_id, product_id FROM tenant_members
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_members
                ADD CONSTRAINT tenant_members_product_assigned_fk
                FOREIGN KEY (tenant_id, product_id)
                REFERENCES tenant_products (tenant_id, product_id)
                ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The table could be dropped and re-derived from memberships, but an
        // assignment with no member yet — a product given to a tenant before
        // anybody was invited into it — is exactly what would be lost, and
        // a rollback that silently loses a decision is worse than none.
        // ADR-016: a rollback in production means restoring a backup.
        $this->throwIrreversibleMigrationException(
            'tenant_products carries assignments no membership implies; restore a backup instead (ADR-016).',
        );
    }
}
