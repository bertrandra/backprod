<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/catalogue/features/{featureId}?product=CODE — correct a
 * feature's name.
 *
 * The only thing about a feature that may change. Its code is how grants and
 * entitlements name it; its kind decides how every grant already written
 * against it is read. Both are absent from the body by omission rather than by
 * refusal: there is nothing to send.
 */
final class RenameFeatureController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $feature = $this->desk->renameFeature(
            $context->identity,
            StaffRoute::productCode($request),
            StaffRoute::id($request, 'featureId'),
            JsonBody::of($request)->requiredString('name', 200),
        );

        return new JsonResponse(['feature' => CataloguePresenter::feature($feature)], 200);
    }
}
