<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An offer's name, in the four other languages (2026-09-24, step 2 of
 * `docs/translatable-fields-spec.md`).
 *
 * The same shape as `feature_translations` and for the same reasons: the
 * English stays on `offers` as the key and the fallback, the four others
 * are rows that cascade, and `locale <> 'en'` is a constraint rather than
 * a convention.
 *
 * **Only the name.** An offer has no description to translate — what it
 * grants is the plan's features, each named in their own row, and what it
 * costs is a number. A column nobody writes is a column somebody
 * eventually fills with something that belongs elsewhere.
 *
 * A translation is *not* part of what an offer version freezes (ADR-033).
 * The price and the terms are snapshotted because a customer agreed to
 * them; the name in Spanish is the same offer said in another language,
 * and correcting it after publication changes nothing anybody agreed to.
 */
final class Version20260924100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An offer carries its name in the four other languages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE offer_translations (
                offer_id   uuid NOT NULL REFERENCES offers(id) ON DELETE CASCADE,
                locale     text NOT NULL,
                name       text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (offer_id, locale),
                CONSTRAINT offer_translations_locale_known
                    CHECK (locale IN ('fr', 'es', 'de', 'it')),
                CONSTRAINT offer_translations_name_not_blank
                    CHECK (btrim(name) <> '')
            )
            SQL);

        $this->addSql("COMMENT ON TABLE offer_translations IS 'What an operator calls an offer in a language that is not English. The English is on offers itself and is the fallback.'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS offer_translations');
    }
}
