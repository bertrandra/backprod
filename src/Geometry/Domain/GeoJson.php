<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\UnprocessableEntityException;
use stdClass;

/**
 * Turns decoded GeoJSON into a {@see Geometry}, or refuses it.
 *
 * Every rule this platform has about what a geometry may be lives here, and
 * they are all refusals rather than repairs. That is the point: the backend
 * of §19 phase 1 is core PostgreSQL, which will happily accept two of the
 * shapes below and return a confidently wrong number for them.
 *
 * - `polygon '((0,0),(1,1))'` parses. It is a line, and it has an area of
 *   zero, and nothing downstream can tell that apart from a real answer.
 * - A self-intersecting ring — the bowtie `((0,0),(4,4),(4,0),(0,4))` —
 *   measures **zero** area, because the shoelace sum cancels its two lobes.
 *   A surveyor who drew a figure-eight by mistake would be told their plot
 *   has no surface.
 *
 * Neither is a database error, so neither can be caught downstream; both are
 * caught here or not at all. The same reasoning rules out the shapes core
 * PostgreSQL cannot represent — a polygon with a hole, a MultiPolygon — where
 * the tempting repair (keep the outer ring, take the first part) silently
 * answers a different question from the one that was asked.
 */
final class GeoJson
{
    /**
     * Enough to describe a parcel or a building footprint several times over,
     * and low enough that the O(n²) simplicity test stays trivial. A caller
     * with a coastline is asking for a spatial backend, which is phase 2.
     */
    public const MAX_VERTICES = 512;

    /**
     * @param list<string> $kinds the GeoJSON types this call accepts
     */
    public static function parse(mixed $value, string $field, array $kinds): Geometry
    {
        if (!$value instanceof stdClass) {
            throw self::invalid($field, 'must be a GeoJSON geometry object');
        }

        $type = $value->type ?? null;

        if (!is_string($type) || !in_array($type, $kinds, true)) {
            throw self::invalid($field, sprintf(
                'must have a "type" of %s',
                implode(' or ', array_map(static fn (string $k): string => '"' . $k . '"', $kinds)),
            ));
        }

        $coordinates = $value->coordinates ?? null;

        if (!is_array($coordinates) || !array_is_list($coordinates)) {
            throw self::invalid($field, 'must have a "coordinates" array');
        }

        return $type === Geometry::POINT
            ? Geometry::point(self::position($coordinates, $field))
            : Geometry::polygon(self::ring($coordinates, $field));
    }

    /**
     * A GeoJSON position: exactly two finite numbers.
     *
     * A third ordinate is refused rather than dropped. GeoJSON allows one,
     * and a caller who sends elevations is working in three dimensions —
     * telling them "computed, in 2D" by silently discarding Z would be
     * answering a question they did not ask.
     *
     * @param list<mixed> $value
     *
     * @return array{float, float}
     */
    private static function position(array $value, string $field): array
    {
        if (count($value) !== 2) {
            throw self::invalid($field, 'positions must be [x, y]; this backend is two-dimensional');
        }

        return [self::ordinate($value[0], $field), self::ordinate($value[1], $field)];
    }

    private static function ordinate(mixed $value, string $field): float
    {
        // `is_numeric` would accept the string "1e3", which JSON never
        // produces for a number and which a caller sending strings did not
        // mean as one.
        if (!is_int($value) && !is_float($value)) {
            throw self::invalid($field, 'coordinates must be numbers');
        }

        $ordinate = (float) $value;

        if (!is_finite($ordinate)) {
            throw self::invalid($field, 'coordinates must be finite');
        }

        return $ordinate;
    }

    /**
     * The single closed ring of a simple polygon.
     *
     * @param list<mixed> $value
     *
     * @return list<array{float, float}>
     */
    private static function ring(array $value, string $field): array
    {
        if (count($value) !== 1) {
            throw self::invalid(
                $field,
                'must have exactly one linear ring; a polygon with holes needs a spatial backend',
            );
        }

        $raw = $value[0];

        if (!is_array($raw) || !array_is_list($raw)) {
            throw self::invalid($field, 'the linear ring must be an array of positions');
        }

        // Four positions is the GeoJSON minimum: three corners and the repeat
        // that closes the ring.
        if (count($raw) < 4) {
            throw self::invalid($field, 'a ring needs at least four positions, the last repeating the first');
        }

        if (count($raw) > self::MAX_VERTICES + 1) {
            throw self::invalid($field, sprintf('a ring may have at most %d positions', self::MAX_VERTICES + 1));
        }

        $positions = [];

        foreach ($raw as $item) {
            if (!is_array($item) || !array_is_list($item)) {
                throw self::invalid($field, 'the linear ring must be an array of positions');
            }

            $positions[] = self::position($item, $field);
        }

        if ($positions[0] !== $positions[count($positions) - 1]) {
            throw self::invalid($field, 'the ring must be closed: its last position must repeat its first');
        }

        // The closing repeat has done its job; from here the ring is the
        // corners, which is what a vertex count and a polygon literal want.
        array_pop($positions);

        return self::simple($positions, $field);
    }

    /**
     * @param list<array{float, float}> $ring
     *
     * @return list<array{float, float}>
     */
    private static function simple(array $ring, string $field): array
    {
        $count = count($ring);

        for ($i = 0; $i < $count; $i++) {
            if ($ring[$i] === $ring[($i + 1) % $count]) {
                throw self::degenerate($field, 'the ring repeats a position; every edge must have a length');
            }
        }

        // Every pair of non-adjacent edges. Adjacent edges meet at a shared
        // corner by construction, which is not a crossing.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $count - 1)) {
                    continue;
                }

                if (Segments::cross($ring[$i], $ring[($i + 1) % $count], $ring[$j], $ring[($j + 1) % $count])) {
                    throw self::degenerate(
                        $field,
                        'the ring crosses itself; a self-intersecting polygon has no well-defined area',
                    );
                }
            }
        }

        return $ring;
    }

    private static function invalid(string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => $field, 'requirement' => $requirement],
        );
    }

    /**
     * Well-formed JSON describing a shape that cannot exist, which is 422
     * rather than 400: the request was understood, and what it asked for is
     * not a polygon.
     */
    private static function degenerate(string $field, string $requirement): UnprocessableEntityException
    {
        return new UnprocessableEntityException(
            'GEOMETRY_NOT_SIMPLE',
            'The geometry is not a simple polygon.',
            ['field' => $field, 'requirement' => $requirement],
        );
    }
}
