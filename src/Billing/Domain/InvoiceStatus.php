<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Shared\Exceptions\ConflictException;

/**
 * The invoice lifecycle of §25.1, and which moves are legal.
 *
 * All nine states exist in the schema from the start so the e-invoicing
 * adapter needs no migration to widen a constraint. Which transitions are
 * *reachable* is decided here, and the ones this milestone cannot yet perform
 * are absent rather than pretended: an invoice cannot become SUBMITTED until
 * something submits it.
 *
 * A state machine rather than scattered checks because the illegal moves are
 * the interesting ones. Re-issuing an issued invoice would allocate a second
 * legal number for one document; paying a cancelled one would put money
 * against a debt that no longer exists.
 */
final class InvoiceStatus
{
    public const DRAFT = 'DRAFT';
    public const ISSUED = 'ISSUED';
    public const READY_FOR_EINVOICE = 'READY_FOR_EINVOICE';
    public const SUBMITTED = 'SUBMITTED';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';
    public const PAID = 'PAID';
    public const CANCELLED = 'CANCELLED';
    public const CREDITED = 'CREDITED';

    /**
     * The reachable transitions.
     *
     * The transmission states were declared but unreachable until the
     * e-invoicing adapter existed, exactly as this comment promised: wiring
     * it extended this table deliberately rather than discovering the states
     * already worked.
     *
     * ISSUED still leads straight to PAID. Not every invoice goes through an
     * approved platform — a bank transfer reconciled by hand does not — and
     * forcing one path would make the platform unusable for the invoices
     * §25.1 does not cover.
     *
     * REJECTED leads back to READY_FOR_EINVOICE because that is what §25.1's
     * remedy is: correct the document and transmit it again. The attempts
     * themselves are kept as separate transmission rows, so the history is
     * not lost when the invoice moves on.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        self::DRAFT => [self::ISSUED, self::CANCELLED],
        self::ISSUED => [self::READY_FOR_EINVOICE, self::PAID, self::CANCELLED, self::CREDITED],
        self::READY_FOR_EINVOICE => [self::SUBMITTED, self::CANCELLED, self::CREDITED],
        self::SUBMITTED => [self::ACCEPTED, self::REJECTED],
        self::ACCEPTED => [self::PAID, self::CANCELLED, self::CREDITED],
        self::REJECTED => [self::READY_FOR_EINVOICE, self::CANCELLED, self::CREDITED],
        self::PAID => [self::CREDITED],
        self::CANCELLED => [],
        self::CREDITED => [],
    ];

    public static function permits(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function assertPermits(string $from, string $to): void
    {
        if (self::permits($from, $to)) {
            return;
        }

        throw new ConflictException(
            'INVALID_INVOICE_TRANSITION',
            'An invoice cannot move between those states.',
            ['from' => $from, 'to' => $to],
        );
    }

    /**
     * Whether the document is final. A finalised invoice has a legal number
     * and has left the building; its contents are no longer editable.
     */
    public static function isFinal(string $status): bool
    {
        return $status !== self::DRAFT;
    }
}
