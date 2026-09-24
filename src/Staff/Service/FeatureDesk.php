<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Commerce\Domain\CatalogueAdministration;
use App\Commerce\Domain\Feature;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;

/**
 * The platform's one list of features (2026-09-24, step 3 of
 * `docs/translatable-fields-spec.md`).
 *
 * A desk of its own rather than four more methods on {@see CatalogueDesk},
 * because the thing it writes has no product. Every act on that desk names
 * one — it is a *product's* price list — and keeping these here is what
 * stops a product code being asked for, ignored, and then quietly relied on
 * by the next person who reads the signature.
 *
 * **Writes are recorded, reads are not**, the same rule as the catalogue:
 * non-negotiable #21 traces staff crossing into a tenant's data, and this
 * list belongs to nobody's tenant. What is recorded is every act that
 * changes what a product may be sold, because "who added this capability?"
 * is a question somebody eventually asks.
 */
final class FeatureDesk
{
    public function __construct(
        private readonly CatalogueAdministration $administration,
        private readonly StaffAccessLog $trail,
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
        $feature = $this->administration->createFeature($code, $name, $kind, $unit);

        $this->record($staff, 'CREATE', $feature->id, [
            'code' => $feature->code,
            'kind' => $feature->kind,
        ]);

        return $feature;
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

        // Retiring is its own act in the trail. Renaming a capability and
        // withdrawing one are not the same decision, and an auditor reading
        // a column of RENAME rows would never find the day a product lost
        // the ability to sell something.
        $action = $active === false ? 'RETIRE' : ($active === true ? 'REINSTATE' : 'RENAME');

        // The languages it now says something in, rather than what it says
        // in them: a trail answers "who changed this, and roughly what", and
        // four paragraphs of marketing copy in an audit row is neither
        // readable nor anybody's business later.
        $this->record($staff, $action, $feature->id, [
            'name' => $feature->name,
            'translated' => array_keys($feature->translations),
        ]);

        return $feature;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function record(StaffIdentity $staff, string $action, string $featureId, array $detail): void
    {
        $this->trail->record(new StaffAccess(
            $staff->userId,
            // Neither a tenant nor a product: the list is the platform's own,
            // and naming either here would invent a scope this decision was
            // not about.
            null,
            null,
            $action,
            'feature',
            $featureId,
            StaffPermission::FEATURES_MANAGE,
            $detail,
        ));
    }
}
