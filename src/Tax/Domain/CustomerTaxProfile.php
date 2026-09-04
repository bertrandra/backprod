<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * Who the customer is, fiscally (§25.3, object 1 of the six).
 *
 * Deliberately not `BillingProfile`. That one says who to address the
 * document to; this one says what governs the tax on it. A customer can move
 * office without changing tax status, and can become VAT-registered without
 * moving, and a single object would make each change look like the other.
 *
 * `taxablePerson` is the fact that matters and the one that cannot be
 * inferred: a country code says where somebody is, never whether they are a
 * business acting as such.
 */
final class CustomerTaxProfile
{
    public const B2B = 'B2B';
    public const B2C = 'B2C';

    /**
     * @param array<string, mixed> $locationEvidence
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $customerKind,
        public readonly ?string $countryCode,
        public readonly bool $taxablePerson,
        public readonly array $locationEvidence,
        public readonly ?TaxIdentification $identification,
    ) {
    }

    /**
     * A B2B customer whose VAT number has actually been verified.
     *
     * The two halves are separate on purpose. Being a business is a status;
     * having proved a number is evidence. Reverse charge needs both, and the
     * bug this method exists to prevent is treating either one as the other.
     */
    public function isVerifiedBusiness(): bool
    {
        return $this->customerKind === self::B2B
            && $this->taxablePerson
            && $this->identification !== null
            && $this->identification->isVerified();
    }

    public function isBusiness(): bool
    {
        return $this->customerKind === self::B2B && $this->taxablePerson;
    }

    /**
     * An unregistered customer with no country is not the supplier's country
     * by default — it is simply unknown, and §25.3 says the place of taxation
     * is a computed and retained value, never a fallback.
     */
    public function hasKnownCountry(): bool
    {
        return $this->countryCode !== null;
    }
}
