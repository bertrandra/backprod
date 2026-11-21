<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Four bands that sell: who trusts it, its numbers, what it includes, and a
 * last call to action (2026-11-20).
 *
 * The page could say what a product does and show screenshots of it, and had
 * nowhere to put what a hesitating reader looks for next: a name they
 * recognise (`TRUST`), a figure (`STATS`), a list of what they get
 * (`FEATURE`) — and nothing after the questions but the end of the page
 * (`CTA`).
 *
 * Three constraints name the kinds, and all three widen together, exactly as
 * they did for `DEMO`: the rows a band may have, the bands that may carry a
 * heading, and the sections an order may name. Widening only the first is the
 * mistake that makes a band writable and unplaceable.
 *
 * **One call to action per page**, by index and not by a check, for the
 * reason there is one headline by index: two saves at once would both pass a
 * check. `ShowcaseBlocks` says it first, in words; this is what holds.
 *
 * A band's layout needs nothing here. It is a code stored in
 * `product_showcase_bands.content`, beside the heading, and that column is
 * already an object nothing constrains the keys of — the endpoint is where a
 * band's shape is guarded (`ShowcaseBandHeadings`).
 */
final class Version20261120090000 extends AbstractMigration
{
    private const WRITABLE = "'HEADLINE', 'TRUST', 'PROBLEM', 'STATS', 'STEPS', 'FEATURE', 'USE_CASE', 'QUOTE', 'PROOF', 'DEMO', 'QUESTION', 'CTA'";

    private const SECTIONS = "'HEADLINE', 'TRUST', 'PROBLEM', 'STATS', 'STEPS', 'FEATURE', 'USE_CASE', 'QUOTE', 'PROOF', 'DEMO', 'PRICING', 'QUESTION', 'CTA'";

    private const SECTIONS_JSON = '"HEADLINE", "TRUST", "PROBLEM", "STATS", "STEPS", "FEATURE", "USE_CASE", "QUOTE", "PROOF", "DEMO", "PRICING", "QUESTION", "CTA"';

    public function getDescription(): string
    {
        return 'TRUST, STATS, FEATURE and CTA: four showcase bands that sell, and one call to action per page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_showcase DROP CONSTRAINT product_showcase_block_known');
        $this->addSql(
            'ALTER TABLE product_showcase ADD CONSTRAINT product_showcase_block_known'
            . ' CHECK (block IN (' . self::WRITABLE . '))',
        );

        $this->addSql('ALTER TABLE product_showcase_bands DROP CONSTRAINT product_showcase_bands_block_known');
        $this->addSql(
            'ALTER TABLE product_showcase_bands ADD CONSTRAINT product_showcase_bands_block_known'
            . ' CHECK (block IN (' . self::SECTIONS . '))',
        );

        $this->addSql('ALTER TABLE products DROP CONSTRAINT products_showcase_sections_known');
        $this->addSql(
            'ALTER TABLE products ADD CONSTRAINT products_showcase_sections_known CHECK ('
            . 'showcase_sections IS NULL OR ('
            . "jsonb_typeof(showcase_sections) = 'array'"
            . ' AND showcase_sections <@ \'[' . self::SECTIONS_JSON . ']\'::jsonb))',
        );

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX product_showcase_one_cta
                ON product_showcase (product_id)
             WHERE block = 'CTA'
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE product_showcase_bands IS
                'What is said and decided once per band (2026-09-28, 2026-11-20): its eyebrow, its title, its lede, and since 2026-11-20 its layout — a code from a closed set, stored beside the heading because it is a fact about the band and not about any one of its rows. Different from product_showcase, which is one row per row of a band. PRICING appears here and never there: it has a heading and no rows.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Narrowing the block constraints would orphan every TRUST, STATS, FEATURE and CTA row already written (ADR-016).',
        );
    }
}
