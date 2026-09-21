<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A product beside the platform as a caller of its own (2026-09-21, ADR-051
 * milestone C).
 *
 * Three tables. `product_credentials` is the key a product's server holds:
 * the public half in the header, the secret's SHA-256 at rest (the secret is
 * 256 random bits, so a fast hash is the right one — the same reasoning as
 * the refresh tokens'), the scopes it may use, and when it was issued,
 * expires, was revoked and was last seen. `product_access_log` is every
 * crossing of a tenant boundary by a key, for the reason `staff_access_log`
 * exists: an actor that is not a person still reads a customer's data, and
 * "did the product read this tenant?" has to have an answer. `product_usage`
 * is what a product metered against a quota: signed deltas whose sum is the
 * level, once per idempotency key, because a retried job must not count twice.
 */
final class Version20260921090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product keys, the product access log, and metered usage reported by a product';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE product_credentials (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                key_id TEXT NOT NULL UNIQUE,
                secret_hash TEXT NOT NULL,
                scopes TEXT[] NOT NULL,
                label TEXT NOT NULL,
                created_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                expires_at TIMESTAMPTZ,
                revoked_at TIMESTAMPTZ,
                last_used_at TIMESTAMPTZ,
                CONSTRAINT product_credentials_label_not_blank CHECK (btrim(label) <> ''),
                CONSTRAINT product_credentials_scopes_known
                    CHECK (scopes <@ ARRAY['product.usage.write', 'product.entitlements.read', 'product.members.read']::text[])
            )
            SQL);
        $this->addSql('CREATE INDEX product_credentials_product ON product_credentials (product_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE product_access_log (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                credential_id UUID NOT NULL REFERENCES product_credentials (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                tenant_id UUID REFERENCES tenants (id) ON DELETE SET NULL,
                asked_for TEXT NOT NULL,
                method TEXT NOT NULL,
                path TEXT NOT NULL,
                status INTEGER NOT NULL,
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
        $this->addSql('CREATE INDEX product_access_log_product_time ON product_access_log (product_id, occurred_at DESC)');

        $this->addSql(<<<'SQL'
            CREATE TABLE product_usage (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                credential_id UUID REFERENCES product_credentials (id) ON DELETE SET NULL,
                feature TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                period_start DATE,
                period_end DATE,
                idempotency_key TEXT NOT NULL,
                recorded_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT product_usage_feature_not_blank CHECK (btrim(feature) <> ''),
                CONSTRAINT product_usage_once UNIQUE (tenant_id, product_id, feature, idempotency_key)
            )
            SQL);
        $this->addSql('CREATE INDEX product_usage_level ON product_usage (tenant_id, product_id, feature)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS product_usage');
        $this->addSql('DROP TABLE IF EXISTS product_access_log');
        $this->addSql('DROP TABLE IF EXISTS product_credentials');
    }
}
