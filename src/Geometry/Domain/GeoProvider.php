<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

/**
 * Where spatial questions are actually answered (§19).
 *
 * A port for the same reason {@see \App\Storage\Domain\StorageProvider} is
 * one, and with a sharper deadline: §19 puts the geometry engine behind a
 * separate service in phase 2, and the whole point of phase 1 is that the day
 * that happens, nothing above this interface changes. So the domain gets to
 * ask about overlap, containment and distance, and never gets to know that
 * today the answer comes from a `polygon` column type in the same PostgreSQL
 * instance that holds the invoices.
 *
 * The two methods are what core PostgreSQL answers **correctly** — verified
 * operator by operator, including the concave cases where a bounding-box
 * shortcut would have been wrong. There is no `buffer()` here even though
 * §7 lists the endpoint, because core PostgreSQL has no buffer and offsetting
 * a ring by hand would produce a shape that looks right and is not. §19 names
 * buffers among the things a spatial extension is for; this is that boundary,
 * and it is drawn at the port rather than papered over beneath it.
 */
interface GeoProvider
{
    /**
     * Area, perimeter and extent of a polygon.
     */
    public function measure(Geometry $polygon): Measurement;

    /**
     * The subject against each candidate, in the order given.
     *
     * @param array<string, Geometry> $candidates polygons, keyed by the id the caller gave them
     *
     * @return list<SpatialRelation>
     */
    public function relate(Geometry $subject, array $candidates): array;
}
