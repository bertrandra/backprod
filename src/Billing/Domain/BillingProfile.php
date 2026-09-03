<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Who a tenant is, for billing purposes, as it stands today.
 *
 * Invoices copy from this at issue time rather than pointing at it. A
 * customer moving office, renaming the company or correcting a VAT number
 * must not rewrite invoices already filed by their accountant (§25).
 */
final class BillingProfile
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $legalName,
        public readonly ?string $vatNumber,
        public readonly ?string $registrationNumber,
        public readonly ?string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly ?string $countryCode,
        public readonly ?string $billingEmail,
    ) {
    }

    /**
     * The snapshot an invoice keeps. A plain structure rather than this
     * object, because what is stored must stay readable when this class
     * changes shape — an invoice from two years ago has to render without
     * today's code agreeing about fields.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'legal_name' => $this->legalName,
            'vat_number' => $this->vatNumber,
            'registration_number' => $this->registrationNumber,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'billing_email' => $this->billingEmail,
        ];
    }
}
