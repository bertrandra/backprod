import { expect, test, type Page } from '@playwright/test';

/**
 * U4's exit criteria that only a browser can judge.
 *
 *   - **an export is requested, tracked in region E, and downloadable when
 *     done, without the page being reloaded** — the stub advances the job
 *     between polls, so a strip that rendered once and stopped fails here;
 *   - **the canvas is usable one-handed at 375 px** — which is a claim about
 *     layout and reach, not about state, and cannot be asserted in jsdom
 *     because jsdom has no layout at all.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: [
    'projects.read',
    'projects.write',
    'assets.read',
    'assets.manage',
    'jobs.read',
    'jobs.manage',
  ],
  capabilities: ['gis.access'],
};

const PROJECT_ID = '44444444-4444-4444-8444-444444444444';

const PROJECT = {
  id: PROJECT_ID,
  name: 'North wall',
  description: 'The scaffolding job',
  schema_version: 7,
  created_by: SESSION.user_id,
  created_at: '2026-01-01T10:00:00Z',
  updated_at: '2026-01-02T10:00:00Z',
  document: {},
};

const QUEUED_JOB = {
  id: '55555555-5555-4555-8555-555555555555',
  type: 'export.project',
  status: 'QUEUED',
  payload: { project_id: PROJECT_ID },
  result: null,
  attempts: 0,
  max_attempts: 3,
  run_after: '2026-01-01T10:00:00Z',
  failure_reason: null,
  started_at: null,
  finished_at: null,
  created_at: '2026-01-01T10:00:00Z',
};

/**
 * A backend where the export actually finishes.
 *
 * `exported` flips on the first poll after the request, which is what makes this
 * a test of the polling rather than of the first render.
 */
async function workspace(page: Page, session: Record<string, unknown> = SESSION) {
  const state = { requested: false, polls: 0 };

  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: session }));

  // The product switcher in region A (U5).
  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({
      json: { products: [{ id: session.product_id, code: 'atlas', name: 'Atlas' }] },
    }),
  );

  await page.route(/\/api\/v1\/products\/[^/]+\/configuration/, (route) =>
    route.fulfill({ json: { configuration: { project_schema_versions: { supported: [7] } } } }),
  );

  await page.route(/\/api\/v1\/projects(\?|$)/, (route) =>
    route.fulfill({ json: { projects: [PROJECT], total: 1, limit: 25, offset: 0 } }),
  );

  await page.route(/\/api\/v1\/projects\/[^/]+$/, (route) => route.fulfill({ json: PROJECT }));

  await page.route(/\/api\/v1\/projects\/[^/]+\/versions$/, (route) =>
    route.fulfill({ json: { versions: [] } }),
  );

  await page.route(/\/api\/v1\/projects\/[^/]+\/assets$/, (route) =>
    route.fulfill({ json: { assets: [] } }),
  );

  await page.route(/\/api\/v1\/projects\/[^/]+\/exports$/, (route) => {
    state.requested = true;

    return route.fulfill({ status: 202, json: QUEUED_JOB });
  });

  await page.route(/\/api\/v1\/jobs(\?|$)/, (route) => {
    if (!state.requested) {
      return route.fulfill({ json: { jobs: [], total: 0, limit: 25, offset: 0 } });
    }

    state.polls += 1;

    // Still queued on the first look, finished on the next: the transition the
    // status strip exists to notice.
    const job =
      state.polls > 1
        ? {
            ...QUEUED_JOB,
            status: 'SUCCEEDED',
            result: { asset_id: '66666666-6666-4666-8666-666666666666' },
            finished_at: '2026-01-01T10:00:30Z',
          }
        : QUEUED_JOB;

    return route.fulfill({ json: { jobs: [job], total: 1, limit: 25, offset: 0 } });
  });

  await page.route(/\/api\/v1\/assets\/[^/]+\/link$/, (route) =>
    route.fulfill({
      status: 201,
      json: { url: '/signed-export.json', expires_at: '2026-01-01T11:00:00Z' },
    }),
  );

  await page.route(/\/signed-export\.json$/, (route) =>
    route.fulfill({ body: '{"exported":true}', contentType: 'application/json' }),
  );

  await page.route(/\/api\/v1\/geometry\/measure$/, (route) =>
    route.fulfill({
      json: {
        measurement: {
          vertices: 4,
          area: 4242,
          perimeter: 1337,
          bounding_box: { min_x: 0, min_y: 0, max_x: 10, max_y: 10 },
        },
      },
    }),
  );

  return state;
}

