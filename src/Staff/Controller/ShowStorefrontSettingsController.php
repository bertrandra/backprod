<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Domain\StorefrontSettings;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/storefront/settings — how a self-service sign-up ends.
 * `staff.catalog.manage`, the storefront console's own permission.
 */
final class ShowStorefrontSettingsController implements RouteHandler
{
    public function __construct(private readonly StorefrontSettings $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        return new JsonResponse(['after_sign_up' => $this->settings->afterSignUp()], 200);
    }
}
