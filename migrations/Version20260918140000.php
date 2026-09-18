<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A member sees what concerns them; the organisation's view is the
 * administrator's (2026-09-18).
 *
 * The tax profile, the rates in force and the VAT periods are the
 * organisation's fiscal record — one fact for the whole tenant, nothing in
 * it addressed to a person. Lending `tax.read` to USER gave every member
 * the accountant's screen, and nothing on it was theirs to act on. The
 * documents that *are* theirs — the orders that bought their seat, the
 * invoices those raised, the payments on them — stay readable and are
 * narrowed to their own by the reading services, which is a code change
 * and not a permission.
 */
final class Version20260918140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tax.read is the administrator\'s: the fiscal record is the organisation\'s, not a member\'s';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
             WHERE role_id = (SELECT id FROM roles WHERE code = 'USER')
               AND permission_id = (SELECT id FROM permissions WHERE code = 'tax.read')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
              FROM roles r
              CROSS JOIN permissions p
             WHERE r.code = 'USER'
               AND p.code = 'tax.read'
            ON CONFLICT DO NOTHING
            SQL);
    }
}
