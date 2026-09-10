import { useMutation } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { toApiError } from './session';

/**
 * Geometry: the clearest case in the application of §4's rule.
 *
 * **The canvas triggers, the Core computes, this file only transports.** There
 * is no `area()` in the frontend and there must never be one. The shoelace
 * formula is four lines of JavaScript, which is exactly what makes it dangerous:
 * it would be written once for a tooltip, disagree with the backend in the
 * eleventh digit, and then two numbers for the same parcel would exist with
 * nothing to say which is right.
 *
 * Two consequences the screens have to respect, both from the contract:
 *
 *   - **the coordinate reference is stated, never inferred.** A parcel in
 *     Lambert-93 and the same parcel in WGS 84 are both pairs of finite numbers,
 *     and guessing from magnitude reports a 1200 m² plot as 0.00000015;
 *   - **`measure` refuses `GEOGRAPHIC` with 422**, because a degree is not a
 *     length. Topology still works there, so `intersections` accepts it and
 *     withholds `distance`.
 *
 * Gated by a **capability**, not a permission (`GeoRoute::CAPABILITY`): geometry
 * touches no tenant data, so there is no question of what a role may do with
 * it — only whether the plan includes GIS. So screens ask `isEntitled`, and the
 * refusal is answered by an upgrade rather than by an administrator.
 */

export type Measurement = Schemas['Measurement'];
export type SpatialRelation = Schemas['SpatialRelation'];
export type CoordinateReference = Schemas['CoordinateReference'];
export type GeoJsonPolygon = Schemas['GeoJsonPolygon'];
export type GeoJsonPoint = Schemas['GeoJsonPoint'];

/** The capability that decides whether geometry is offered at all. */
export const GIS_CAPABILITY = 'gis.access';

/**
 * Which references can be measured.
 *
 * `GEOGRAPHIC` is absent because the API refuses it, and a control that always
 * failed would be worse than none — the screen says why instead.
 */
export const MEASURABLE: readonly CoordinateReference[] = ['PROJECTED'];

export function isMeasurable(crs: CoordinateReference): boolean {
  return MEASURABLE.includes(crs);
}

/**
 * A ring, closed.
 *
 * GeoJSON requires the last position to repeat the first, and the API refuses a
 * ring that does not. Closing it here is shaping a payload to the contract's
 * format, not computing anything about the shape — the distinction that matters
 * is that nothing in this file derives a *quantity*. Lengths, areas and topology
 * come back from the Core.
 */
export function closedRing(points: readonly (readonly [number, number])[]): number[][] {
  const ring = points.map(([x, y]) => [x, y]);
  const first = ring[0];
  const last = ring[ring.length - 1];

  if (first === undefined || last === undefined) {
    return ring;
  }

  const alreadyClosed = first[0] === last[0] && first[1] === last[1];

  return alreadyClosed ? ring : [...ring, [first[0] as number, first[1] as number]];
}

export function polygonOf(points: readonly (readonly [number, number])[]): GeoJsonPolygon {
  return { type: 'Polygon', coordinates: [closedRing(points)] };
}

export function useMeasureGeometry() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (input: {
      crs: CoordinateReference;
      geometry: GeoJsonPolygon;
    }): Promise<Measurement> => {
      const { data, error, response } = await client.POST('/api/v1/geometry/measure', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.measurement;
    },
  });
}

export function useIntersectGeometries() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (input: {
      crs: CoordinateReference;
      // The subject may be a point — "which parcels is this pin in" — but a
      // candidate is always a polygon. The contract says so and the generated
      // types enforced it; a candidate point would make `contains` meaningless.
      subject: GeoJsonPolygon | GeoJsonPoint;
      candidates: { id: string; geometry: GeoJsonPolygon }[];
    }): Promise<readonly SpatialRelation[]> => {
      const { data, error, response } = await client.POST('/api/v1/geometry/intersections', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.relations;
    },
  });
}
