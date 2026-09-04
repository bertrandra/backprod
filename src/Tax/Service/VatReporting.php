<?php

declare(strict_types=1);

namespace App\Tax\Service;

use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tax\Domain\TaxRepository;
use App\Tax\Domain\VatDeclaration;
use App\Tax\Domain\VatReportingPeriod;
use DateTimeImmutable;

/**
 * Declaration periods and their closure (§25.3).
 *
 * Closing is one-way. The database enforces that with a trigger, and this
 * service refuses earlier with a message a human can act on — the trigger is
 * the guarantee, this is the manners. The order matters: if the two ever
 * disagree, the database wins, which is the point of putting it there.
 */
final class VatReporting
{
    public const ALREADY_CLOSED = 'PERIOD_ALREADY_CLOSED';
    public const PERIOD_NOT_ENDED = 'PERIOD_NOT_ENDED';

    public function __construct(private readonly TaxRepository $tax)
    {
    }

    /**
     * @return list<VatReportingPeriod>
     */
    public function periods(?string $jurisdiction): array
    {
        return $this->tax->periods($jurisdiction);
    }

    public function open(
        string $jurisdiction,
        string $periodKind,
        DateTimeImmutable $startsOn,
        DateTimeImmutable $endsOn,
    ): VatReportingPeriod {
        return $this->tax->openPeriod($jurisdiction, $periodKind, $startsOn, $endsOn);
    }

    /**
     * @return array{
     *     period: VatReportingPeriod,
     *     declaration: VatDeclaration|null,
     *     totals: array{
     *         currency: string,
     *         total_base: int,
     *         total_vat: int,
     *         transaction_count: int,
     *         breakdown: list<array{regime: string, rate: int, base: int, vat: int, count: int}>
     *     }
     * }
     */
    public function show(string $periodId): array
    {
        $period = $this->require($periodId);
        $declaration = $this->tax->declarationFor($periodId);

        // A closed period reports what it declared, not what a fresh query
        // would say today. Those are different questions, and only the first
        // one is what was filed.
        $totals = $declaration === null
            ? $this->tax->totalsFor($period)
            : [
                'currency' => $declaration->currency,
                'total_base' => $declaration->totalBase,
                'total_vat' => $declaration->totalVat,
                'transaction_count' => $declaration->transactionCount,
                'breakdown' => $declaration->breakdown,
            ];

        return ['period' => $period, 'declaration' => $declaration, 'totals' => $totals];
    }

    public function close(string $periodId, ?string $actorUserId): VatDeclaration
    {
        $period = $this->require($periodId);

        if ($period->isClosed()) {
            throw new ConflictException(
                self::ALREADY_CLOSED,
                'This reporting period is already closed. A correction belongs in a later period.',
            );
        }

        // Closing a period that has not ended would freeze a figure that is
        // still moving, and it cannot be reopened to fix that.
        if ($period->covers(new DateTimeImmutable()) || $period->endsOn > new DateTimeImmutable()) {
            throw new ConflictException(
                self::PERIOD_NOT_ENDED,
                'This reporting period has not ended yet, and closing is one-way.',
                ['ends_on' => $period->endsOn->format('Y-m-d')],
            );
        }

        return $this->tax->close($period, $actorUserId);
    }

    private function require(string $periodId): VatReportingPeriod
    {
        $period = $this->tax->findPeriod($periodId);

        if ($period === null) {
            throw new NotFoundException('Reporting period not found.', [], 'PERIOD_NOT_FOUND');
        }

        return $period;
    }
}
