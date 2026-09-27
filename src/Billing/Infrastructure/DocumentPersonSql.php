<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

/**
 * Whom a billing document concerns — one rule, written once (2026-09-27).
 *
 * The person is **the holder of the subscription the document belongs to**
 * (`subscriptions.owner_user_id`, the same column the organisation's
 * subscription list names as holder), and failing that **the person the order
 * that raised it was for** (`orders.subscriber_user_id`, else who placed it).
 * Null is the organisation, and is said as such rather than attributed to
 * anybody.
 *
 * **It replaced a narrower rule that hid documents from their own people.**
 * "Mine" used to mean "raised by one of my seat's orders", and an upgrade's
 * prorated invoice, an early-termination buy-out and a renewal are written
 * onto the subscription with no order at all — so a member could not see the
 * invoice for their own upgrade, nor the payment on it, while the
 * administrator saw it attributed to nobody. The person column, the person
 * filter and a member's own view all read this, so they cannot disagree.
 *
 * Expressions over a table alias, for a WHERE or a SELECT; each returns a
 * uuid or NULL.
 */
final class DocumentPersonSql
{
    /** @param string $invoice alias of an `invoices` row */
    public static function ofInvoice(string $invoice): string
    {
        return sprintf(
            'COALESCE('
            . '(SELECT ps.owner_user_id FROM subscriptions ps WHERE ps.id = %1$s.subscription_id), '
            . '(SELECT coalesce(po.subscriber_user_id, po.placed_by) FROM orders po WHERE po.invoice_id = %1$s.id ORDER BY po.created_at LIMIT 1))',
            $invoice,
        );
    }

    /** @param string $creditNote alias of a `credit_notes` row — whoever its invoice concerns */
    public static function ofCreditNote(string $creditNote): string
    {
        return sprintf(
            '(SELECT %s FROM invoices pci WHERE pci.id = %s.invoice_id)',
            self::ofInvoice('pci'),
            $creditNote,
        );
    }

    /**
     * @param string $payment alias of a `payments` row — whoever its invoice
     *                        concerns, else its subscription's holder, else its order's person
     */
    public static function ofPayment(string $payment): string
    {
        return sprintf(
            'COALESCE('
            . '(SELECT %2$s FROM invoices ppi WHERE ppi.id = %1$s.invoice_id), '
            . '(SELECT pps.owner_user_id FROM subscriptions pps WHERE pps.id = %1$s.subscription_id), '
            . '(SELECT coalesce(ppo.subscriber_user_id, ppo.placed_by) FROM orders ppo WHERE ppo.id = %1$s.order_id))',
            $payment,
            self::ofInvoice('ppi'),
        );
    }

    /**
     * The WHERE clause every list, count and find shares: the caller's own
     * documents when `:ownedBy` is set, one person's when `:person` is.
     */
    public static function narrowing(string $expression): string
    {
        return sprintf(
            '(CAST(:ownedBy AS uuid) IS NULL OR %1$s = CAST(:ownedBy AS uuid))'
            . ' AND (CAST(:person AS uuid) IS NULL OR %1$s = CAST(:person AS uuid))',
            $expression,
        );
    }
}
