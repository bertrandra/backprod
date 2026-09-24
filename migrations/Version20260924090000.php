<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A feature's name and description, in the four other languages
 * (2026-09-24, `docs/translatable-fields-spec.md`).
 *
 * The English stays on `features` and stays required: it is the key, the
 * way ADR-050 made the English sentence the key of the application's own
 * catalogues. A row missing here is not an error to handle — it *is* the
 * fallback, and a `LEFT JOIN` that finds nothing answers English.
 *
 * **A table per translated thing, not one table for everything.** A single
 * `translations(entity_type, entity_id, …)` is what everybody draws first
 * and the one shape PostgreSQL cannot keep honest: an id pointing at two
 * tables carries no foreign key, so nothing stops a translation surviving
 * the feature it describes, and nothing catches an `entity_type` misspelt
 * in a migration written at speed. This one cascades.
 *
 * `locale <> 'en'` is a constraint rather than a convention: an English row
 * here would be a second place to look for the English, and the two would
 * disagree the first time somebody edited one of them.
 */
final class Version20260924090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A feature carries its name and description in the four other languages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE features ADD COLUMN description text');

        $this->addSql(<<<'SQL'
            CREATE TABLE feature_translations (
                feature_id  uuid NOT NULL REFERENCES features(id) ON DELETE CASCADE,
                locale      text NOT NULL,
                name        text,
                description text,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (feature_id, locale),
                CONSTRAINT feature_translations_locale_known
                    CHECK (locale IN ('fr', 'es', 'de', 'it')),
                CONSTRAINT feature_translations_says_something
                    CHECK (name IS NOT NULL OR description IS NOT NULL)
            )
            SQL);

        $this->addSql("COMMENT ON TABLE feature_translations IS 'What an operator wrote about their own feature, in a language that is not English. The English is on features itself and is the fallback.'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS feature_translations');
        $this->addSql('ALTER TABLE features DROP COLUMN IF EXISTS description');
    }
}
