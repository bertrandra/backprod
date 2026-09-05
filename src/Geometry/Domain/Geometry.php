<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

/**
 * A planar geometry this platform is willing to compute on.
 *
 * Deliberately narrower than GeoJSON. Only a point and a simple polygon get
 * in, because those are the two shapes every operation behind
 * {@see GeoProvider} answers correctly on the backend of §19 phase 1. A
 * MultiPolygon, a polygon with a hole, or a shape with a Z ordinate has no
 * correct answer there, and the refusal is at the parser rather than here —
 * by the time a Geometry exists it has already been established as sound.
 *
 * A polygon's ring is held **without** the repeated closing position. GeoJSON
 * requires that repeat and PostgreSQL's `polygon` literal forbids it, so one
 * of the two has to be the internal form; dropping it means the vertex count
 * is `count($positions)` rather than something that has to be explained.
 */
final class Geometry
{
    public const POINT = 'Point';
    public const POLYGON = 'Polygon';

    /**
     * @param list<array{float, float}> $positions
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $positions,
    ) {
    }

    /**
     * @param array{float, float} $position
     */
    public static function point(array $position): self
    {
        return new self(self::POINT, [$position]);
    }

    /**
     * The ring, already validated as simple and closed, with the closing
     * position removed.
     *
     * @param list<array{float, float}> $ring
     */
    public static function polygon(array $ring): self
    {
        return new self(self::POLYGON, $ring);
    }

    public function isPolygon(): bool
    {
        return $this->kind === self::POLYGON;
    }

    /**
     * How many distinct vertices the shape has: one for a point, and for a
     * polygon the ring's corners, counting the closing position once.
     */
    public function vertices(): int
    {
        return count($this->positions);
    }
}
