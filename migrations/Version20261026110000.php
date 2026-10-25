<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A published offer version never goes back to DRAFT (2026-10-26).
 *
 * `offer_version_is_frozen_once_published` left `status` mutable so a version
 * can expire or be archived, and returned early on `OLD.status = 'DRAFT'`.
 * Together that was a door: set a sold version back to DRAFT, and the next
 * UPDATE passed the early return and rewrote the price, the terms and the
 * grants of a contract already running. Nothing in the platform moves a
 * version back — publishing is DRAFT → ACTIVE and the rest is expiry or
 * archiving — so the database now says so too.
 */
final class Version20261026110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'a published offer version never returns to DRAFT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION offer_version_is_not_unpublished() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'DRAFT' AND NEW.status = 'DRAFT' THEN
                    RAISE EXCEPTION
                        'offer_versions: a published version is what somebody bought and never returns to DRAFT'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER offer_versions_not_unpublished
                BEFORE UPDATE OF status ON offer_versions
                FOR EACH ROW EXECUTE FUNCTION offer_version_is_not_unpublished()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS offer_versions_not_unpublished ON offer_versions');
        $this->addSql('DROP FUNCTION IF EXISTS offer_version_is_not_unpublished()');
    }
}
