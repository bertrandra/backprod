<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The rendered invoice document (§7 `GET /invoices/{id}/pdf`).
 *
 * **The bytes are stored, not re-rendered.** An issued invoice is frozen, so
 * its PDF is a pure function of it and re-rendering would seem safe. It is
 * not: the renderer has a version, fonts have versions, and a document
 * regenerated a year later can differ in a way nobody chose. For a legal
 * artefact the useful guarantee is that what is served is what was sent, so
 * the first render is kept and every later request returns that.
 *
 * `renderer` records what produced it for the same reason. When a future
 * version renders differently, the row says which engine made this one — the
 * question an auditor asks about a document that does not match today's
 * output.
 *
 * **`invoice_id` is the primary key, so a document exists at most once per
 * invoice.** Two concurrent first requests both render — wasteful, harmless —
 * and the second insert loses to the key rather than to a check somebody
 * remembered to write. The loser then reads the winner's row, so both callers
 * are served identical bytes.
 *
 * The bytes live in storage, not here: §"Stocke" puts PDFs in the object store
 * and keeps base64 out of JSONB. This table holds the key and the checksum.
 *
 * ON DELETE CASCADE, because a document about a deleted invoice is a file
 * nothing can address. The bytes in storage outlive the row and are collected
 * by the same sweep that handles every other orphaned key.
 */
final class Version20260910090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the rendered PDF of an issued invoice, once, with its checksum.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_documents (
                invoice_id   UUID PRIMARY KEY REFERENCES invoices (id) ON DELETE CASCADE,
                storage_key  TEXT NOT NULL UNIQUE,
                byte_size    INTEGER NOT NULL CHECK (byte_size > 0),
                checksum     CHAR(64) NOT NULL CHECK (checksum ~ '^[0-9a-f]{64}$'),
                renderer     TEXT NOT NULL CHECK (btrim(renderer) <> ''),
                generated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);

        // A rendered invoice is history. Changing the bytes under a number
        // that has already been sent is the one thing this table exists to
        // prevent, so it is prevented here rather than by every caller
        // remembering not to.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION invoice_document_is_frozen() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'invoice_documents: a rendered invoice is what was sent; '
                    'it cannot be rewritten';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER invoice_documents_frozen
                BEFORE UPDATE ON invoice_documents
                FOR EACH ROW EXECUTE FUNCTION invoice_document_is_frozen()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS invoice_documents_frozen ON invoice_documents');
        $this->addSql('DROP FUNCTION IF EXISTS invoice_document_is_frozen()');
        $this->addSql('DROP TABLE IF EXISTS invoice_documents');
    }
}
