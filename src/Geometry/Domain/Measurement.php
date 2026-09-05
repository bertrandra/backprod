<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

/**
 * What one geometry measures, in the coordinate units it arrived in.
 *
 * There is no unit field and no conversion, because this platform never
 * learns which linear unit a projected coordinate system uses — Lambert-93 is
 * metres, a US state plane may be feet, and inventing "m²" for either would
 * be a claim nothing here can support. The caller chose the projection and
 * knows its unit; the answer is in it.
 */
final class Measurement
{
    public function __construct(
        public readonly int $vertices,
        public readonly float $area,
        public readonly float $perimeter,
        public readonly BoundingBox $boundingBox,
    ) {
    }
}
