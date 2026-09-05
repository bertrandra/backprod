<?php

declare(strict_types=1);

namespace App\Geometry\Controller;

use App\Geometry\Domain\Measurement;
use App\Geometry\Domain\SpatialRelation;

final class GeometryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function measurement(Measurement $measurement): array
    {
        return [
            'vertices' => $measurement->vertices,
            'area' => $measurement->area,
            'perimeter' => $measurement->perimeter,
            'bounding_box' => [
                'min_x' => $measurement->boundingBox->minX,
                'min_y' => $measurement->boundingBox->minY,
                'max_x' => $measurement->boundingBox->maxX,
                'max_y' => $measurement->boundingBox->maxY,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function relation(SpatialRelation $relation): array
    {
        return [
            'id' => $relation->id,
            'intersects' => $relation->intersects,
            'contains' => $relation->contains,
            'within' => $relation->within,
            // Present and null in geographic coordinates rather than absent,
            // so a client reads one shape whichever reference it sent.
            'distance' => $relation->distance,
        ];
    }
}
