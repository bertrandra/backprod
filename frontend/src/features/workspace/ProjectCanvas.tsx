import { useRef, useState } from 'react';

import { isEntitled } from '@/app/access/access';
import {
  GIS_CAPABILITY,
  isMeasurable,
  polygonOf,
  useIntersectGeometries,
  useMeasureGeometry,
  type CoordinateReference,
  type Measurement,
  type SpatialRelation,
} from '@/queries/geometry';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';

/**
 * The canvas, and the milestone's architectural test.
 *
 * **Nothing here computes anything about a shape.** Area, perimeter, vertex
 * count and every spatial relation come back from `/geometry/*`; this file
 * collects points, draws them, and renders the answer. The shoelace formula is
 * four lines of JavaScript and that is exactly why it must not be written here:
 * it would be added for a live tooltip, disagree with PostgreSQL in the
 * eleventh digit, and leave two numbers for one parcel with nothing to say
 * which is right (§4, §5).
 *
 * What *is* computed is the mapping from a pointer position to a coordinate,
 * which is presentation: where a finger landed on a box of pixels. It answers
 * nothing about the parcel.
 *
 * **The shapes are scratch, deliberately.** They are not written into the
 * project document, because the document's shape belongs to the Core and this
 * component inventing keys for it would be the frontend deciding what a project
 * *is*. When the Core defines that shape, saving becomes a call rather than a
 * guess.
 *
 * Mobile is the design case, not an adaptation: the drawing surface is
 * full-bleed and every control lives in a sheet along the bottom edge, within
 * reach of one thumb at 375 px.
 */

interface Shape {
  readonly id: string;
  readonly points: readonly (readonly [number, number])[];
}

/** The drawing surface's own units. Coordinates are reported in these. */
const EXTENT = 100;

const MIN_RING = 3;

