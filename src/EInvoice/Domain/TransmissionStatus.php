<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

/**
 * How far an invoice has got towards the approved platform (§25.1).
 *
 * These four are the transmission's own states, distinct from the invoice's
 * nine. That separation matters: an invoice can be REJECTED by a platform and
 * then accepted on a second attempt, and squeezing both into the invoice's
 * status column would lose the fact that there were two attempts.
 *
 * One-way, for the same reason a payment's machine is: the platform's
 * webhooks arrive out of order, and a retried "submitted" landing after an
 * "accepted" must not walk the record backwards.
 */
final class TransmissionStatus
{
    public const PENDING = 'PENDING';
    public const SUBMITTED = 'SUBMITTED';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        self::PENDING => [self::SUBMITTED, self::REJECTED],
        self::SUBMITTED => [self::ACCEPTED, self::REJECTED],
        // Both terminal for *this* attempt. A rejected invoice is corrected
        // and transmitted again, which is a new transmission row — the
        // history of attempts is the thing an inspector asks about.
        self::ACCEPTED => [],
        self::REJECTED => [],
    ];

    public static function permits(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function isSettled(string $status): bool
    {
        return $status === self::ACCEPTED || $status === self::REJECTED;
    }
}
