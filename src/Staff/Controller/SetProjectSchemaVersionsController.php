<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Project\Domain\SchemaVersions;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ConfigurationDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/configuration/project-schema-versions?product=CODE — which
 * project document schema versions the product accepts (non-negotiable #10).
 *
 * **Why this exists.** `PostgresProductDirectory::create()` writes a row in
 * `products` and none in `product_configuration`, and a product that has
 * declared no versions accepts no document of any version — so every product
 * created through the console refused every project, and nothing on the
 * platform could change it. Only `bin/seed-demo.php` had ever written the key.
 * That is the same hole ADR-042 found in the billing identity, one layer down,
 * and it gets the same answer: a third named key on the configuration desk.
 *
 * **PUT rather than PATCH**, like the two keys beside it: the list is one
 * answer, not a set of fields, and retiring a version means sending the list
 * without it. "Omitted means leave it" would make removing one impossible.
 *
 * The body is validated by {@see SchemaVersions::parse()} rather than here,
 * because the reading half lives in the same class and the two have to agree
 * about what a version is — a console that stored what the policy would drop
 * would show a product accepting a version it refuses.
 */
final class SetProjectSchemaVersionsController implements RouteHandler
{
    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $versions = $this->desk->setSchemaVersions(
            $context->identity,
            StaffRoute::productCode($request),
            SchemaVersions::parse(
                JsonBody::of($request)->requiredIntList('supported', SchemaVersions::LIMIT),
            ),
        );

        return new JsonResponse(['project_schema_versions' => $versions], 200);
    }
}
