<?php

declare(strict_types=1);

namespace App\Geometry\Domain;

/**
 * How the subject stands to one candidate.
 *
 * Four answers rather than one, because the useful question is almost never
 * just "do these touch": a parcel search wants to separate the plots a
 * footprint merely clips from the ones it swallows whole, and the plot that
 * swallows the footprint is a different answer again.
 *
 * `distance` is nullable rather than absent, and it is null exactly when the
 * coordinates were geographic — see {@see CoordinateReference}. Zero would be
 * a lie and omitting the key would make every client write a branch.
 */
final class SpatialRelation
{
    public function __construct(
        public readonly string $id,
        public readonly bool $intersects,
        public readonly bool $contains,
        public readonly bool $within,
        public readonly ?float $distance,
    ) {
    }

    public function withoutDistance(): self
    {
        return new self($this->id, $this->intersects, $this->contains, $this->within, null);
    }
}
