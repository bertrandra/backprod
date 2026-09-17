<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * The organisation that lives at `hostname/` with no slug (2026-09-17).
 *
 * Every tenant has a URL root of its own, `hostname/{slug}/`; one of them —
 * the operator's, by decision — is addressed by the bare host. Which one is
 * a platform setting, chosen by the platform administrator beside the menu
 * setup, and nothing else about the tenant differs.
 */
interface DefaultTenant
{
    public function id(): ?string;

    /** Null clears it: the bare host then answers as it did before roots. */
    public function set(?string $tenantId): void;
}
