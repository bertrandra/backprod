<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The product's story, in rows (2026-09-24, step 3 of
 * `docs/home-showcase-spec.md`).
 *
 * `/` is the product's story since this morning and the story was the
 * product's name and nothing else, because there was nowhere to put one. A
 * component carrying Plan's headline would be one product's fact baked into
 * a shell shared by all of them — `gate:products` in PHP, moved somewhere
 * the backend gates cannot see it. The words are **data about a product**.
 *
 * **A row per band, not one document.** The console edits one band at a
 * time, a missing picture must not take a headline with it, and `position`
 * orders the several rows a band may hold — three steps, two use cases,
 * four questions. In tens, `display_order`'s habit, so one can be slipped
 * between two others without renumbering anybody.
 *
 * **`PRICING` is not a block kind**, deliberately: it is a position in the
 * order and reads the catalogue (spec §4). An enum value for it would be a
 * form the console must never offer, and a row somebody could write a price
 * into — which is the one thing §9 forbids outright.
 *
 * **The translations are a table of their own**, like `feature_translations`
 * and `offer_translations` before them (ADR-050, ADR-052): the English is on
 * the block and is the key and the fallback, and a polymorphic `entity_id`
 * would carry no foreign key at all.
 *
 * **Publishing is a state on the product**, not on a block. A page is
 * published whole or not at all — a half-published story is a shop window
 * with a sentence missing from the middle — and the public read answers 404
 * while it is null, rather than an empty page advertising that a product
 * exists and has nothing to say.
 */
final class Version20260924130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "A product's story: blocks, their translations, and when it was published";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE product_showcase (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id  uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                block       text NOT NULL,
                position    integer NOT NULL DEFAULT 10,
                content     jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT product_showcase_block_known
                    CHECK (block IN ('HEADLINE', 'STEPS', 'USE_CASE', 'PROOF', 'QUESTION')),
                CONSTRAINT product_showcase_position_positive CHECK (position > 0),
                CONSTRAINT product_showcase_content_is_object
                    CHECK (jsonb_typeof(content) = 'object')
            )
            SQL);

        // One headline per product, said by the database rather than by a
        // service: a hero is the page's first frame and there is one of it.
        // A partial index, because it is the only block kind that is unique.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX product_showcase_one_headline
                ON product_showcase (product_id)
             WHERE block = 'HEADLINE'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX product_showcase_ordered
                ON product_showcase (product_id, block, position)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE product_showcase_translations (
                block_id    uuid NOT NULL REFERENCES product_showcase(id) ON DELETE CASCADE,
                locale      text NOT NULL,
                content     jsonb NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (block_id, locale),
                CONSTRAINT product_showcase_translations_locale_known
                    CHECK (locale IN ('fr', 'es', 'de', 'it')),
                CONSTRAINT product_showcase_translations_is_object
                    CHECK (jsonb_typeof(content) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE product_showcase IS
                'What a product says about itself on its own page (2026-09-24), one row per band. The English lives here and is the key and the fallback; the other four languages are in product_showcase_translations. PRICING is not a block kind: it reads the catalogue.'
            SQL);

        // Null is a draft, and the public read answers 404 for it. Not a
        // boolean: "when" is a fact somebody will want, and a boolean would
        // have to be widened into this the first time they ask.
        $this->addSql('ALTER TABLE products ADD COLUMN showcase_published_at timestamptz');

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN products.showcase_published_at IS
                'When the product s story was published. Null is a draft, and the public read answers 404 rather than an empty page. A retired product keeps this: retiring stops the selling, not the record (home-showcase-spec §11.3).'
            SQL);

        // **No permission of its own.** `staff.products.manage` writes this,
        // as the operator decided (home-showcase-spec §11.1): the story sits
        // beside a product's other settings and is written by whoever
        // creates the product. A `staff.showcase.manage` would be arguable —
        // marketing copy and retiring a product are different trusts, which
        // is exactly the argument ADR-052 made for features — but that is a
        // decision to take out loud, not one to slip into a migration.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS product_showcase_translations');
        $this->addSql('DROP TABLE IF EXISTS product_showcase');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS showcase_published_at');
    }
}
