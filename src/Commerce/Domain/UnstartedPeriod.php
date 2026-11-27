<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * A period that was rolled and invoiced ahead, and has not begun (ADR-079).
 *
 * The renewal runs a lead before the period ends (ADR-070): for those days the
 * subscription row already describes the **next** period — it starts in the
 * future — while the customer is still inside the one they paid for. This is
 * that next period, with what is needed to take it back: where the running
 * period started, the term and commitment the roll replaced when it rolled a
 * term too, and the invoice raised for it.
 *
 * It exists for one decision. Somebody who cancels before a period starts does
 * not owe it, so cancelling in that window ends the subscription with the
 * period actually running, and the invoice for the one that never began is
 * withdrawn — cancelled if unpaid, refunded with its credit note if paid.
 */
final class UnstartedPeriod
{
    public const CANCELLED = 'INVOICE_CANCELLED';
    public const REFUNDED = 'INVOICE_REFUNDED';
    public const NOTHING_RAISED = 'NO_INVOICE';

    public function __construct(
        /** Where the period that has not begun starts — the end of the one running. */
        public readonly DateTimeImmutable $startsAt,
        /** Where the running period started, to put the row back on it. */
        public readonly DateTimeImmutable $runningPeriodStartedAt,
        /** Set when the roll opened a new term (ADR-071): what it replaced. */
        public readonly bool $termRolled,
        public readonly ?DateTimeImmutable $previousTermEndsAt,
        public readonly ?DateTimeImmutable $previousCommitmentEndsAt,
        /** The invoice raised for it, when one was — a free period raises none. */
        public readonly ?string $invoiceId,
        /** What became of that invoice, once decided. */
        public readonly string $outcome = self::NOTHING_RAISED,
    ) {
    }

    public function withOutcome(string $outcome): self
    {
        return new self(
            $this->startsAt,
            $this->runningPeriodStartedAt,
            $this->termRolled,
            $this->previousTermEndsAt,
            $this->previousCommitmentEndsAt,
            $this->invoiceId,
            $outcome,
        );
    }

    /**
     * What is recorded and what the caller is shown.
     *
     * @return array{starts_at: string, invoice_id: string|null, outcome: string}
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->format(DATE_ATOM),
            'invoice_id' => $this->invoiceId,
            'outcome' => $this->outcome,
        ];
    }
}
