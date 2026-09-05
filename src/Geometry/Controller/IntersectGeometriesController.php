<?php

declare(strict_types=1);

namespace App\Geometry\Controller;

use App\Geometry\Domain\GeoJson;
use App\Geometry\Domain\Geometry;
use App\Geometry\Service\Geometries;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/geometry/intersections` — one subject against a set of
 * candidate polygons, answered in the order they were sent.
 *
 * The candidates carry ids the caller chose, and those ids come straight back
 * out. That is what makes the endpoint usable against a parcel register the
 * platform has never seen: the caller holds the mapping, and this side holds
 * none of it.
 */
final class IntersectGeometriesController implements RouteHandler
{
    public function __construct(private readonly Geometries $geometries)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        GeoRoute::computable($request);

        $body = JsonBody::of($request);
        $reference = GeoRoute::reference($body);

        // A point subject answers "which parcel is this address in"; a
        // polygon subject answers "what does this footprint touch". The
        // candidates are parcels either way, so they are polygons either way.
        $subject = GeoJson::parse(
            $body->requiredObject('subject'),
            'subject',
            [Geometry::POINT, Geometry::POLYGON],
        );

        $candidates = [];

        foreach ($body->requiredObjectList('candidates', Geometries::MAX_CANDIDATES) as $index => $candidate) {
            $id = $candidate->id ?? null;

            if (!is_string($id) || trim($id) === '') {
                throw self::invalid($index, 'must carry a non-empty string "id"');
            }

            $id = trim($id);

            if (array_key_exists($id, $candidates)) {
                // Silently keeping the last would drop an answer the caller
                // asked for, and returning two rows with one id would make
                // the response impossible to index by.
                throw self::invalid($index, sprintf('repeats the id "%s"; candidate ids must be distinct', $id));
            }

            $candidates[$id] = GeoJson::parse(
                $candidate->geometry ?? null,
                sprintf('candidates[%d].geometry', $index),
                [Geometry::POLYGON],
            );
        }

        $relations = $this->geometries->intersections($subject, $candidates, $reference);

        return new JsonResponse(
            ['relations' => array_map(GeometryPresenter::relation(...), $relations)],
            200,
        );
    }

    private static function invalid(int $index, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => sprintf('candidates[%d]', $index), 'requirement' => $requirement],
        );
    }
}
