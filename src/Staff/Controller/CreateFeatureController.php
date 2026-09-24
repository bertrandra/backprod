<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Domain\Feature;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\FeatureDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/features — a billable capability, platform-wide.
 *
 * No product (2026-09-24, `docs/translatable-fields-spec.md` §4): there is
 * one list, and a product's catalogue picks from it. `max_projects` existed
 * once per product until this route moved, and the code that reads it
 * depended on every one of those rows having been spelled the same.
 *
 * `kind` is BOOLEAN or QUOTA, and it is chosen here because it can never
 * change: every grant written against a feature was written meaning one or the
 * other — a quota's grant carries a limit, a boolean's carries null — and
 * flipping it would reinterpret rows already priced into live subscriptions.
 * A feature that should have been the other kind is a new feature.
 *
 * `unit` is what a quota is counted in and is refused on a boolean.
 */
final class CreateFeatureController implements RouteHandler
{
    private const KINDS = [Feature::BOOLEAN, Feature::QUOTA];

    public function __construct(private readonly FeatureDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::FEATURES_MANAGE);

        $body = JsonBody::of($request);
        $kind = strtoupper($body->requiredString('kind', 16));

        if (!in_array($kind, self::KINDS, true)) {
            // Named here rather than left to `features_kind_known`, which would
            // refuse with a constraint's name.
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'kind', 'requirement' => 'must be one of ' . implode(', ', self::KINDS)],
            );
        }

        $feature = $this->desk->create(
            $context->identity,
            CatalogueRoute::code($body),
            $body->requiredString('name', 200),
            $kind,
            $body->optionalNullableString('unit', 40),
        );

        return new JsonResponse(['feature' => CataloguePresenter::feature($feature)], 201);
    }
}
