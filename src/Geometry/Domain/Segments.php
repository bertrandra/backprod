<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

/**
 * Do two line segments touch?
 *
 * The one piece of computational geometry this platform does for itself,
 * because it is the piece that has to run **before** the database sees the
 * shape. Core PostgreSQL has no notion of a valid polygon: it takes a
 * self-intersecting ring, computes the shoelace sum, and returns whatever
 * that cancellation leaves — zero for a bowtie. There is no error to catch
 * downstream, so the check happens here or the wrong number ships.
 *
 * Orientation is computed as a cross product rather than by comparing slopes,
 * which avoids dividing by a zero run for vertical edges. It is not exact —
 * these are floats, and a near-degenerate ring can fall either side of the
 * decision — but it is the same arithmetic the backend uses, so the two agree
 * about what they are looking at.
 */
final class Segments
{
    /**
     * @param array{float, float} $a1
     * @param array{float, float} $a2
     * @param array{float, float} $b1
     * @param array{float, float} $b2
     */
    public static function cross(array $a1, array $a2, array $b1, array $b2): bool
    {
        $d1 = self::orientation($b1, $b2, $a1);
        $d2 = self::orientation($b1, $b2, $a2);
        $d3 = self::orientation($a1, $a2, $b1);
        $d4 = self::orientation($a1, $a2, $b2);

        // A proper crossing: each segment has one endpoint strictly on either
        // side of the other's line.
        if ((($d1 > 0) !== ($d2 > 0)) && $d1 !== 0.0 && $d2 !== 0.0
            && (($d3 > 0) !== ($d4 > 0)) && $d3 !== 0.0 && $d4 !== 0.0) {
            return true;
        }

        // Collinear touching, which a cross product alone reports as "neither
        // side". A ring that doubles back along an edge it already drew is
        // not simple either, and it is the shape a badly closed survey
        // produces most often.
        return ($d1 === 0.0 && self::between($b1, $b2, $a1))
            || ($d2 === 0.0 && self::between($b1, $b2, $a2))
            || ($d3 === 0.0 && self::between($a1, $a2, $b1))
            || ($d4 === 0.0 && self::between($a1, $a2, $b2));
    }

    /**
     * Twice the signed area of the triangle abc: positive when c is left of
     * a→b, negative when right, zero when the three are collinear.
     *
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @param array{float, float} $c
     */
    private static function orientation(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    /**
     * Whether a point already known to be collinear with a→b lies within its
     * bounding box, and therefore on the segment rather than beyond an end.
     *
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @param array{float, float} $p
     */
    private static function between(array $a, array $b, array $p): bool
    {
        return $p[0] >= min($a[0], $b[0]) && $p[0] <= max($a[0], $b[0])
            && $p[1] >= min($a[1], $b[1]) && $p[1] <= max($a[1], $b[1]);
    }
}
