<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The order of a product's sections is the product's, not the bundle's
 * (2026-09-28, `docs/home-showcase-spec.md` §5.1).
 *
 * It was `BAND_META[kind].order`, a constant compiled into the frontend and
 * read by the page and its in-page nav. An operator who wanted the prices
 * above the questions could do nothing about it without a rebuild and a
 * redeploy — the same position `VITE_DEFAULT_PRODUCT` put a deployment in
 * before a tenant could choose its own product.
 *
 * **Nullable, and null is the default order.** No backfill: every product
 * that exists today reads exactly as it did yesterday, and a row is written
 * the first time somebody drags one. Seeding the default into every product
 * would make "nobody has reordered this" and "somebody chose the order it
 * already had" look identical, and the first is what every product starts
 * from.
 *
 * **`PRICING` is in this list and in no other.** It is a section that reads
 * the catalogue and has no row anybody writes — `product_showcase_block_known`
 * refuses it as a band, deliberately, because a row for it would be a row
 * somebody could type a price into (§9). Its place in the order is the one
 * thing about it an operator decides.
 *
 * The check is containment (`<@`) rather than a subquery, which PostgreSQL
 * does not allow in a CHECK: it refuses a section this platform has never
 * heard of. It does **not** refuse a duplicate or a short list — the
 * boundary validator does that, the way `ShowcaseBlocks` already guards a
 * band's shape, and {@see ShowcaseSections::readIn} completes anything that
 * survived an older deployment rather than refusing to draw the page.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product decides the order of its own showcase sections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD COLUMN showcase_sections jsonb');

        $this->addSql(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_showcase_sections_known CHECK (
                showcase_sections IS NULL OR (
                    jsonb_typeof(showcase_sections) = 'array'
                    AND showcase_sections <@ '["HEADLINE","STEPS","USE_CASE","PROOF","PRICING","QUESTION"]'::jsonb
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN products.showcase_sections IS
                'The order this product reads its showcase sections in (2026-09-28), as a JSON array of section kinds. Null is the default order and needs no row. Different from product_showcase.position, which orders the rows within one band; this orders the bands themselves. PRICING appears here and in no other table: it is a section with no writable row.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP CONSTRAINT products_showcase_sections_known');
        $this->addSql('ALTER TABLE products DROP COLUMN showcase_sections');
    }
}
