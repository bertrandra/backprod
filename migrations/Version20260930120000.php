<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A band that shows the product working (2026-09-30).
 *
 * `DEMO` carries either a page to embed or a picture, one per row and never
 * both, plus a caption. It is the eighth writable kind, and the first that
 * puts something on the page which is not this platform's own bytes.
 *
 * **The URL is a code and not a sentence**, in the sense `PROBLEM.icon`
 * already established: it is validated at the HTTP boundary, it is refused in
 * a translation — an address has no French — and it is the one field on this
 * page whose value a browser will *fetch*. Everything else here is plain text
 * the platform renders itself.
 *
 * **Two tokens, and they are the platform's.** An operator writes
 * `…&x={width}&y={height}…` and the page substitutes the pixels it actually
 * rendered at. The embedded application decides what to call its parameters —
 * Plan calls them `x` and `y` — and the platform never learns that, because a
 * shared component naming one product's query parameters is UR5 with extra
 * steps (`gate:products` forbids the same thing in PHP).
 *
 * **What this migration cannot do is let the frame load.** `frame-src` is a
 * header written into `.htaccess` at build time, so the origin has to be
 * named to `bin/build-dist.sh --embed`. A blocked frame is silent — nothing
 * on screen and one line in a console nobody reads — which is why the build
 * prints what it allowed and `bin/verify-dist.sh` checks it.
 */
final class Version20260930120000 extends AbstractMigration
{
    /** The eight an operator writes rows for. */
    private const WRITABLE = "'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'PROOF', 'DEMO', 'QUESTION'";

    /** Those, plus the one that reads the catalogue and has a heading too. */
    private const SECTIONS = "'HEADLINE', 'PROBLEM', 'STEPS', 'USE_CASE', 'QUOTE', 'PROOF', 'DEMO', 'PRICING', 'QUESTION'";

    /** The same list, as a JSON array for the order column's own check. */
    private const SECTIONS_JSON = '"HEADLINE", "PROBLEM", "STEPS", "USE_CASE", "QUOTE", "PROOF", "DEMO", "PRICING", "QUESTION"';

    public function getDescription(): string
    {
        return 'DEMO: a band that embeds the product, or shows a picture of it.';
    }

    public function up(Schema $schema): void
    {
        // Rewritten rather than added to: a CHECK constraint is one
        // expression, and two of them naming overlapping sets would leave
        // whichever is stricter deciding, silently.
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

        // The third, and the one that is easy to miss: the *order* a product
        // chose is a jsonb array with its own list of what may be in it, so a
        // migration widening the rows and the headings alone would leave the
        // order refusing a band the rows accept. Found by a test asking for
        // the default order and getting a constraint violation.
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
        // ADR-016: forward only. Narrowing the constraint again would refuse
        // rows a deployment has already published, and a page that cannot be
        // read is worse than a band nobody uses.
        $this->throwIrreversibleMigrationException(
            'Narrowing the block constraint would orphan every DEMO row already written (ADR-016).',
        );
    }
}
