<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use App\Billing\Domain\LineOffer;

/**
 * What an offer version sold, for the documents that name one (2026-09-19).
 *
 * A document line carries its amounts as a snapshot and the version that
 * priced it as an id; a customer reading the invoice wants neither the id
 * nor a bare "Pro monthly (v1)" but the product, the plan and how it is
 * billed. This answers that for many versions at once, so a list of
 * documents costs one query and not one per line.
 */
interface OfferLineDetails
{
    /**
     * @param list<string> $versionIds
     *
     * @return array<string, LineOffer> by version id; a version nobody can find is simply absent
     */
    public function describe(array $versionIds): array;
}
