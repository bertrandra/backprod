<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The product registry: what each product offers and how it is configured.
 *
 * §12.1 requires a new product to be addable with configuration and modules
 * rather than by cloning the backend. These tables are the configuration half
 * of that: features and settings are rows, so adding a product is an insert,
 * not a branch.
 */
final class Version20260902150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product features and per-product configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE product_features (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT product_features_unique UNIQUE (product_id, code),
                CONSTRAINT product_features_code_not_blank CHECK (btrim(code) <> '')
            )
            SQL);

        // JSONB rather than text: configuration values are structured (limits,
        // toggles, nested settings), and PostgreSQL is the reason we can store
        // them without inventing an encoding (ADR-009).
        $this->addSql(<<<'SQL'
            CREATE TABLE product_configuration (
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE CASCADE,
                key TEXT NOT NULL,
                value JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (product_id, key),
                CONSTRAINT product_configuration_key_not_blank CHECK (btrim(key) <> '')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Safe: these tables are new and nothing else references them.
        $this->addSql('DROP TABLE IF EXISTS product_configuration');
        $this->addSql('DROP TABLE IF EXISTS product_features');
    }
}
