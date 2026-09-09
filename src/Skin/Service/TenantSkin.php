<?php

declare(strict_types=1);

namespace App\Skin\Service;

use App\Shared\Exceptions\UnprocessableEntityException;
use App\Skin\Domain\Skin;
use App\Skin\Domain\SkinRepository;
use App\Storage\Service\Assets;
use App\Storage\Service\UploadPolicy;

/**
 * A tenant's colours and logo (§7's Skins / White label block).
 *
 * The logo goes through {@see Assets} rather than being stored here. That
 * path already sniffs the content type from the bytes rather than trusting
 * the request's claim, refuses SVG for the stored-XSS reason ADR-028 gives,
 * and serves through signed links — none of which is worth having a second,
 * slightly different copy of.
 */
final class TenantSkin
{
    /**
     * A logo is a picture. The upload allowlist is wider than that because it
     * also carries exports and attachments, so the narrower list applies here
     * — checked against the *sniffed* type rather than against anything the
     * request said about itself, and checked before a byte is written.
     */
    private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Assets $assets,
        private readonly UploadPolicy $policy,
    ) {
    }

    public function forTenant(string $tenantId, string $productId): Skin
    {
        return $this->skins->forTenant($tenantId, $productId);
    }

    /**
     * @param array<string, string|null> $changes
     */
    public function change(string $tenantId, string $productId, array $changes, ?string $actorId): Skin
    {
        return $this->skins->save($tenantId, $productId, $changes, $actorId);
    }

    /**
     * Stores the bytes as an asset and points the skin at it.
     *
     * The previous logo is left in place rather than deleted. It may be on a
     * page somebody has open, in a PDF already generated, or in a cache; and
     * `assets` is where files are managed, with its own endpoint for removing
     * one deliberately.
     */
    public function setLogo(
        string $tenantId,
        string $productId,
        string $contents,
        string $filename,
        ?string $actorId,
    ): Skin {
        // Asked before anything is stored, not after. Uploading first and
        // refusing afterwards would leave the bytes in the store and an
        // orphaned row in `assets` — exactly the trace UploadPolicy exists to
        // avoid leaving behind for a refused upload.
        $this->mustBeAPicture($this->policy->inspect($contents)['content_type']);

        $asset = $this->assets->upload($tenantId, $productId, null, $contents, $filename, $actorId);

        return $this->skins->save($tenantId, $productId, ['logo_asset_id' => $asset->id], $actorId);
    }

    /**
     * Clears the logo from the skin. The asset itself stays: this endpoint
     * says "stop using this as our logo", which is not the same instruction
     * as "destroy this file".
     */
    public function removeLogo(string $tenantId, string $productId, ?string $actorId): Skin
    {
        return $this->skins->save($tenantId, $productId, ['logo_asset_id' => null], $actorId);
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
