<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a product deployed beside the platform lives (2026-09-20, ADR-051
 * milestone B).
 *
 * `products.app_url` is the address the shell sends a person to when they
 * choose that product — `https://plan.raillard.org` — with nothing but
 * `?product=` appended: no token, no session, nothing a log could replay
 * (ADR-051 §3). Null for a product whose screens live inside the platform's
 * own shell, which is every product until now. HTTPS only, and no query or
 * fragment, because the shell appends its own.
 */
final class Version20260920090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Where a product deployed beside the platform lives';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD COLUMN app_url TEXT NULL');
        $this->addSql("ALTER TABLE products ADD CONSTRAINT products_app_url_https CHECK (app_url IS NULL OR (app_url ~ '^https://' AND app_url !~ '[?#]'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS app_url');
    }
}
