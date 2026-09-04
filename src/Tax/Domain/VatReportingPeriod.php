<?php

declare(strict_types=1);

namespace App\Tax\Domain;

use DateTimeImmutable;

/**
 * A declaration period, per jurisdiction (§25.3).
 *
 * Closing is one-way, and the database enforces it with a trigger rather than
 * this class with a check: a check in one service does not survive the second
 * code path, and "we closed the quarter" is exactly the kind of statement
 * that has to remain true afterwards. A correction to a closed period is an
 * entry in a later one — the same rule as gapless numbering and credit notes.
 */
final class VatReportingPeriod
{
    public const OPEN = 'OPEN';
    public const CLOSED = 'CLOSED';

    public const MONTHLY = 'MONTHLY';
    public const QUARTERLY = 'QUARTERLY';

    public function __construct(
        public readonly string $id,
        public readonly string $jurisdiction,
        public readonly string $periodKind,
        public readonly DateTimeImmutable $startsOn,
        public readonly DateTimeImmutable $endsOn,
        public readonly string $status,
        public readonly ?DateTimeImmutable $closedAt,
        public readonly ?string $closedBy,
    ) {
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /**
     * Whether a transaction on this date belongs to this period.
     *
     * Inclusive of both ends because a reporting period is named by dates,
     * not by instants: a sale on the 31st belongs to the month that ends on
     * the 31st.
     */
    public function covers(DateTimeImmutable $moment): bool
    {
        $day = $moment->format('Y-m-d');

        return $day >= $this->startsOn->format('Y-m-d')
            && $day <= $this->endsOn->format('Y-m-d');
    }
}
