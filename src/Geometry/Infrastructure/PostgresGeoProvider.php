<?php

declare(strict_types=1);

namespace App\Geometry\Infrastructure;

use App\Geometry\Domain\BoundingBox;
use App\Geometry\Domain\Geometry;
use App\Geometry\Domain\GeoProvider;
use App\Geometry\Domain\Measurement;
use App\Geometry\Domain\SpatialRelation;
use App\Shared\Database\Row;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * The phase 1 spatial backend: core PostgreSQL, no PostGIS (§19).
 *
 * The roadmap's constraint was that a spatial backend can arrive later
 * without the domain knowing, and the reason it is a constraint rather than a
 * preference is in §19's own last line — PostGIS is an extension, and the
 * deployment target has not confirmed it. So this adapter uses only the
 * geometric types core PostgreSQL has always shipped.
 *
 * That is a smaller toolbox than it looks, and its edges were mapped against
 * a live server rather than assumed:
 *
 * - `&&`, `@>` and `<@` on polygons are **genuinely geometric** in
 *   PostgreSQL 16, not the bounding-box approximations older documentation
 *   suggests. An L-shaped parcel and a block sitting in its notch have
 *   overlapping bounding boxes, and `&&` correctly answers false.
 * - `<->` is the true minimum distance between two polygons, not the
 *   distance between their boxes.
 * - `area()` takes a `path`, not a `polygon`; `polygon::path` closes the ring
 *   for both area and `@-@` perimeter.
 * - `box(polygon)` gives the extent, and its corners come out as
 *   `(bb[1])[0]` — upper-right is subscript 0, lower-left is 1.
 *
 * What it cannot do is buffer, so {@see GeoProvider} does not offer one.
 *
 * **Nothing is interpolated into SQL.** Geometry literals are built from
 * floats this platform has already validated as finite, bound as parameters,
 * and cast inside the statement. A caller cannot reach the cast with anything
 * but a number, because a `Geometry` cannot be constructed from anything else.
 */
final class PostgresGeoProvider implements GeoProvider
{
    /**
     * `ordinality` keeps the answer in the order the caller listed their
     * candidates. Without it the set is unordered and a client matching
     * results to inputs by position would be reading a coincidence.
     */
    private const POLYGON_RELATIONS = <<<'SQL'
        WITH s AS (SELECT CAST(:subject AS polygon) AS g),
             c AS (SELECT e.ordinality           AS n,
                          e.item->>'id'          AS id,
                          CAST(e.item->>'geometry' AS polygon) AS g
                     FROM jsonb_array_elements(CAST(:candidates AS jsonb))
                          WITH ORDINALITY AS e(item, ordinality))
        SELECT c.id           AS id,
               (s.g && c.g)   AS intersects,
               (s.g @> c.g)   AS contains,
               (s.g <@ c.g)   AS within,
               (s.g <-> c.g)  AS distance
          FROM s CROSS JOIN c
         ORDER BY c.n
        SQL;

