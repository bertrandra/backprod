<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A product says what it gates on, and may not invent it (2026-09-24, step
 * 5 of `docs/translatable-fields-spec.md`).
 *
 * `product_features` says what a product has *built*; `features` says what
 * may be *sold*. The two were never connected, so `plan.terrase` typed once
 * on either side was a capability the other would never honour — silently,
 * because nothing compared them.
 *
 * ADR-052 made `features.code` unique platform-wide, which is what finally
 * makes the connection expressible: a built capability names a word on the
 * platform's list, by foreign key. A program may tell the platform what it
 * gates on and may not add to the vocabulary on its own — that is
 * `staff.features.manage`, held by a person.
 *
 * `ON UPDATE CASCADE` for the reason a code never changes: it cannot, and
 * should the platform ever allow it, a built capability must follow rather
 * than dangle. `ON DELETE RESTRICT` is implicit and wanted — a feature
 * something is built against is one more reason it cannot be deleted.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "A product's built capabilities name the platform's features, and a key may declare them";
    }

    public function up(Schema $schema): void
    {
        // Rows predating the rule, if any: a built capability naming nothing
        // on the list is one nothing could ever grant, so the platform learns
        // the word rather than the row being discarded. Kind BOOLEAN, because
        // a capability a program gates on is something you have or do not —
        // a quota is declared by a person, with the unit it is counted in.
        $this->addSql(<<<'SQL'
            INSERT INTO features (code, name, kind)
            SELECT DISTINCT pf.code, pf.code, 'BOOLEAN'
              FROM product_features pf
             WHERE NOT EXISTS (SELECT 1 FROM features f WHERE f.code = pf.code)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE product_features
                ADD CONSTRAINT product_features_code_known
                FOREIGN KEY (code) REFERENCES features (code) ON UPDATE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE product_features IS
                'What a product has built, by a code from the platform s one list of features (2026-09-24). Distinct from what may be sold: a product may ship something it does not sell, and sell something it has not shipped.'
            SQL);

        // A fourth scope, so a product's own server can declare the above.
        // Narrow on purpose: a key holding it writes this product's built
        // capabilities and reads nothing.
        $this->addSql('ALTER TABLE product_credentials DROP CONSTRAINT product_credentials_scopes_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE product_credentials
                ADD CONSTRAINT product_credentials_scopes_known
                CHECK (scopes <@ ARRAY[
                    'product.usage.write',
                    'product.entitlements.read',
                    'product.members.read',
                    'product.capabilities.write'
                ]::text[])
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_features DROP CONSTRAINT IF EXISTS product_features_code_known');
        $this->addSql('ALTER TABLE product_credentials DROP CONSTRAINT product_credentials_scopes_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE product_credentials
                ADD CONSTRAINT product_credentials_scopes_known
                CHECK (scopes <@ ARRAY['product.usage.write', 'product.entitlements.read', 'product.members.read']::text[])
            SQL);
    }
}