export function ProjectCanvas({ projectName }: { projectName: string }) {
  const { data: session } = useSession();
  const allowed = isEntitled(session, GIS_CAPABILITY);

  const [crs, setCrs] = useState<CoordinateReference>('PROJECTED');
  const [shapes, setShapes] = useState<readonly Shape[]>([]);
  const [drawing, setDrawing] = useState<readonly (readonly [number, number])[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [measurement, setMeasurement] = useState<Measurement | null>(null);
  const [relations, setRelations] = useState<readonly SpatialRelation[] | null>(null);

  const measure = useMeasureGeometry();
  const intersect = useIntersectGeometries();

  // The surface is read through a ref rather than the event's `currentTarget`:
  // a tap can land on a child polygon, and the box being measured against must
  // be the surface either way.
  const surface = useRef<SVGSVGElement>(null);

  const selectedShape = shapes.find((shape) => shape.id === selected) ?? null;
  const others = shapes.filter((shape) => shape.id !== selected);

  /**
   * Pointer position to coordinate.
   *
   * Presentation, not geometry: the SVG is a box of pixels with a known extent,
   * and this says where in that box the pointer was. Rounded to whole units so
   * a ring drawn by hand does not carry sixteen digits of finger tremor into a
   * request.
   */
  const coordinateOf = (event: React.PointerEvent<SVGSVGElement>): [number, number] => {
    const box = surface.current?.getBoundingClientRect();

    if (box === undefined || box.width === 0 || box.height === 0) {
      // No box to map against — before layout, or in a test environment that
      // gives every element a zero size. The origin is a defensible answer; a
      // division by zero would put NaN into a request.
      return [0, 0];
    }

    const x = ((event.clientX - box.left) / box.width) * EXTENT;
    // Screen y grows downward and a coordinate grows upward, so it is flipped
    // here rather than leaving every shape mirrored.
    const y = EXTENT - ((event.clientY - box.top) / box.height) * EXTENT;

    return [Math.round(x), Math.round(y)];
  };

  const addPoint = (event: React.PointerEvent<SVGSVGElement>) => {
    setDrawing((points) => [...points, coordinateOf(event)]);
  };

  const finish = () => {
    if (drawing.length < MIN_RING) {
      return;
    }

    const shape: Shape = { id: `shape-${String(shapes.length + 1)}`, points: drawing };

    setShapes((all) => [...all, shape]);
    setSelected(shape.id);
    setDrawing([]);
    setMeasurement(null);
    setRelations(null);
  };

  const clear = () => {
    setShapes([]);
    setDrawing([]);
    setSelected(null);
    setMeasurement(null);
    setRelations(null);
  };

  const askToMeasure = () => {
    if (selectedShape === null) {
      return;
    }

    measure.mutate(
      { crs, geometry: polygonOf(selectedShape.points) },
      { onSuccess: setMeasurement },
    );
  };

  const askToIntersect = () => {
    if (selectedShape === null || others.length === 0) {
      return;
    }

    intersect.mutate(
      {
        crs,
        subject: polygonOf(selectedShape.points),
        candidates: others.map((shape) => ({ id: shape.id, geometry: polygonOf(shape.points) })),
      },
      { onSuccess: setRelations },
    );
  };

  return (
    <section className="space-y-3" data-testid="project-canvas">
      <h2 className="text-base font-semibold">Canvas</h2>

      {/* Full-bleed on a phone: the surface is square and takes the width it is
          given, and the controls sit under it rather than beside it. */}
      <svg
        ref={surface}
        data-testid="canvas-surface"
        viewBox={`0 0 ${String(EXTENT)} ${String(EXTENT)}`}
        role="application"
        aria-label={`Drawing surface for ${projectName}`}
        onPointerDown={addPoint}
        className="aspect-square w-full max-w-full touch-none rounded border border-neutral-300 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900"
      >
        {shapes.map((shape) => (
          <polygon
            key={shape.id}
            data-shape={shape.id}
            points={shape.points.map(([x, y]) => `${String(x)},${String(EXTENT - y)}`).join(' ')}
            className={
              shape.id === selected
                ? 'fill-neutral-900/20 stroke-neutral-900 dark:fill-neutral-100/20 dark:stroke-neutral-100'
                : 'fill-neutral-500/10 stroke-neutral-500'
            }
            strokeWidth={0.6}
            onPointerDown={(event) => {
              // Selecting a shape must not also drop a point into the drawing.
              event.stopPropagation();
              setSelected(shape.id);
              setMeasurement(null);
              setRelations(null);
            }}
          />
        ))}

        {drawing.length > 0 && (
          <polyline
            data-testid="drawing"
            points={drawing.map(([x, y]) => `${String(x)},${String(EXTENT - y)}`).join(' ')}
            className="fill-none stroke-neutral-900 dark:stroke-neutral-100"
            strokeWidth={0.6}
            strokeDasharray="2 1"
          />
        )}

        {drawing.map(([x, y], index) => (
          <circle
            key={`${String(x)}-${String(y)}-${String(index)}`}
            cx={x}
            cy={EXTENT - y}
            r={1.2}
            className="fill-neutral-900 dark:fill-neutral-100"
          />
        ))}
      </svg>

      {/* The tool sheet. Sticky at the bottom on a phone so it stays under the
          thumb while the surface scrolls; inline from `sm` upward. */}
      <div
        data-testid="tool-sheet"
        className="sticky bottom-0 z-10 space-y-3 rounded border border-neutral-200 bg-white/95 p-3 backdrop-blur sm:static sm:bg-transparent sm:backdrop-blur-none dark:border-neutral-800 dark:bg-neutral-950/95 sm:dark:bg-transparent"
      >
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="secondary" onClick={finish} disabled={drawing.length < MIN_RING}>
            Finish shape
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => setDrawing((points) => points.slice(0, -1))}
            disabled={drawing.length === 0}
          >
            Undo point
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={clear}
            disabled={shapes.length === 0 && drawing.length === 0}
          >
            Clear
          </Button>
        </div>

        <p className="text-xs text-neutral-600 dark:text-neutral-400">
          Tap the surface to place a corner, then finish the shape. Shapes are a scratch pad — they
          are not saved into the project.
        </p>

        {!allowed ? (
          // A capability, not a permission: geometry touches no tenant data, so
          // the refusal is answered by an upgrade rather than by an
          // administrator (ui-spec.md §3.4's two-refusal rule).
          <EmptyState
            title="Measurement needs GIS"
            description="Your plan does not include the GIS capability, so measurement and intersection are unavailable. An upgrade adds them."
          />
        ) : (
          <>
            <fieldset className="space-y-1">
              <legend className="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Coordinate reference
              </legend>
              {/* Stated, never inferred. The same ring is a valid answer in
                  both, and guessing from magnitude reports a 1200 m² plot as
                  0.00000015. */}
              <div className="flex flex-wrap gap-4">
                {(['PROJECTED', 'GEOGRAPHIC'] as const).map((option) => (
                  <label key={option} className="flex min-h-[44px] items-center gap-2 text-sm">
                    <input
                      type="radio"
                      name="crs"
                      className="size-5"
                      value={option}
                      checked={crs === option}
                      onChange={() => {
                        setCrs(option);
                        // The previous answer was about a different question.
                        setMeasurement(null);
                        setRelations(null);
                      }}
                    />
                    {option === 'PROJECTED' ? 'Projected (linear units)' : 'Geographic (degrees)'}
                  </label>
                ))}
              </div>
            </fieldset>

            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                pending={measure.isPending}
                onClick={askToMeasure}
                disabled={selectedShape === null || !isMeasurable(crs)}
              >
                Measure
              </Button>
              <Button
                type="button"
                variant="secondary"
                pending={intersect.isPending}
                onClick={askToIntersect}
                disabled={selectedShape === null || others.length === 0}
              >
                Intersect with the others
              </Button>
            </div>

            {!isMeasurable(crs) && (
              // Said before the attempt rather than after the 422: a degree of
              // longitude is a different length in Lille and in Marseille, so
              // there is no area to report. Topology still works, which is why
              // intersection stays available.
              <p className="text-sm text-neutral-600 dark:text-neutral-400">
                Area and perimeter are not defined in degrees, so measurement needs projected
                coordinates. Intersection still works here.
              </p>
            )}

            {measure.error !== null && <ErrorSurface error={measure.error} />}
            {intersect.error !== null && <ErrorSurface error={intersect.error} />}

            {measurement !== null && (
              <dl data-testid="measurement" className="grid grid-cols-3 gap-2 text-sm">
                {/* Rendered exactly as the Core reported them. There is no unit
                    label because there is no unit to report: the caller chose
                    the projection and knows whether it is metres or feet. */}
                <div>
                  <dt className="text-xs text-neutral-500">Corners</dt>
                  <dd data-testid="vertices">{measurement.vertices}</dd>
                </div>
                <div>
                  <dt className="text-xs text-neutral-500">Area</dt>
                  <dd data-testid="area">{measurement.area}</dd>
                </div>
                <div>
                  <dt className="text-xs text-neutral-500">Perimeter</dt>
                  <dd data-testid="perimeter">{measurement.perimeter}</dd>
                </div>
              </dl>
            )}

            {relations !== null && (
              <ul data-testid="relations" className="space-y-1 text-sm">
                {relations.map((relation) => (
                  <li key={relation.id} data-relation={relation.id}>
                    <span className="font-medium">{relation.id}</span>{' '}
                    {/* Four answers, not one: a footprint that clips a parcel,
                        one that swallows it, and one swallowed by it are
                        different facts about the same pair. */}
                    <span className="text-neutral-600 dark:text-neutral-400">
                      {relation.intersects ? 'intersects' : 'disjoint'}
                      {relation.contains && ' · contains it'}
                      {relation.within && ' · inside it'}
                      {relation.distance !== null && ` · distance ${String(relation.distance)}`}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </div>
    </section>
  );
}
