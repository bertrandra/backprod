<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A person's default product: the one their screens open in when the
 * address names none.
 *
 * Asked by the operator on 2026-09-17. Until now nothing chose, and a
 * person who signed out and in again at the landing page met a screen with
 * nothing on it (no product, so no `/me`, so no menu). The shell now picks
 * a product when none is named; this column says which — set at sign-up
 * to the product signed up for, changed from the profile, and only ever a
 * product the person holds. `ON DELETE SET NULL`: a retired product is not
 * a reason to lose the account.
 */
final class Version20260917140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A default product per person, set at sign-up';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN default_product_id UUID NULL REFERENCES products (id) ON DELETE SET NULL
            SQL);

        // Everybody who already has exactly one product gets it as their
        // default; anybody with several keeps choosing, as before.
        $this->addSql(<<<'SQL'
            UPDATE users u
               SET default_product_id = one.product_id
              FROM (
                       SELECT user_id, min(product_id::text)::uuid AS product_id
                         FROM tenant_members
                        GROUP BY user_id
                       HAVING count(DISTINCT product_id) = 1
                   ) one
             WHERE u.id = one.user_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN default_product_id');
    }
}
