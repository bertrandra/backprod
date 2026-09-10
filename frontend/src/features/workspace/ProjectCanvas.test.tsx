import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { ProjectCanvas } from './ProjectCanvas';

/**
 * U4's architectural exit criterion: **no business calculation in a component.**
 *
 * The test that matters is not "does an area appear" — a shoelace formula in
 * JavaScript would satisfy that. It is that the number on screen is the *Core's*
 * number: the stub answers 12, a JavaScript implementation of the same ring
 * would answer something else, and the screen shows 12 or fails.
 *
 * The second half is the refusal. `measure` rejects GEOGRAPHIC with 422 because
 * a degree is not a length, so the control is not offered there at all — a
 * button that always failed would be worse than the sentence explaining why.
 */
const WITH_GIS = { ...SESSION, capabilities: [...SESSION.capabilities, 'gis.access'] };
const WITHOUT_GIS = { ...SESSION, capabilities: [] };

/** Deliberately not the area of the ring drawn below. It is what the Core said. */
const MEASUREMENT = {
  vertices: 4,
  area: 4242,
  perimeter: 1337,
  bounding_box: { min_x: 0, min_y: 0, max_x: 10, max_y: 10 },
};

function clientFor(
  session: Record<string, unknown>,
  extra: Record<string, Stub | (() => Stub)> = {},
) {
  return stubClient({
    'GET /api/v1/me': { data: session },
    'POST /api/v1/geometry/measure': { data: { measurement: MEASUREMENT } },
    ...extra,
  });
}

/**
 * Draws a triangle by tapping the surface.
 *
 * jsdom gives every element a zero-size bounding box, so the coordinates all
 * land on 0 — which is fine here: what is asserted is that the ring reaches the
 * API and the API's answer reaches the screen, not where the taps were.
 */
function drawTriangle() {
  const surface = screen.getByTestId('canvas-surface');

  fireEvent.pointerDown(surface, { clientX: 10, clientY: 10 });
  fireEvent.pointerDown(surface, { clientX: 40, clientY: 10 });
  fireEvent.pointerDown(surface, { clientX: 40, clientY: 40 });
  fireEvent.click(screen.getByRole('button', { name: /finish shape/i }));
}

describe('measurement', () => {
  it('shows the numbers the Core returned, not numbers worked out here', async () => {
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITH_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());
    drawTriangle();

    fireEvent.click(screen.getByRole('button', { name: /^measure$/i }));

    await waitFor(() => expect(screen.getByTestId('area').textContent).toBe('4242'));
    // A JavaScript shoelace over the drawn ring could not produce these, which
    // is the point: they came from the API or they did not appear.
    expect(screen.getByTestId('perimeter').textContent).toBe('1337');
    expect(screen.getByTestId('vertices').textContent).toBe('4');
  });

  it('shows nothing at all before the Core has answered', async () => {
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITH_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());
    drawTriangle();

    // A drawn shape with no measurement is the honest state. A component that
    // could compute one would have shown it here, without being asked.
    expect(screen.queryByTestId('measurement')).toBeNull();
  });

  it('is not offered in degrees, and says why', async () => {
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITH_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());
    drawTriangle();

    fireEvent.click(screen.getByLabelText(/geographic/i));

    expect(screen.getByRole<HTMLButtonElement>('button', { name: /^measure$/i }).disabled).toBe(
      true,
    );
    expect(screen.getByText(/needs projected coordinates/i)).toBeTruthy();
    // Topology is still answerable in degrees, so this one stays available.
    expect(screen.getByRole('button', { name: /intersect/i })).toBeTruthy();
  });

  it('forgets the answer when the coordinate reference changes', async () => {
    // The previous answer was about a different question. Leaving 4242 on screen
    // under a new reference would be the same defect as inferring the reference.
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITH_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());
    drawTriangle();
    fireEvent.click(screen.getByRole('button', { name: /^measure$/i }));
    await waitFor(() => expect(screen.getByTestId('measurement')).toBeTruthy());

    fireEvent.click(screen.getByLabelText(/geographic/i));

    expect(screen.queryByTestId('measurement')).toBeNull();
  });
});

describe('intersection', () => {
  it('asks about the selected shape against the others, and shows four answers', async () => {
    let asked = 0;

    renderWith(
      <ProjectCanvas projectName="North wall" />,
      clientFor(WITH_GIS, {
        'POST /api/v1/geometry/intersections': (): Stub => {
          asked += 1;

          return {
            data: {
              relations: [
                { id: 'shape-1', intersects: true, contains: false, within: true, distance: null },
              ],
            },
          };
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());
    drawTriangle();
    drawTriangle();

    // Nothing is asked until somebody asks: two shapes on screen produce no
    // request of their own, which is the difference between a canvas that
    // triggers and one that computes as you draw.
    expect(asked).toBe(0);

    fireEvent.click(screen.getByRole('button', { name: /intersect/i }));

    await waitFor(() => expect(asked).toBe(1));
    await waitFor(() => expect(screen.getByTestId('relations')).toBeTruthy());
    // "intersects" and "inside it" are different facts about the same pair, and
    // both are shown rather than collapsed into one word.
    expect(screen.getByText(/intersects/)).toBeTruthy();
    expect(screen.getByText(/inside it/)).toBeTruthy();
  });

  it('needs something to compare against', async () => {
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITH_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());
    drawTriangle();

    expect(
      screen.getByRole<HTMLButtonElement>('button', { name: /intersect/i }).disabled,
    ).toBe(true);
  });
});

describe('without the GIS capability', () => {
  it('offers no geometry, and says an upgrade adds it', async () => {
    // A capability, not a permission: geometry touches no tenant data, so the
    // refusal is answered by an upgrade rather than by an administrator.
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITHOUT_GIS));

    await waitFor(() => expect(screen.getByText(/needs GIS/i)).toBeTruthy());
    expect(screen.getByText(/upgrade/i)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^measure$/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /intersect/i })).toBeNull();
  });

  it('still lets the shape be drawn', async () => {
    // Drawing is not geometry. Taking the surface away too would make the plan
    // decide whether a canvas exists, which is not what the capability names.
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITHOUT_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());
    drawTriangle();

    expect(document.querySelectorAll('[data-shape]')).toHaveLength(1);
  });
});

describe('drawing', () => {
  it('will not close a ring with fewer than three corners', async () => {
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITH_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());

    const surface = screen.getByTestId('canvas-surface');
    fireEvent.pointerDown(surface, { clientX: 10, clientY: 10 });
    fireEvent.pointerDown(surface, { clientX: 40, clientY: 10 });

    // Two points are a line. The API refuses it, so the button does too.
    expect(
      screen.getByRole<HTMLButtonElement>('button', { name: /finish shape/i }).disabled,
    ).toBe(true);
  });

  it('undoes a corner without clearing the shape', async () => {
    renderWith(<ProjectCanvas projectName="North wall" />, clientFor(WITH_GIS));

    await waitFor(() => expect(screen.getByTestId('canvas-surface')).toBeTruthy());

    const surface = screen.getByTestId('canvas-surface');
    fireEvent.pointerDown(surface, { clientX: 10, clientY: 10 });
    fireEvent.pointerDown(surface, { clientX: 40, clientY: 10 });
    fireEvent.pointerDown(surface, { clientX: 40, clientY: 40 });

    fireEvent.click(screen.getByRole('button', { name: /undo point/i }));

    expect(
      screen.getByRole<HTMLButtonElement>('button', { name: /finish shape/i }).disabled,
    ).toBe(true);
    expect(screen.getByTestId('drawing')).toBeTruthy();
  });
});
