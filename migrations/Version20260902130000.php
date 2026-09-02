<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The platform identity tables: users, products, tenants and membership.
 *
 * SQL is hand-written (ADR-016) so the PostgreSQL features this schema
 * depends on — array columns, check constraints, gen_random_uuid() — are
 * visible in review rather than hidden behind a portable abstraction.
 *
 * `products` is created here, ahead of the product registry in M3, because
 * membership is per product (§12.1) and tenant_members needs the foreign key
 * to exist. M3 extends this table with catalogue, features and configuration.
 */
final class Version20260902130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform identity: users, products, tenants, tenant_members';
    }

    public function up(Schema $schema): void
    {
        // Provisioned just-in-time from the identity provider (ADR-017).
        // auth_subject is the provider's subject claim; the internal id is
        // what every other table references, so changing provider is a data
        // migration of one column rather than of every foreign key.
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                auth_subject TEXT NOT NULL,
                email TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT users_auth_subject_unique UNIQUE (auth_subject),
                CONSTRAINT users_auth_subject_not_blank CHECK (btrim(auth_subject) <> '')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE products (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT products_code_unique UNIQUE (code),
                CONSTRAINT products_code_not_blank CHECK (btrim(code) <> '')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tenants (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT tenants_slug_unique UNIQUE (slug),
                CONSTRAINT tenants_slug_not_blank CHECK (btrim(slug) <> '')
            )
            SQL);

        // Membership is (tenant, user, product): §12.1 lets a tenant use one
        // or several products, and a user may administer a tenant in one
        // product while having no access to it in another.
        //
        // Roles are constrained in the database as well as in code: a role
        // string that no longer exists must not be silently authoritative.
        // Platform-wide roles (FINANCE_ADMIN and the rest of §25.2) are not
        // tenant membership and arrive with the admin surface in M8.
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_members (
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                roles TEXT[] NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (tenant_id, user_id, product_id),
                CONSTRAINT tenant_members_roles_not_empty CHECK (cardinality(roles) > 0),
                CONSTRAINT tenant_members_roles_known CHECK (roles <@ ARRAY['TENANT_ADMIN', 'USER']::TEXT[])
            )
            SQL);

        // Every protected request resolves membership by (user, product).
        $this->addSql('CREATE INDEX tenant_members_user_product_idx ON tenant_members (user_id, product_id)');
    }

    public function down(Schema $schema): void
    {
        // Safe to reverse only because nothing has depended on these tables
        // yet. Later migrations that drop or rewrite populated columns will
        // omit down() rather than pretend the data can be restored (ADR-016).
        $this->addSql('DROP TABLE IF EXISTS tenant_members');
        $this->addSql('DROP TABLE IF EXISTS tenants');
        $this->addSql('DROP TABLE IF EXISTS products');
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
