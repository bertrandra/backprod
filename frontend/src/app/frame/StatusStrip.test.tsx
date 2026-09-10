import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { StatusStrip } from './StatusStrip';

/**
 * Region E, and U4's first exit criterion: **an export is requested, tracked
 * here, and downloadable when done, without the page being reloaded.**
 *
 * The stub moves the job from RUNNING to SUCCEEDED between polls, which is the
 * only way to tell a strip that watches from one that rendered once and stopped.
 */
const SESSION_WITH_JOBS = { ...SESSION, permissions: [...SESSION.permissions, 'jobs.read'] };

function job(overrides: Record<string, unknown> = {}) {
  return {
    id: 'j-1',
    type: 'export.project',
    status: 'RUNNING',
    payload: {},
    result: null,
    attempts: 1,
    max_attempts: 3,
    run_after: '2026-01-01T10:00:00Z',
    failure_reason: null,
    started_at: '2026-01-01T10:00:00Z',
    finished_at: null,
    created_at: '2026-01-01T10:00:00Z',
    ...overrides,
  };
}

const listing = (jobs: unknown[]): Stub => ({
  data: { jobs, total: jobs.length, limit: 25, offset: 0 },
});

describe('the status strip', () => {
  it('says nothing is running when nothing is', async () => {
    renderAtRoute(
      <StatusStrip />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_JOBS },
        'GET /api/v1/jobs': listing([]),
      }),
      // The strip links to the jobs area, so it needs a router to render at all.
      { path: '/jobs' },
    );

    await waitFor(() => expect(screen.getByTestId('status-strip-idle')).toBeTruthy());
  });

  it('counts what is unfinished', async () => {
    renderAtRoute(
      <StatusStrip />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_JOBS },
        'GET /api/v1/jobs': listing([job(), job({ id: 'j-2', status: 'QUEUED' })]),
      }),
      // The strip links to the jobs area, so it needs a router to render at all.
      { path: '/jobs' },
    );

    await waitFor(() => expect(screen.getByTestId('running-count').textContent).toBe('2 running'));
  });

  it('offers the download when an export finishes, with no reload', async () => {
    let polls = 0;

    renderAtRoute(
      <StatusStrip />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_JOBS },
        'GET /api/v1/jobs': (): Stub => {
          polls += 1;

          // Running at first, finished afterwards — the transition this strip
          // exists to notice.
          return polls === 1
            ? listing([job()])
            : listing([
                job({
                  status: 'SUCCEEDED',
                  result: { asset_id: 'a-1' },
                  finished_at: '2026-01-01T10:00:30Z',
                }),
              ]);
        },
      }),
      // The strip links to the jobs area, so it needs a router to render at all.
      { path: '/jobs' },
    );

    await waitFor(() => expect(screen.getByTestId('running-count')).toBeTruthy());

    // The polling in queries/jobs.ts brings this about; nothing here reloads.
    await waitFor(() => expect(screen.getByTestId('strip-download')).toBeTruthy(), {
      timeout: 5_000,
    });
    expect(screen.queryByTestId('running-count')).toBeNull();
  });

  it('ignores work that had already finished before the page opened', async () => {
    // History, not news. A strip that showed every past success would offer a
    // download for last Tuesday's export on every page load.
    //
    // The wait is on the *request*, not on the idle strip: the strip is idle
    // before the answer arrives too, so asserting it straight away would pass
    // whatever the answer turned out to be. This version fails when the filter
    // is removed; the first version did not.
    let asked = 0;

    renderAtRoute(
      <StatusStrip />,
      stubClient({
        'GET /api/v1/me': { data: SESSION_WITH_JOBS },
        'GET /api/v1/jobs': (): Stub => {
          asked += 1;

          return listing([
            job({
              status: 'SUCCEEDED',
              result: { asset_id: 'a-1' },
              finished_at: '2026-01-01T09:00:00Z',
            }),
          ]);
        },
      }),
      // The strip links to the jobs area, so it needs a router to render at all.
      { path: '/jobs' },
    );

    await waitFor(() => expect(asked).toBe(1));
    await new Promise((resolve) => setTimeout(resolve, 50));

    expect(screen.getByTestId('status-strip-idle')).toBeTruthy();
    expect(screen.queryByTestId('strip-download')).toBeNull();
  });

  it('asks nothing at all without jobs.read', async () => {
    let asked = 0;

    renderAtRoute(
      <StatusStrip />,
      stubClient({
        'GET /api/v1/me': { data: { ...SESSION, permissions: [] } },
        'GET /api/v1/jobs': (): Stub => {
          asked += 1;

          return listing([job()]);
        },
      }),
      // The strip links to the jobs area, so it needs a router to render at all.
      { path: '/jobs' },
    );

    await waitFor(() => expect(screen.getByTestId('status-strip-idle')).toBeTruthy());
    // Region E is in both shells and platform staff hold no tenant permissions,
    // so a strip that polled regardless would log a refusal every two seconds.
    expect(asked).toBe(0);
  });
});
