<?php

declare(strict_types=1);

namespace App\Geometry\Service;

use App\Geometry\Domain\CoordinateReference;
use App\Geometry\Domain\Geometry;
use App\Geometry\Domain\GeoProvider;
use App\Geometry\Domain\Measurement;
use App\Geometry\Domain\SpatialRelation;
use App\Shared\Exceptions\UnprocessableEntityException;

/**
 * The two spatial questions this platform answers, and the one rule about
 * units that decides which of them it will answer.
 *
 * Nothing here is tenant data. A caller sends geometry and gets arithmetic
 * back; no row is read and none is written, and two tenants asking the same
 * question get the same answer. Scope still matters — the capability that
 * opens these routes is resolved per (tenant, product) like every other — but
 * there is no isolation to enforce below the door, because there is nothing
 * on the other side of it to leak.
 */
final class Geometries
{
    /**
     * More than a parcel search needs and few enough that one statement stays
     * one statement. A caller with a département's worth of parcels wants the
     * spatial backend of §19 phase 2, not a larger request.
     */
    public const MAX_CANDIDATES = 200;

    public function __construct(private readonly GeoProvider $geo)
    {
    }

    /**
     * Area and perimeter, which exist only on a plane.
     *
     * Degrees are refused rather than converted. Converting would mean
     * choosing a projection on the caller's behalf, and the choice changes
     * the answer — a parcel measured in Web Mercator at the latitude of Lille
     * comes out roughly 2.4 times its true size. A platform that quietly
     * picked one would be inventing the number it reports.
     */
    public function measure(Geometry $polygon, CoordinateReference $crs): Measurement
    {
        if ($crs === CoordinateReference::GEOGRAPHIC) {
            throw new UnprocessableEntityException(
                'PROJECTION_REQUIRED',
                'Lengths and areas need projected coordinates.',
                [
                    'field' => 'crs',
                    'requirement' => 'must be PROJECTED; a degree of longitude is not a fixed distance, '
                        . 'so an area in degrees is not an area',
                ],
            );
        }

        return $this->geo->measure($polygon);
    }

    /**
     * Overlap, containment and proximity against each candidate.
     *
     * Accepted in either reference, because topology does not depend on the
     * unit: whether a footprint falls inside a parcel is the same question in
     * metres and in degrees. Distance is the part that does depend on it, so
     * that is the part withheld — see {@see SpatialRelation}.
     *
     * @param array<string, Geometry> $candidates
     *
     * @return list<SpatialRelation>
     */
    public function intersections(Geometry $subject, array $candidates, CoordinateReference $crs): array
    {
        $relations = $this->geo->relate($subject, $candidates);

        if ($crs === CoordinateReference::PROJECTED) {
            return $relations;
        }

        return array_map(
            static fn (SpatialRelation $relation): SpatialRelation => $relation->withoutDistance(),
            $relations,
        );
    }
}
