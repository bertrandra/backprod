<?php

declare(strict_types=1);

namespace App\Geometry\Controller;

use App\Geometry\Domain\GeoJson;
use App\Geometry\Domain\Geometry;
use App\Geometry\Service\Geometries;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/geometry/measure` — the area, perimeter and extent of one
 * polygon, in the units it arrived in.
 */
final class MeasureGeometryController implements RouteHandler
{
    public function __construct(private readonly Geometries $geometries)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        GeoRoute::computable($request);

        $body = JsonBody::of($request);
        $reference = GeoRoute::reference($body);
        $polygon = GeoJson::parse($body->requiredObject('geometry'), 'geometry', [Geometry::POLYGON]);

        $measurement = $this->geometries->measure($polygon, $reference);

        return new JsonResponse(['measurement' => GeometryPresenter::measurement($measurement)], 200);
    }
}
