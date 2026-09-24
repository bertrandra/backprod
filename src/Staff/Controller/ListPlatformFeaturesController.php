<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Domain\Feature;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\FeatureDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/features — the platform's one list of features.
 *
 * No product parameter, unlike every other console catalogue route: since
 * 2026-09-24 a feature belongs to the platform and a product's catalogue
 * picks from this list (`docs/translatable-fields-spec.md` §4).
 *
 * Retired features are listed. Their codes are still taken, and a list that
 * hid them would refuse a retyped code with nothing on screen to say why.
 */
final class ListPlatformFeaturesController implements RouteHandler
{
    public function __construct(private readonly FeatureDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::FEATURES_MANAGE);

        return new JsonResponse([
            'features' => array_map(
                static fn (Feature $feature): array => CataloguePresenter::feature($feature),
                $this->desk->features(),
            ),
        ], 200);
    }
}
