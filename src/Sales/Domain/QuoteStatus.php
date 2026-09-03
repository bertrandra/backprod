<?php

declare(strict_types=1);

namespace App\Sales\Domain;

use App\Shared\Exceptions\ConflictException;

/**
 * The life of a quote.
 *
 * EXPIRED is in this table but nothing moves a quote into it. That is
 * deliberate and it is the platform's oldest rule: **a lapse is a fact about
 * the clock, never about whether something ran.** A quote past its
 * `valid_until` cannot be accepted whatever its status column says, and the
 * column is tidiness for M7's sweeper rather than the thing that decides.
 *
 * The same reasoning already governs whether an offer may be sold and whether
 * an entitlement is in force.
 */
final class QuoteStatus
{
    public const DRAFT = 'DRAFT';
    public const SENT = 'SENT';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';
    public const EXPIRED = 'EXPIRED';
    public const CANCELLED = 'CANCELLED';

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        self::DRAFT => [self::SENT, self::CANCELLED],
        // Only a quote that was actually sent can be decided on. Accepting a
        // draft would mean a customer agreed to something nobody showed them.
        self::SENT => [self::ACCEPTED, self::REJECTED, self::EXPIRED, self::CANCELLED],
        self::ACCEPTED => [],
        self::REJECTED => [],
        self::EXPIRED => [],
        self::CANCELLED => [],
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
            'INVALID_QUOTE_TRANSITION',
            'A quote cannot move between those states.',
            ['from' => $from, 'to' => $to],
        );
    }
}
