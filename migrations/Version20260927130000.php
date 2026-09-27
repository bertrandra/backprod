<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A person registers freely and proves their address — before a domain lets
 * them in, and within a week otherwise (2026-09-27, ADR-061).
 *
 * **`users.email_confirm_by`** is the deadline a self-service sign-up is
 * given to follow its confirmation link. Until then the person uses what they
 * bought as if nothing were outstanding — registering and buying in one
 * breath is what the storefront is for. Past it, with the address still
 * unproved, the tenant surface refuses them with `EMAIL_UNCONFIRMED` until
 * they click; nothing is cancelled, and one click restores everything.
 *
 * NULL means no deadline, and it is what every row has today: invited people
 * prove their address by the invitation's link, seeded and installed accounts
 * were named by an operator, and accounts that existed before this column were
 * never told there was one. A deadline nobody announced is not one to enforce.
 *
 * **`tenant_members.status` gains `UNCONFIRMED`**: a membership the `DOMAIN`
 * join policy would grant, waiting on the address that is its only evidence.
 * Not `PENDING`, which is a question put to the administrators — nobody is
 * asking them anything. Every read that means "a member" says
 * `status = 'ACTIVE'`, so it grants nothing until the address is proved.
 */
final class Version20260927130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A self-service address has a deadline; a domain admits a proved address only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN email_confirm_by TIMESTAMPTZ');

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN users.email_confirm_by IS
                'When a self-service sign-up must have confirmed its address by (ADR-061). NULL: no deadline — invited, seeded, installed and pre-existing accounts. Past it with email_verified_at still NULL, the tenant surface answers EMAIL_UNCONFIRMED.'
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_members
                DROP CONSTRAINT tenant_members_status_known,
                ADD CONSTRAINT tenant_members_status_known CHECK (status IN ('ACTIVE', 'PENDING', 'UNCONFIRMED'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM tenant_members WHERE status = 'UNCONFIRMED'");
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_members
                DROP CONSTRAINT tenant_members_status_known,
                ADD CONSTRAINT tenant_members_status_known CHECK (status IN ('ACTIVE', 'PENDING'))
            SQL);
        $this->addSql('ALTER TABLE users DROP COLUMN email_confirm_by');
    }
}
