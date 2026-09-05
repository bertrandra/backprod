<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

/**
 * The axis-aligned extent of a geometry, in the coordinate units it was sent
 * in. Cheap to compute and cheap to compare, which is what makes it the first
 * filter of any spatial search — and why it is returned alongside the area
 * rather than instead of it.
 */
final class BoundingBox
{
    public function __construct(
        public readonly float $minX,
        public readonly float $minY,
        public readonly float $maxX,
        public readonly float $maxY,
    ) {
    }
}
