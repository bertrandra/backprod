<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two bands beside the prices (2026-11-24): what a hesitating buyer is owed
 * — the payment is safe, leaving is allowed, somebody answers — and what
 * customers said, with the mark they gave.
 *
 * `REASSURE` is an icon and a line. `REVIEW` is an author, their words and a
 * whole number of stars; the page's count and average are computed on read
 * (`ShowcaseReviews`) and stored nowhere, so they cannot disagree with the
 * rows they are drawn from.
 *
 * The same three constraints as every band before them, widened together:
 * the rows a band may have, the bands that may carry a heading, and the
 * sections an order may name.
 */
final class Version20261124090000 extends AbstractMigration
{
    private const WRITABLE = "'HEADLINE', 'ANNOUNCE', 'TRUST', 'PROBLEM', 'STATS', 'VIDEO', 'STEPS', 'FEATURE', 'HOTSPOT', 'TOUR', 'USE_CASE', 'VERSUS', 'QUOTE', 'REVIEW', 'PROOF', 'COMPARE', 'DEMO', 'REASSURE', 'QUESTION', 'CONTACT', 'CTA'";

    private const SECTIONS = "'HEADLINE', 'ANNOUNCE', 'TRUST', 'PROBLEM', 'STATS', 'VIDEO', 'STEPS', 'FEATURE', 'HOTSPOT', 'TOUR', 'USE_CASE', 'VERSUS', 'QUOTE', 'REVIEW', 'PROOF', 'COMPARE', 'DEMO', 'PRICING', 'REASSURE', 'QUESTION', 'CONTACT', 'CTA'";

    private const SECTIONS_JSON = '"HEADLINE", "ANNOUNCE", "TRUST", "PROBLEM", "STATS", "VIDEO", "STEPS", "FEATURE", "HOTSPOT", "TOUR", "USE_CASE", "VERSUS", "QUOTE", "REVIEW", "PROOF", "COMPARE", "DEMO", "PRICING", "REASSURE", "QUESTION", "CONTACT", "CTA"';

    public function getDescription(): string
    {
        return 'REASSURE and REVIEW: what a buyer is owed beside the prices, and what customers said with the mark they gave.';
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
            'Narrowing the block constraints would orphan every REASSURE and REVIEW row already written (ADR-016).',
        );
    }
}
