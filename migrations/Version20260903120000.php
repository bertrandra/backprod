<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assets: what large files are, given that they are not in this database.
 *
 * Non-negotiable #9 keeps the bytes out of PostgreSQL, so what lives here is
 * the record of an object rather than the object. `storage_key` names it in
 * whatever the StorageProvider is; nothing else in the platform knows where
 * that is.
 *
 * Two columns are deliberately not what the client said.
 *
 * `content_type` is sniffed from the bytes. A client's Content-Type is a
 * claim, and a claim is exactly what an attacker controls — a PHP file
 * announcing itself as image/png is the oldest upload bug there is.
 *
 * `storage_key` is generated, never derived from the filename. A key built
 * from user input is a path traversal waiting to be written, and `filename`
 * survives only as a label to hand back on download.
 *
 * `checksum` makes an asset comparable without fetching it, and is what tells
 * a corrupted or truncated store from a healthy one.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assets stored outside the database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE assets (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                project_id UUID REFERENCES projects (id) ON DELETE CASCADE,
                kind TEXT NOT NULL DEFAULT 'UPLOAD',
                storage_key TEXT NOT NULL,
                filename TEXT NOT NULL,
                content_type TEXT NOT NULL,
                byte_size BIGINT NOT NULL,
                checksum TEXT NOT NULL,
                uploaded_by UUID REFERENCES users (id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT assets_storage_key_unique UNIQUE (storage_key),
                CONSTRAINT assets_kind_known CHECK (kind IN ('UPLOAD', 'EXPORT')),
                CONSTRAINT assets_filename_not_blank CHECK (btrim(filename) <> ''),
                -- A content type this shape or nothing: the column holds what
                -- was sniffed, and a sniffer that returned something strange
                -- should fail here rather than reach a Content-Type header.
                CONSTRAINT assets_content_type_is_a_media_type
                    CHECK (content_type ~ '^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$'),
                CONSTRAINT assets_byte_size_positive CHECK (byte_size > 0),
                CONSTRAINT assets_checksum_is_sha256 CHECK (checksum ~ '^[a-f0-9]{64}$'),
                -- An export belongs to the project it exported. An upload may
                -- stand alone, which is what a message attachment will be.
                CONSTRAINT assets_export_names_its_project
                    CHECK (kind <> 'EXPORT' OR project_id IS NOT NULL)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX assets_tenant_idx ON assets (tenant_id, product_id, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX assets_project_idx ON assets (project_id, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('assets.read', 'Read and download the tenant''s assets'),
                ('assets.manage', 'Upload and delete assets')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE (r.code IN ('TENANT_ADMIN', 'USER') AND p.code = 'assets.read')
               OR (r.code IN ('TENANT_ADMIN', 'USER') AND p.code = 'assets.manage')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('assets.read', 'assets.manage')
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('assets.read', 'assets.manage')");
        $this->addSql('DROP TABLE IF EXISTS assets');
    }
}
