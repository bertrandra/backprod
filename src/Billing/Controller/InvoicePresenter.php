<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Domain\BillingProfile;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use App\Billing\Domain\TaxRecord;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for invoices, their lines, their tax and the billing profile.
 *
 * Money leaves as an integer count of minor units plus its currency, never
 * as a formatted string and never as a decimal. A client that receives
 * `{"minor_units": 1999, "currency": "EUR"}` cannot round it wrong on the
 * way in; one that receives `19.99` already has.
 *
 * The parties are returned exactly as they were stored on the document —
 * that is the whole point of §25's snapshot, and re-deriving them here for
 * presentation would undo it at the last moment.
 */
final class InvoicePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            // Null until issued. A draft has no legal number, and inventing
            // a placeholder is how a gap enters the sequence.
            'number' => $invoice->number,
            'status' => $invoice->status,
            'final' => $invoice->isFinal(),
            'subscription_id' => $invoice->subscriptionId,
            'net' => self::money($invoice->net),
            'vat' => self::money($invoice->vat),
            'gross' => self::money($invoice->gross),
            'issued_at' => self::nullableMoment($invoice->issuedAt),
            'due_at' => self::nullableMoment($invoice->dueAt),
            'paid_at' => self::nullableMoment($invoice->paidAt),
            'period_start' => self::nullableMoment($invoice->periodStart),
            'period_end' => self::nullableMoment($invoice->periodEnd),
            'payment_terms' => $invoice->paymentTerms,
            'supplier' => $invoice->supplier,
            'customer' => $invoice->customer,
            'lines' => array_map(self::line(...), $invoice->lines),
            'taxes' => array_map(self::tax(...), $invoice->taxes),
        ];
    }

    /**
     * @param list<Invoice> $invoices
     *
     * @return list<array<string, mixed>>
     */
    public static function many(array $invoices): array
    {
        return array_map(self::one(...), $invoices);
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(BillingProfile $profile): array
    {
        return [
            'legal_name' => $profile->legalName,
            'vat_number' => $profile->vatNumber,
            'registration_number' => $profile->registrationNumber,
            'address_line1' => $profile->addressLine1,
            'address_line2' => $profile->addressLine2,
            'postal_code' => $profile->postalCode,
            'city' => $profile->city,
            'country_code' => $profile->countryCode,
            'billing_email' => $profile->billingEmail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(InvoiceLine $line): array
    {
        return [
            'position' => $line->position,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => self::money($line->unitPrice),
            'discount' => self::money($line->discount),
            'net' => self::money($line->net),
            // Basis points, as stored. Sending "20%" would lose 5.5% to
            // whatever the client's parser did with the half.
            'vat_rate_basis_points' => $line->vatRateBasisPoints,
            'vat' => self::money($line->vat),
            'gross' => self::money($line->gross),
            'source_offer_version_id' => $line->sourceOfferVersionId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tax(TaxRecord $tax): array
    {
        return [
            'jurisdiction' => $tax->jurisdiction,
            'rate_basis_points' => $tax->rateBasisPoints,
            'taxable' => self::money($tax->taxable),
            'tax' => self::money($tax->tax),
        ];
    }

    /**
     * @return array{minor_units: int, currency: string}
     */
    private static function money(Money $money): array
    {
        return ['minor_units' => $money->minorUnits, 'currency' => $money->currency];
    }

    private static function nullableMoment(?DateTimeImmutable $moment): ?string
    {
        return $moment === null
            ? null
            : $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
