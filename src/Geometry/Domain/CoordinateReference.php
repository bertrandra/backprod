<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

/**
 * Whether the coordinates the caller sent are metres on a plane or degrees on
 * a globe. The caller has to say, because nothing in the numbers tells us.
 *
 * A parcel in Lambert-93 and the same parcel in WGS 84 are both lists of two
 * finite numbers, and both fall inside the ranges a longitude and a latitude
 * occupy. Guessing from magnitude would be right most of the time, and a
 * geometry engine that is right most of the time about which units it is in
 * is a geometry engine that reports a 1200 m² plot as 0.00000015.
 *
 * So this is a required field rather than an inferred one, and it is the
 * hinge the measurement rules turn on: lengths and areas mean something on a
 * projected plane and nothing in degrees, while topology — does this overlap
 * that, does this contain that — is the same question either way.
 */
enum CoordinateReference: string
{
    /**
     * A planar coordinate system in linear units, such as Lambert-93 (EPSG
     * 2154) or a UTM zone. Distances and areas are in those units squared;
     * this platform never learns which unit, and never claims to.
     */
    case PROJECTED = 'PROJECTED';

    /**
     * Longitude and latitude in degrees, GeoJSON's default. Topology is
     * answerable; length and area are not, because a degree of longitude is
     * not a fixed distance.
     */
    case GEOGRAPHIC = 'GEOGRAPHIC';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
