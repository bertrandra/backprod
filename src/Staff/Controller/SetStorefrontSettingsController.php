<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Domain\StorefrontSettings;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/storefront/settings — decide how a self-service sign-up
 * ends: the checkout right there (`PAY`), or the application first, where
 * the offer is picked again from the catalogue (`CATALOGUE`). Platform-wide,
 * behind `staff.catalog.manage` like the rest of the storefront console.
 */
final class SetStorefrontSettingsController implements RouteHandler
{
    public function __construct(private readonly StorefrontSettings $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $choice = JsonBody::of($request)->requiredString('after_sign_up', 16);

        if (!in_array($choice, StorefrontSettings::AFTER_SIGN_UP, true)) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'after_sign_up', 'requirement' => 'must be one of ' . implode(', ', StorefrontSettings::AFTER_SIGN_UP)],
            );
        }

        $this->settings->setAfterSignUp($choice);

        return new JsonResponse(['after_sign_up' => $this->settings->afterSignUp()], 200);
    }
}
