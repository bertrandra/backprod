<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Shared\Http\Translations;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\FeatureDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/features/{featureId} — correct what a feature is
 * called, in every language it is called something, and whether it may still
 * be granted.
 *
 * Its code is how grants and entitlements name it; its kind decides how every
 * grant already written against it is read. Both are absent from the body by
 * omission rather than by refusal: there is nothing to send.
 *
 * `active` false retires it. That is not a delete and is not meant to be
 * one: every entitlement resting on this row keeps resting on it, and what
 * stops is a new offer granting it.
 *
 * The name, the description and the four translations arrive **together**
 * (2026-09-24, `docs/translatable-fields-spec.md`) and are written in one
 * transaction. A route per language would allow a state nobody would think
 * to look for: renamed in English, still saying the old thing in Spanish.
 */
final class RenameFeatureController implements RouteHandler
{
    public function __construct(private readonly FeatureDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::FEATURES_MANAGE);

        $body = JsonBody::of($request);

        $feature = $this->desk->update(
            $context->identity,
            StaffRoute::id($request, 'featureId'),
            $body->requiredString('name', 200),
            $body->has('description'),
            $body->has('description') ? $body->optionalNullableString('description', 500) : null,
            $body->has('translations') ? Translations::of($body, 'translations') : null,
            $body->has('active') ? $body->requiredBool('active') : null,
        );

        return new JsonResponse(['feature' => CataloguePresenter::feature($feature)], 200);
    }
}
