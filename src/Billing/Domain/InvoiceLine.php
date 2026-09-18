<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * One line of an invoice, carrying its own description and prices.
 *
 * Nothing here is looked up. The description is the text as it was billed,
 * the unit price is the price that was charged, and the VAT rate is the rate
 * that applied — all copied at issue time, because §25 forbids a historical
 * invoice from depending on current plan values.
 *
 * sourceOfferVersionId records where the line came from, for lineage only.
 * No amount is ever read through it.
 */
final class InvoiceLine
{
    public function __construct(
        public readonly int $position,
        public readonly string $description,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly Money $discount,
        public readonly Money $net,
        public readonly int $vatRateBasisPoints,
        public readonly Money $vat,
        public readonly Money $gross,
        public readonly ?string $sourceOfferVersionId,
        /** What the line sold, in words — resolved on read, never stored (2026-09-19). */
        public readonly ?LineOffer $offer = null,
    ) {
    }

    public function describedBy(?LineOffer $offer): self
    {
        return $this->pinnedTo($this->sourceOfferVersionId, $offer);
    }

    /**
     * The same line, naming the version that priced it. A quote's or an
     * order's lines are stored without one — the document names it once for
     * all of them — so it is put back on read, and travels with the line
     * into the invoice it becomes (2026-09-19).
     */
    public function pinnedTo(?string $sourceOfferVersionId, ?LineOffer $offer): self
    {
        return new self(
            $this->position,
            $this->description,
            $this->quantity,
            $this->unitPrice,
            $this->discount,
            $this->net,
            $this->vatRateBasisPoints,
            $this->vat,
            $this->gross,
            $sourceOfferVersionId,
            $offer,
        );
    }

    /**
     * Builds a line and derives its arithmetic once, so net, VAT and gross
     * cannot disagree — the database enforces gross = net + VAT, and this is
     * where that identity is established rather than hoped for.
     */
    public static function of(
        int $position,
        string $description,
        int $quantity,
        Money $unitPrice,
        Money $discount,
        int $vatRateBasisPoints,
        ?string $sourceOfferVersionId,
    ): self {
        $net = $unitPrice->times($quantity)->minus($discount);
        $vat = $net->taxedAt($vatRateBasisPoints);

        return new self(
            $position,
            $description,
            $quantity,
            $unitPrice,
            $discount,
            $net,
            $vatRateBasisPoints,
            $vat,
            $net->plus($vat),
            $sourceOfferVersionId,
        );
    }
}
