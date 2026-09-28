<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A band has a heading of its own, and two new bands exist (2026-09-28,
 * `docs/home-showcase-spec.md` §7).
 *
 * **Why a table and not more fields on a row.** A band's eyebrow and title
 * are one fact per band; its rows are another. `product_showcase` is one row
 * per *row* — three steps are three rows — so putting the band's title on
 * one of them would mean editing step one to rename the section, and would
 * leave the title homeless the moment somebody deleted that step.
 *
 * `PRICING` settles it outright: it has **no rows at all** and never will,
 * because a row for it would be a row somebody could type a price into (§9).
 * It still needs a title. Nothing that lives on a row can carry it.
 *
 * So: one row per `(product, band)`, mirroring `product_showcase` and its
 * translations exactly — the English is the key and the fallback, the other
 * four languages sit beside it, and the translation desk counts both the
 * same way.
 *
 * **Two new bands.** `PROBLEM` names what the reader lives with, in a few
 * short points; `QUOTE` carries somebody saying it worked. Neither fitted
 * what was there: a problem is not a numbered `STEPS` sequence, and it is
 * not a `USE_CASE`, which is a before and an after about one person.
 *
 * `PROBLEM.icon` is deliberately **not a sentence**: it names one of a closed
 * set the frontend draws, so it is refused in a translation the way a
 * price is refused in a band — an icon has no French.
 */
final class Version20260928150000 extends AbstractMigration
{
    /** The sections of the page, `PRICING` included: it has a heading too. */
    private const SECTIONS = "'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'PROOF', 'PRICING', 'QUESTION'";

    /** The kinds an operator writes rows for — `PRICING` is not one. */
    private const WRITABLE = "'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'PROOF', 'QUESTION'";

    public function getDescription(): string
    {
        return 'A showcase band carries its own heading, and gains a problem band and a quote band';
    }

    public function up(Schema $schema): void
    {
        // The two new row kinds join the ones an operator may write.
        $this->addSql('ALTER TABLE product_showcase DROP CONSTRAINT product_showcase_block_known');
        $this->addSql(sprintf(
            'ALTER TABLE product_showcase ADD CONSTRAINT product_showcase_block_known CHECK (block IN (%s))',
            self::WRITABLE,
        ));

        // And the order of the page may now name them.
        $this->addSql('ALTER TABLE products DROP CONSTRAINT products_showcase_sections_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_showcase_sections_known CHECK (
                showcase_sections IS NULL OR (
                    jsonb_typeof(showcase_sections) = 'array'
                    AND showcase_sections <@ '["HEADLINE","PROBLEM","STEPS","USE_CASE","QUOTE","PROOF","PRICING","QUESTION"]'::jsonb
                )
            )
            SQL);

        $this->addSql(sprintf(<<<'SQL'
            CREATE TABLE product_showcase_bands (
                product_id  uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                block       text NOT NULL,
                content     jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (product_id, block),
                CONSTRAINT product_showcase_bands_block_known CHECK (block IN (%s)),
                CONSTRAINT product_showcase_bands_content_is_object
                    CHECK (jsonb_typeof(content) = 'object')
            )
            SQL, self::SECTIONS));

        $this->addSql(<<<'SQL'
            CREATE TABLE product_showcase_band_translations (
                product_id  uuid NOT NULL,
                block       text NOT NULL,
                locale      text NOT NULL,
                content     jsonb NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (product_id, block, locale),
                FOREIGN KEY (product_id, block)
                    REFERENCES product_showcase_bands(product_id, block) ON DELETE CASCADE,
                CONSTRAINT product_showcase_band_translations_locale_known
                    CHECK (locale IN ('fr', 'es', 'de', 'it')),
                CONSTRAINT product_showcase_band_translations_is_object
                    CHECK (jsonb_typeof(content) = 'object')
            )
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE product_showcase_bands IS
                'A showcase band''s own heading (2026-09-28): its eyebrow, its title and its lede, one row per (product, band). Different from product_showcase, which is one row per row of a band — three steps are three rows there and one row here. PRICING appears here and never there: it has a heading and no rows, because a row for it would be a row somebody could type a price into.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_showcase_band_translations');
        $this->addSql('DROP TABLE product_showcase_bands');

        // Rows of the two new kinds go with them, or the old CHECK refuses.
        $this->addSql("DELETE FROM product_showcase WHERE block IN ('PROBLEM', 'QUOTE')");
        $this->addSql('ALTER TABLE product_showcase DROP CONSTRAINT product_showcase_block_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE product_showcase ADD CONSTRAINT product_showcase_block_known
                CHECK (block IN ('HEADLINE', 'STEPS', 'USE_CASE', 'PROOF', 'QUESTION'))
            SQL);

        $this->addSql("UPDATE products SET showcase_sections = NULL WHERE showcase_sections ?| array['PROBLEM', 'QUOTE']");
        $this->addSql('ALTER TABLE products DROP CONSTRAINT products_showcase_sections_known');
        $this->addSql(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_showcase_sections_known CHECK (
                showcase_sections IS NULL OR (
                    jsonb_typeof(showcase_sections) = 'array'
                    AND showcase_sections <@ '["HEADLINE","STEPS","USE_CASE","PROOF","PRICING","QUESTION"]'::jsonb
                )
            )
            SQL);
    }
}
