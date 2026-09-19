<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The language a person reads in (2026-09-19, ADR-050).
 *
 * A presentation fact, not a business one: the API keeps its codes and
 * enumerations in English, and what changes is the words on the screen and
 * in the mail. `users.locale` is what the person chose (or the default,
 * English); `invoices.locale` is the language an invoice was issued in,
 * snapshotted with the rest of it — a document does not change language
 * because its reader did.
 */
final class Version20260919150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The language a person reads in, and the language an invoice was issued in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'");
        $this->addSql("ALTER TABLE users ADD CONSTRAINT users_locale_known CHECK (locale IN ('en', 'fr', 'es', 'de', 'it'))");
        $this->addSql("ALTER TABLE invoices ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'");
        $this->addSql("ALTER TABLE invoices ADD CONSTRAINT invoices_locale_known CHECK (locale IN ('en', 'fr', 'es', 'de', 'it'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoices DROP COLUMN IF EXISTS locale');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS locale');
    }
}
