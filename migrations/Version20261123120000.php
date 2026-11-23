<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A product's page may carry a contact form (2026-11-23), and what visitors
 * send through it is kept for the platform's support to answer.
 *
 * `consent` is the sentence the visitor ticked, as they read it: the
 * product may reword it tomorrow, and the request must still say what this
 * person agreed to. No address, no browser, no referrer are kept — only
 * what was typed.
 */
final class Version20261123120000 extends AbstractMigration
{
    private const WRITABLE = "'HEADLINE', 'ANNOUNCE', 'TRUST', 'PROBLEM', 'STATS', 'VIDEO', 'STEPS', 'FEATURE', 'HOTSPOT', 'TOUR', 'USE_CASE', 'VERSUS', 'QUOTE', 'PROOF', 'COMPARE', 'DEMO', 'QUESTION', 'CONTACT', 'CTA'";

    private const SECTIONS = "'HEADLINE', 'ANNOUNCE', 'TRUST', 'PROBLEM', 'STATS', 'VIDEO', 'STEPS', 'FEATURE', 'HOTSPOT', 'TOUR', 'USE_CASE', 'VERSUS', 'QUOTE', 'PROOF', 'COMPARE', 'DEMO', 'PRICING', 'QUESTION', 'CONTACT', 'CTA'";

    private const SECTIONS_JSON = '"HEADLINE", "ANNOUNCE", "TRUST", "PROBLEM", "STATS", "VIDEO", "STEPS", "FEATURE", "HOTSPOT", "TOUR", "USE_CASE", "VERSUS", "QUOTE", "PROOF", "COMPARE", "DEMO", "PRICING", "QUESTION", "CONTACT", "CTA"';

    public function getDescription(): string
    {
        return 'CONTACT: a form on a product\'s page, and the requests it sends.';
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

        $this->addSql(
            <<<'SQL'
            CREATE TABLE contact_requests (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                product_id  UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                name        TEXT NOT NULL,
                email       TEXT NOT NULL,
                message     TEXT NOT NULL,
                consent     TEXT NOT NULL,
                locale      TEXT NOT NULL,
                received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                handled_at  TIMESTAMPTZ,
                handled_by  UUID REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT contact_requests_name_said CHECK (length(btrim(name)) BETWEEN 1 AND 200),
                CONSTRAINT contact_requests_email_said CHECK (length(btrim(email)) BETWEEN 3 AND 254),
                CONSTRAINT contact_requests_message_said CHECK (length(btrim(message)) BETWEEN 1 AND 5000),
                CONSTRAINT contact_requests_consent_said CHECK (btrim(consent) <> ''),
                CONSTRAINT contact_requests_handled_together CHECK ((handled_at IS NULL) = (handled_by IS NULL) OR handled_by IS NULL)
            )
            SQL,
        );
        $this->addSql('CREATE INDEX contact_requests_to_answer ON contact_requests (handled_at NULLS FIRST, received_at DESC)');
        $this->addSql('CREATE INDEX contact_requests_by_product ON contact_requests (product_id, received_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping contact_requests would throw away what visitors asked and nobody has answered yet.',
        );
    }
}
