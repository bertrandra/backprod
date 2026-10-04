<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Commerce\Domain\CatalogueAdministration;
use App\Commerce\Domain\Feature;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffIdentity;

/**
 * The platform's one list of features (2026-09-24, step 3 of
 * `docs/translatable-fields-spec.md`).
 *
 * A desk of its own rather than four more methods on {@see CatalogueDesk},
 * because the thing it writes has no product. Every act on that desk names
 * one — it is a *product's* price list — and keeping these here is what
 * stops a product code being asked for, ignored, and then quietly relied on
 * by the next person who reads the signature.
 */
final class FeatureDesk
{
    public function __construct(
        private readonly CatalogueAdministration $administration,
    ) {
    }

    /**
     * Every feature, retired ones included.
     *
     * The retired are shown rather than filtered: the code is still taken,
     * and a list that hid it would refuse a retyped code with nothing on
     * screen to explain the refusal.
     *
     * @return list<Feature>
     */
    public function features(): array
    {
        return $this->administration->features();
    }

    public function create(
        StaffIdentity $staff,
        string $code,
        string $name,
        string $kind,
        ?string $unit,
    ): Feature {
        return $this->administration->createFeature($code, $name, $kind, $unit);
    }

    /**
     * @param array<string, array{name?: ?string, description?: ?string}>|null $translations
     */
    public function update(
        StaffIdentity $staff,
        string $featureId,
        string $name,
        bool $setDescription = false,
        ?string $description = null,
        ?array $translations = null,
        ?bool $active = null,
    ): Feature {
        $feature = $this->administration->renameFeature(
            $featureId,
            $name,
            $setDescription,
            $description,
            $translations,
            $active,
        );

        if ($feature === null) {
            throw new NotFoundException('Feature not found.', [], 'FEATURE_NOT_FOUND');
        }

        return $feature;
    }
}
