<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Shared\Http\Translations;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/catalogue/features/{featureId}?product=CODE — correct
 * what a feature is called, in every language it is called something.
 *
 * Its code is how grants and entitlements name it; its kind decides how every
 * grant already written against it is read. Both are absent from the body by
 * omission rather than by refusal: there is nothing to send.
 *
 * The name, the description and the four translations arrive **together**
 * (2026-09-24, `docs/translatable-fields-spec.md`) and are written in one
 * transaction. A route per language would allow a state nobody would think
 * to look for: renamed in English, still saying the old thing in Spanish.
 */
final class RenameFeatureController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $body = JsonBody::of($request);

        $feature = $this->desk->renameFeature(
            $context->identity,
            StaffRoute::productCode($request),
            StaffRoute::id($request, 'featureId'),
            $body->requiredString('name', 200),
            $body->has('description'),
            $body->has('description') ? $body->optionalNullableString('description', 500) : null,
            $body->has('translations') ? Translations::of($body, 'translations') : null,
        );

        return new JsonResponse(['feature' => CataloguePresenter::feature($feature)], 200);
    }
}
