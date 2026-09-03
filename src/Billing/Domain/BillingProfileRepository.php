<?php

declare(strict_types=1);

namespace App\Billing\Domain;

interface BillingProfileRepository
{
    public function find(string $tenantId): ?BillingProfile;

    /**
     * Creates or replaces the tenant's profile. One per tenant: a company
     * has one legal identity, and a second row would leave "which one gets
     * invoiced" to whichever query ran.
     */
    public function save(BillingProfile $profile): BillingProfile;
}