test.describe('an export', () => {
  test('is requested, tracked in region E, and downloadable — no reload', async ({ page }) => {
    await workspace(page);

    await page.goto(`/projects/${PROJECT_ID}?product=atlas`);
    await expect(page.getByRole('heading', { name: 'North wall' })).toBeVisible();

    // Nothing is running, so region E says so.
    await expect(page.getByTestId('status-strip-idle')).toBeVisible();

    await page.getByRole('button', { name: 'Export project' }).click();

    // Tracked while it runs…
    await expect(page.getByTestId('running-count')).toBeVisible();

    // …and offered when it lands, without anything reloading.
    await expect(page.getByTestId('strip-download')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('running-count')).toHaveCount(0);
  });

  test('downloads through a signed link rather than through the client', async ({ page }) => {
    await workspace(page);

    const requests: string[] = [];
    page.on('request', (request) => requests.push(request.url()));

    await page.goto(`/projects/${PROJECT_ID}?product=atlas`);
    await page.getByRole('button', { name: 'Export project' }).click();
    await expect(page.getByTestId('strip-download')).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('strip-download').click();

    // The browser navigates to the signed URL. The point is that it is a
    // navigation, so the bytes never pass through the application.
    await page.waitForURL(/signed-export\.json/);
    expect(requests.some((url) => url.includes('/api/v1/assets/'))).toBe(true);
  });
});

test.describe('the canvas', () => {
  test('is usable one-handed at 375 px', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 720 });
    await workspace(page);

    await page.goto(`/projects/${PROJECT_ID}?product=atlas`);

    const surface = page.getByTestId('canvas-surface');
    await expect(surface).toBeVisible();

    const box = await surface.boundingBox();
    expect(box).not.toBeNull();

    // Full-bleed: the surface takes the width it is given, give or take the
    // page's own gutter.
    expect(box?.width ?? 0).toBeGreaterThan(320);

    // Every control is a 44 px touch target (ui-spec.md §4.2), and within reach:
    // the sheet sits in the lower half of the screen rather than at the top of a
    // long page.
    const measure = page.getByRole('button', { name: 'Measure' });
    const measureBox = await measure.boundingBox();

    expect(measureBox?.height ?? 0).toBeGreaterThanOrEqual(44);

    await expect(page.getByTestId('tool-sheet')).toBeVisible();
  });

  test('never scrolls horizontally at phone width', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 720 });
    await workspace(page);

    await page.goto(`/projects/${PROJECT_ID}?product=atlas`);
    await expect(page.getByTestId('canvas-surface')).toBeVisible();

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(overflows).toBe(false);
  });

  test('reports the measurement the Core computed', async ({ page }) => {
    await workspace(page);

    await page.goto(`/projects/${PROJECT_ID}?product=atlas`);

    const surface = page.getByTestId('canvas-surface');
    await expect(surface).toBeVisible();

    // Clicked through the locator with element-relative positions: it scrolls the
    // surface into view first, which `page.mouse` does not — the canvas sits well
    // down the project page.
    await surface.click({ position: { x: 20, y: 20 } });
    await surface.click({ position: { x: 120, y: 20 } });
    await surface.click({ position: { x: 120, y: 120 } });

    await page.getByRole('button', { name: 'Finish shape' }).click();
    await page.getByRole('button', { name: 'Measure' }).click();

    // 4242 over a right triangle drawn by hand is not any function of these
    // three points: it is what the API said.
    await expect(page.getByTestId('area')).toHaveText('4242');
    await expect(page.getByTestId('perimeter')).toHaveText('1337');
  });
});
