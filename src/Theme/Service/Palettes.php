<?php

declare(strict_types=1);

namespace App\Theme\Service;

use App\Shared\Exceptions\NotFoundException;
use App\Shared\Validation\Uuid;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;
use App\Theme\Domain\Palette;
use App\Theme\Domain\PaletteAssignments;
use App\Theme\Domain\PaletteName;
use App\Theme\Domain\PaletteRepository;
use App\Theme\Domain\TenantPaletteRepository;
use App\Theme\Domain\ThemeDocument;

/**
 * Palettes, and who wears which (2026-10-04).
 *
 * **Only the platform administrator changes a palette.** An organisation
 * chooses among them and never edits one: a palette is shared by every
 * organisation wearing it, so an edit is the platform's decision.
 *
 * **Which palette an organisation wears is one fact with two doors.** The
 * console assigns it from the matrix; the organisation's administrator
 * changes it on their own screen. Both write the same row, so each sees what
 * the other chose. A staff write leaves a trail row, like every staff write
 * that reaches a tenant.
 */
final class Palettes
{
    public function __construct(
        private readonly PaletteRepository $palettes,
        private readonly TenantPaletteRepository $tenants,
        private readonly StaffAccessLog $trail,
    ) {
    }

    /**
     * @return list<Palette>
     */
    public function all(): array
    {
        return $this->palettes->all();
    }

    /**
     * @param array<mixed> $document decoded JSON
     */
    public function save(StaffIdentity $staff, string $name, array $document): Palette
    {
        $palette = $this->palettes->save(PaletteName::of($name), ThemeDocument::fromArray($document));

        // Every organisation wearing it changes with it, so who changed it is
        // worth being able to answer.
        $this->trail->record(new StaffAccess($staff->userId, null, null, 'SAVE_PALETTE', 'palette', null, StaffPermission::DESIGN_MANAGE, ['palette' => $palette->name]));

        return $palette;
    }

    public function assignments(int $limit, int $offset): PaletteAssignments
    {
        return $this->tenants->assignments($limit, $offset);
    }

    /** The console's door: any organisation, any product it holds. */
    public function assign(StaffIdentity $staff, string $tenantId, string $productId, ?string $name): ?Palette
    {
        $palette = $this->choose($tenantId, $productId, $name, $staff->userId);

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenantId,
            $productId,
            'ASSIGN_PALETTE',
            'tenant_palette',
            $tenantId,
            StaffPermission::DESIGN_MANAGE,
            ['palette' => $palette?->name],
        ));

        return $palette;
    }

    /** The organisation's door: its own tenant and product, from its context. */
    public function select(string $tenantId, string $productId, ?string $name, string $userId): ?Palette
    {
        return $this->choose($tenantId, $productId, $name, $userId);
    }

    public function selected(string $tenantId, string $productId): ?Palette
    {
        return $this->tenants->selected($tenantId, $productId);
    }

    private function choose(string $tenantId, string $productId, ?string $name, string $by): ?Palette
    {
        $palette = $name === null ? null : PaletteName::of($name);

        if ($palette !== null && $this->palettes->find($palette) === null) {
            throw new NotFoundException('No palette has that name.', ['palette' => $name], 'PALETTE_NOT_FOUND');
        }

        // An id that is not one is the same answer as an id of nobody's: the
        // console sends what it was given, and the database would otherwise
        // refuse the cast with an error nobody should see.
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !$this->tenants->holds($tenantId, $productId)) {
            throw new NotFoundException('The tenant does not hold that product.', [], 'TENANT_OR_PRODUCT_NOT_FOUND');
        }

        $this->tenants->select($tenantId, $productId, $palette, $by);

        return $this->tenants->selected($tenantId, $productId);
    }
}
