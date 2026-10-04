<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Shared\Exceptions\UnprocessableEntityException;
use App\Storage\Service\Assets;
use App\Storage\Service\UploadPolicy;
use App\Tenant\Domain\Tenant;
use App\Tenant\Domain\TenantRepository;

/**
 * The organisation's logo (2026-10-05).
 *
 * Part of what the organisation *is*, like its name: one for the
 * organisation, in whatever product it is seen, set by its administrator
 * (`tenant.manage`) and tied to no offer. It used to be half of a per-product
 * "skin" sold as `white_label`, whose colours nothing ever painted; the
 * colours went, the palettes answer that question now, and the logo came here.
 *
 * The bytes go through {@see Assets} rather than being stored here. That path
 * already sniffs the content type from the bytes rather than trusting the
 * request's claim, refuses SVG for the stored-XSS reason ADR-028 gives, and
 * serves through signed links — none of which is worth a second copy of.
 */
final class TenantLogo
{
    /**
     * A logo is a picture. The upload allowlist is wider than that because it
     * also carries exports and attachments, so the narrower list applies here
     * — checked against the *sniffed* type, and before a byte is written.
     */
    private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly TenantProfile $profile,
        private readonly TenantRepository $tenants,
        private readonly Assets $assets,
        private readonly UploadPolicy $policy,
    ) {
    }

    /**
     * Stores the bytes as an asset and points the organisation at it.
     *
     * The asset is filed under the product the administrator was using, as
     * every asset is; the organisation's pointer does not care which.
     *
     * The previous logo is left in place rather than deleted: it may be on a
     * page somebody has open or in a PDF already generated, and `assets` has
     * its own endpoint for removing a file deliberately.
     */
    public function set(string $tenantId, string $productId, string $contents, string $filename, ?string $actorId): Tenant
    {
        $this->profile->current($tenantId);

        // Asked before anything is stored, not after: refusing afterwards
        // would leave the bytes in the store and an orphaned row in `assets`.
        $this->mustBeAPicture($this->policy->inspect($contents)['content_type']);

        $asset = $this->assets->upload($tenantId, $productId, null, $contents, $filename, $actorId);
        $this->tenants->chooseLogo($tenantId, $asset->id);

        return $this->profile->current($tenantId);
    }

    /**
     * "Stop using this as our logo", which is not "destroy this file": the
     * asset stays.
     */
    public function remove(string $tenantId): Tenant
    {
        $this->profile->current($tenantId);
        $this->tenants->chooseLogo($tenantId, null);

        return $this->profile->current($tenantId);
    }

    private function mustBeAPicture(string $contentType): void
    {
        if (in_array($contentType, self::LOGO_TYPES, true)) {
            return;
        }

        throw new UnprocessableEntityException(
            'LOGO_NOT_AN_IMAGE',
            'A logo has to be a picture.',
            ['content_type' => $contentType, 'allowed' => self::LOGO_TYPES],
        );
    }
}
