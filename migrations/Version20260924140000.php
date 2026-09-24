<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The pictures a product's story shows (2026-09-24, step 4 of
 * `docs/home-showcase-spec.md`).
 *
 * **A table of its own, beside `assets` rather than inside it.** That one
 * is tenant-scoped — `tenant_id` is `NOT NULL` and every read is narrowed
 * by it, which is what makes holding a stray asset id useless. A product's
 * shop window belongs to no tenant, so putting these there would mean
 * making that column nullable and every isolation check optional. The
 * safer table is a second one.
 *
 * **These are public by construction.** The page they appear on is read by
 * strangers, so the bytes are too — served while the showcase is
 * published, and 404 the moment it is taken down. That is a departure from
 * the specification's "served by signed link the way `downloadAsset`
 * does": a signed link is minted by an *authenticated* caller, and the
 * reader here has no session to mint one with. Written down because the
 * next person will find the spec's sentence and wonder.
 *
 * No `alt` column: the alternative text is a **field on the band**, so it
 * travels through the same translation mechanism as every other sentence
 * on the page rather than needing a second one of its own.
 */
final class Version20260924140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Pictures for a product's story, and the block that carries one";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE product_assets (
                id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id   uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                storage_key  text NOT NULL UNIQUE,
                filename     text NOT NULL,
                content_type text NOT NULL,
                byte_size    integer NOT NULL,
                checksum     text NOT NULL,
                uploaded_by  uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT product_assets_byte_size_positive CHECK (byte_size > 0),
                CONSTRAINT product_assets_is_a_picture
                    CHECK (content_type IN ('image/png', 'image/jpeg', 'image/gif', 'image/webp'))
            )
            SQL);

        $this->addSql('CREATE INDEX product_assets_product ON product_assets (product_id)');

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE product_assets IS
                'Pictures a product shows on its own page (2026-09-24). Public while the showcase is published, because the page is. Not in `assets`, which is tenant-scoped: a shop window belongs to no tenant, and making that column nullable would make every isolation check optional.'
            SQL);

        // `SET NULL`, not `CASCADE`: deleting a picture must not delete the
        // caption it illustrated. A band with no picture is a band; a band
        // that vanished because somebody tidied an image is an operator's
        // words lost to a housekeeping act.
        $this->addSql(<<<'SQL'
            ALTER TABLE product_showcase
                ADD COLUMN asset_id uuid REFERENCES product_assets(id) ON DELETE SET NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_showcase DROP COLUMN IF EXISTS asset_id');
        $this->addSql('DROP TABLE IF EXISTS product_assets');
    }
}
