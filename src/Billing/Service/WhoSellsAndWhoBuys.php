<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\InvoiceParties;
use App\Commerce\Domain\Subscriber;
use App\Shared\Exceptions\ConflictException;
use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Domain\SupplierTaxSettings;
use App\Tax\Domain\TaxableSale;
use App\Tax\Service\Taxation;
use App\User\Domain\UserRepository;

/**
 * Who sells and who buys, for every document raised against a sale
 * (2026-09-25).
 *
 * Two sales exist, and they have different parties:
 *
 * - **a seat** is the organisation selling to one of its own people
 *   (2026-09-19). The organisation's legal identity is the supplier, the
 *   person is the customer, the VAT is the organisation's country's, and the
 *   number comes from the organisation's own series (ADR-054);
 * - **an organisation's own subscription** is the product's supplier selling
 *   to the organisation, numbered in the platform's series.
 *
 * This existed once, privately, inside `InvoiceThenSubscribe` — and the other
 * service that raises an invoice against a subscription,
 * `ChargeOnEarlyTermination`, did not have it. A buy-out of a seat was billed
 * *product supplier → organisation*: the platform charging the company for
 * ending a contract the company had sold to one of its staff. Nobody on that
 * document was a party to the thing being ended.
 *
 * **A seat never reads the product's billing identity.** It used to, for a
 * jurisdiction the organisation's profile had not given — so a product with
 * no issuer configured refused a sale its identity would never have appeared
 * on, and a seat sold by a company that had not said where it trades filed
 * its VAT in the platform's country. Both are gone: the organisation says
 * where it sells from, or it does not sell.
 *
 * **And the VAT regime is part of the same answer** (2026-09-26). It was not
 * for a day, and that day is the reason this paragraph exists: the document
 * named the organisation and the person while `Taxation` went on deciding
 * between the product's supplier settings and the tenant's customer profile.
 * A platform in IE, a French tenant with a verified VAT number, and a seat
 * sold in France to a French colleague came out `REVERSE_CHARGE` at 0% — an
 * immutable fiscal fact, filed in the platform's own return.
 *
 * So `forSale` returns the pair {@see TaxableSale} as well, and nothing
 * downstream resolves it again. For a seat the supplier is the organisation:
 * its country from its billing profile, whether it charges VAT at all from
 * its tax profile's `taxablePerson`, never registered for the one-stop shop,
 * and the customer is the person as a consumer. A company under the
 * small-business threshold therefore invoices its colleague with no VAT and
 * the mention that says why, which is what such a company must do.
 */
final class WhoSellsAndWhoBuys
{
    public function __construct(
        private readonly BillingProfileRepository $profiles,
        private readonly SupplierIdentity $supplier,
        private readonly UserRepository $users,
        private readonly Taxation $taxation,
    ) {
    }

    /**
     * @throws ConflictException BILLING_PROFILE_REQUIRED when the organisation
     *                           has no profile, or a seat is being sold by an
     *                           organisation whose profile does not say which
     *                           country it sells from
     * @throws ConflictException BILLING_NOT_CONFIGURED when the platform sells
     *                           and the product has no billing identity
     */
    public function forSale(string $tenantId, string $productId, Subscriber $subscriber): InvoiceParties
    {
        $profile = $this->profiles->find($tenantId);

        if ($profile === null) {
            // Refused before anything is written: numbering is gapless, so a
            // document raised by mistake cannot be deleted.
            throw new ConflictException(
                'BILLING_PROFILE_REQUIRED',
                'This tenant has no billing profile, so nothing can be invoiced to it.',
            );
        }

        $organisation = $profile->snapshot();

        if (!$subscriber->isSeat()) {
            $supplier = $this->supplier->forProduct($productId);

            return new InvoiceParties(
                null,
                $supplier,
                $organisation,
                SupplierIdentity::jurisdictionOf($supplier),
                $this->taxation->platformSelling($tenantId, $productId),
            );
        }

        if ($profile->countryCode === null) {
            // The organisation is the supplier here, and a supplier with no
            // country cannot say under which regime it sells. Guessing was
            // what this did until today, and the guess was the platform's
            // country — somebody else's VAT return.
            throw new ConflictException(
                'BILLING_PROFILE_REQUIRED',
                'This organisation sells this seat, and its billing profile does not say from which country.',
                ['missing' => ['country_code']],
            );
        }

        // The organisation's own fiscal position, not the product's. Whether
        // it charges VAT is `taxablePerson` — the one fact §25.3 says cannot
        // be inferred from anything else — read from the profile it keeps for
        // its own purchases, because a company's VAT status is one status.
        //
        // **Registered unless the organisation has said it is not.** The
        // same default the platform's own configuration carries, and for the
        // same reason: charging VAT is the ordinary case and a company below
        // the small-business threshold is the exception that states itself.
        //
        // The alternative — refusing until somebody has opened the tax
        // screen — was written first and thrown away. It kills the flow the
        // storefront exists for: a stranger signs up, a tenant is created,
        // and they buy in the same minute (ADR-041). There is no moment in
        // that minute to fill in a fiscal profile, and a purchase that
        // refused would be the last thing they tried.
        //
        // It fails in the recoverable direction. VAT charged by a company
        // that owed none is corrected by a credit note, which since today
        // reverses the fiscal fact with it; VAT not charged by a company that
        // owed it is a debt found at the declaration, with nothing on the
        // customer's side to collect it from.
        //
        // `profileFor` is deliberately not used: its unknown-B2C default
        // answers `taxablePerson = false`, which is the right answer about a
        // customer nobody knows and the opposite of the one wanted here.
        //
        // What is supplied and what it costs stay the product's: a seat is
        // the same service, resold, at the price the offer set.
        $product = $this->taxation->supplierFor($productId);
        $fiscal = $this->taxation->declaredProfileFor($tenantId);
        $registered = $fiscal === null || $fiscal->taxablePerson;

        return new InvoiceParties(
            $tenantId,
            $organisation,
            $this->customer($subscriber, $organisation),
            $profile->countryCode,
            new TaxableSale(
                SupplierTaxSettings::forOrganisation(
                    $profile->countryCode,
                    $registered,
                    $product->defaultSupplyType,
                    $product->currency,
                ),
                CustomerTaxProfile::forSeatHolder($tenantId, $profile->countryCode),
                $tenantId,
            ),
        );
    }

    /**
     * The person a seat is sold to, as the customer on the document: their
     * name — or their address, for somebody who gave none — copied in like
     * every snapshot, so a later change of name or an erasure (§26) leaves
     * the document as it was sent.
     *
     * Falls back to the organisation's identity when the person cannot be
     * found, which is a broken invariant rather than a case.
     *
     * @param array<string, mixed> $organisation
     *
     * @return array<string, mixed>
     */
    private function customer(Subscriber $subscriber, array $organisation): array
    {
        if ($subscriber->userId === null) {
            return $organisation;
        }

        $person = $this->users->find($subscriber->userId);

        if ($person === null) {
            return $organisation;
        }

        $name = $person->displayName ?? $person->email ?? 'A member';

        return [
            'legal_name' => $name,
            'billing_email' => $person->email,
            'country_code' => $organisation['country_code'] ?? null,
            'person' => ['name' => $name, 'email' => $person->email],
            'organisation' => $organisation['legal_name'] ?? null,
            // The language the document is issued in (ADR-050): theirs.
            'locale' => $person->locale,
        ];
    }
}
