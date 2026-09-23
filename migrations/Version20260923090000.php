<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Products are shown in an order somebody chose (2026-09-23).
 *
 * Every list of products was ordered by `code` — the switcher, the public
 * list, the console — which is alphabetical order dressed as a decision.
 * It put Atlas first because A comes before P, and the operator had no way
 * to say otherwise short of renaming a product, which is the one thing a
 * code may never do.
 *
 * `display_order` is an integer, and the seeded values are decades: 10, 20,
 * 30. The gaps are the point — inserting a product between two others is a
 * number nobody else has to move, and a whole platform renumbering itself
 * because a new product arrived second is how an ordering column becomes
 * one nobody dares touch.
 *
 * Not unique, deliberately. Two products sharing a number is not a defect
 * to refuse at 3 a.m.; it is a tie, and `code` breaks it, which is what
 * every query that reads this column now says. A unique index here would
 * turn "move this one up" into a transaction that has to renumber its
 * neighbours first.
 *
 * The backfill keeps today's order rather than inventing one: whatever was
 * first alphabetically is still first, now by a number that can be changed.
 */
final class Version20260923090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Products carry a display order, seeded in tens, keeping the order they had';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD COLUMN display_order integer NOT NULL DEFAULT 0');

        // Ten, twenty, thirty — in the order the lists have been showing
        // them, so nothing visibly moves on the day this runs.
        $this->addSql(<<<'SQL'
            UPDATE products p
               SET display_order = ranked.position * 10
              FROM (
                  SELECT id, row_number() OVER (ORDER BY code) AS position
                    FROM products
              ) AS ranked
             WHERE ranked.id = p.id
            SQL);

        $this->addSql("COMMENT ON COLUMN products.display_order IS 'Where this product sits in every list of products; ties are broken by code. Seeded in tens so one can be inserted between two others.'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP COLUMN display_order');
    }
}
