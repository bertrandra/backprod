<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An administrator administers; they do not buy, and they do not pay by card
 * (2026-09-25).
 *
 * `billing.pay` was given to both roles on 2026-09-18 so that a stranger who
 * signed up could pay for what they had just chosen. The administrator got it
 * because the role was a superset, not because anybody decided they should
 * have it — and it showed: **an administrator who subscribes to nothing was
 * offered *Buy for yourself* on every offer in the catalogue.** The operator
 * asked what it was doing there, which is the right question.
 *
 * Since the tenant surface sells seats only (ADR-055), what that button does
 * is take out a seat in the administrator's own name, invoiced by their
 * organisation to themselves. Coherent, and not the model: an administrator
 * runs the organisation, reads what its people hold, and records the money
 * that arrives.
 *
 * So `billing.pay` becomes the USER's alone. It gates three things and all
 * three go with it:
 *
 * ```text
 * openCheckoutSession    taking out a seat
 * cancelCheckoutSession  giving one up before paying
 * startPayment           paying an invoice through the provider
 * ```
 *
 * **The third is deliberate too**, and is what makes this the strict reading
 * rather than half of one: the money reaches the organisation outside the
 * platform, and the administrator records it with `markPaid` — which is
 * `billing.manage` and stays theirs. Issuing, crediting and refunding are
 * unchanged.
 *
 * Nobody is left unable to pay: a seat's invoice is addressed to the person
 * who bought it, and that person is a USER.
 */
final class Version20260925160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.pay is the USER\'s alone: an administrator administers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
             WHERE role_id = (SELECT id FROM roles WHERE code = 'TENANT_ADMIN')
               AND permission_id = (SELECT id FROM permissions WHERE code = 'billing.pay')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE permissions
               SET description = 'Take out a seat, give one up before paying, pay an invoice through the provider. The buyer''s permission: an administrator records money that arrived instead (billing.manage).'
             WHERE code = 'billing.pay'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible: it gives the administrator back a permission, which is
        // the state this migration found. Nothing they did with it is
        // recorded differently.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
              FROM roles r
              CROSS JOIN permissions p
             WHERE r.code = 'TENANT_ADMIN'
               AND p.code = 'billing.pay'
            ON CONFLICT DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE permissions
               SET description = 'Open a checkout, pay an invoice, retry a payment'
             WHERE code = 'billing.pay'
            SQL);
    }
}
