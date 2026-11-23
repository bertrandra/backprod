<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two bands that show rather than say: a tour of the product, and one plot
 * before and after (2026-11-21).
 *
 * `TOUR` is what the product includes, each with its own screenshot, one
 * shown at a time. `COMPARE` is two rows — before, after — each an address to
 * embed or a picture, exactly as a `DEMO` row is.
 *
 * The same three constraints as every band before them, widened together:
 * the rows a band may have, the bands that may carry a heading, and the
 * sections an order may name.
 *
 * Nothing here bounds `COMPARE` to two rows. The story is replaced whole on
 * every write, so there is no second writer to race and the validator's
 * count is the whole of the rule (`ShowcaseBlock::AT_MOST`).
 */
final class Version20261123090000 extends AbstractMigration
{
    private const WRITABLE = "'HEADLINE', 'ANNOUNCE', 'TRUST', 'PROBLEM', 'STATS', 'VIDEO', 'STEPS', 'FEATURE', 'HOTSPOT', 'TOUR', 'USE_CASE', 'VERSUS', 'QUOTE', 'PROOF', 'COMPARE', 'DEMO', 'QUESTION', 'CTA'";

    private const SECTIONS = "'HEADLINE', 'ANNOUNCE', 'TRUST', 'PROBLEM', 'STATS', 'VIDEO', 'STEPS', 'FEATURE', 'HOTSPOT', 'TOUR', 'USE_CASE', 'VERSUS', 'QUOTE', 'PROOF', 'COMPARE', 'DEMO', 'PRICING', 'QUESTION', 'CTA'";

    private const SECTIONS_JSON = '"HEADLINE", "ANNOUNCE", "TRUST", "PROBLEM", "STATS", "VIDEO", "STEPS", "FEATURE", "HOTSPOT", "TOUR", "USE_CASE", "VERSUS", "QUOTE", "PROOF", "COMPARE", "DEMO", "PRICING", "QUESTION", "CTA"';

    public function getDescription(): string
    {
        return 'ANNOUNCE, VIDEO, VERSUS and HOTSPOT: a line of news, a film, a comparison table and points on a picture.';
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
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Narrowing the block constraints would orphan every ANNOUNCE, VIDEO, VERSUS and HOTSPOT row already written (ADR-016).',
        );
    }
}