    /**
     * A point subject. `&&` has no point/polygon form, and it does not need
     * one: a point intersects a polygon exactly when the polygon contains it,
     * and a point contains nothing.
     */
    private const POINT_RELATIONS = <<<'SQL'
        WITH s AS (SELECT CAST(:subject AS point) AS g),
             c AS (SELECT e.ordinality           AS n,
                          e.item->>'id'          AS id,
                          CAST(e.item->>'geometry' AS polygon) AS g
                     FROM jsonb_array_elements(CAST(:candidates AS jsonb))
                          WITH ORDINALITY AS e(item, ordinality))
        SELECT c.id           AS id,
               (c.g @> s.g)   AS intersects,
               false          AS contains,
               (c.g @> s.g)   AS within,
               (c.g <-> s.g)  AS distance
          FROM s CROSS JOIN c
         ORDER BY c.n
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function measure(Geometry $polygon): Measurement
    {
        if (!$polygon->isPolygon()) {
            // Unreachable through the service, which parses for POLYGON. A
            // point has no area and this is the one place that could pretend
            // otherwise, so it refuses rather than returning zeroes.
            throw new RuntimeException('Only a polygon can be measured.');
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH s AS (SELECT CAST(:geometry AS polygon) AS g),
                     b AS (SELECT g, box(g) AS bb FROM s)
                SELECT npoints(g)      AS vertices,
                       area(g::path)   AS area,
                       @-@ (g::path)   AS perimeter,
                       (bb[1])[0]      AS min_x,
                       (bb[1])[1]      AS min_y,
                       (bb[0])[0]      AS max_x,
                       (bb[0])[1]      AS max_y
                  FROM b
                SQL,
            ['geometry' => self::literal($polygon)],
        );

        if ($row === false) {
            // Unreachable: the CTE has exactly one row by construction.
            throw new RuntimeException('The spatial backend measured nothing.');
        }

        return new Measurement(
            Row::integer($row, 'vertices'),
            // PostgreSQL already returns this unsigned — a ring and its
            // reverse measure the same 12 — so `abs` changes no answer today
            // and keeps a negative one from ever reaching a caller if a
            // future backend reports the signed shoelace sum instead.
            abs(Row::float($row, 'area')),
            Row::float($row, 'perimeter'),
            new BoundingBox(
                Row::float($row, 'min_x'),
                Row::float($row, 'min_y'),
                Row::float($row, 'max_x'),
                Row::float($row, 'max_y'),
            ),
        );
    }

    public function relate(Geometry $subject, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        // The candidates travel as one JSONB parameter and are unnested in
        // SQL. One round trip for the whole set, one bound value, and no
        // array-literal building — the alternative, a statement whose
        // placeholder count depends on the request, is the shape that ends up
        // being assembled by string concatenation.
        $rows = $this->connection->fetchAllAssociative(
            $subject->isPolygon() ? self::POLYGON_RELATIONS : self::POINT_RELATIONS,
            [
                'subject' => self::literal($subject),
                'candidates' => json_encode(self::listed($candidates), JSON_THROW_ON_ERROR),
            ],
        );

        $relations = [];

        foreach ($rows as $row) {
            $relations[] = new SpatialRelation(
                Row::string($row, 'id'),
                Row::boolean($row, 'intersects'),
                Row::boolean($row, 'contains'),
                Row::boolean($row, 'within'),
                Row::float($row, 'distance'),
            );
        }

        return $relations;
    }

    /**
     * The PostgreSQL text form: `(x,y)` for a point, `((x,y),...)` for a
     * polygon, whose literal syntax omits the closing repeat that GeoJSON
     * requires — which is why {@see Geometry} drops it.
     */
    private static function literal(Geometry $geometry): string
    {
        $positions = array_map(self::position(...), $geometry->positions);

        // A point has exactly one position, so imploding it yields `(x,y)`
        // — the same string an offset would, without asking the analyser to
        // believe a list is non-empty.
        $text = implode(',', $positions);

        return $geometry->isPolygon() ? '(' . $text . ')' : $text;
    }

    /**
     * `json_encode` rather than a cast: it is the one float-to-string in PHP
     * that neither loses precision to the `precision` ini setting nor follows
     * a locale into writing "0,5" — which PostgreSQL would read as two
     * ordinates.
     *
     * @param array{float, float} $position
     */
    private static function position(array $position): string
    {
        return sprintf(
            '(%s,%s)',
            json_encode($position[0], JSON_THROW_ON_ERROR),
            json_encode($position[1], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The candidates as the rows the statement unnests, in the caller's order.
     *
     * @param array<string, Geometry> $candidates
     *
     * @return list<array{id: string, geometry: string}>
     */
    private static function listed(array $candidates): array
    {
        $listed = [];

        foreach ($candidates as $id => $geometry) {
            $listed[] = ['id' => $id, 'geometry' => self::literal($geometry)];
        }

        return $listed;
    }
}
