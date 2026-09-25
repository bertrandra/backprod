<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\InvoiceParties;
use App\Commerce\Domain\Subscriber;
use App\Shared\Exceptions\ConflictException;
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
 */
final class WhoSellsAndWhoBuys
{
    public function __construct(
        private readonly BillingProfileRepository $profiles,
        private readonly SupplierIdentity $supplier,
        private readonly UserRepository $users,
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

        return new InvoiceParties(
            $tenantId,
            $organisation,
            $this->customer($subscriber, $organisation),
            $profile->countryCode,
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
