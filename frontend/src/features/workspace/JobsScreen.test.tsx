import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { JobsScreen } from './JobsScreen';

/**
 * `workspace.jobs`, and what the roadmap means by *"a lapse is a fact about the
 * clock, never about whether something ran"*.
 *
 * A queued job that has already failed twice and will not be tried again for
 * four minutes is a different situation from one queued a second ago, and only
 * one of them needs somebody to look at it. So attempts, `run_after` and the
 * failure reason are all on screen — a job that failed for a reason nobody can
 * read is a job nobody can fix.
 */
const SESSION_WITH_JOBS = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'jobs.read', 'jobs.manage'],
};

function job(overrides: Record<string, unknown> = {}) {
  return {
    id: 'j-1',
    type: 'export.project',
    status: 'QUEUED',
    payload: {},
    result: null,
    attempts: 2,
    max_attempts: 3,
    run_after: '2026-01-01T10:04:00Z',
    failure_reason: null,
    started_at: null,
    finished_at: null,
    created_at: '2026-01-01T10:00:00Z',
    ...overrides,
  };
}

const listing = (jobs: unknown[]): Stub => ({
  data: { jobs, total: jobs.length, limit: 25, offset: 0 },
});

function clientFor(jobs: unknown[], extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SESSION_WITH_JOBS },
    'GET /api/v1/jobs': listing(jobs),
    ...extra,
  });
}

describe('a job', () => {
  it('shows its attempts and when it will next run', async () => {
    renderWith(<JobsScreen />, clientFor([job()]));

    await waitFor(() => expect(screen.getByText(/attempt 2 of 3/)).toBeTruthy());
    expect(screen.getByText(/not before/)).toBeTruthy();
  });

  it('shows why it failed', async () => {
    renderWith(
      <JobsScreen />,
      clientFor([job({ status: 'FAILED', failure_reason: 'This export job does not name a project.' })]),
    );

    await waitFor(() =>
      expect(screen.getByTestId('failure-reason').textContent).toContain('does not name a project'),
    );
    // Finished, so there is nothing to cancel and no waiting to report.
    expect(screen.queryByRole('button', { name: /cancel/i })).toBeNull();
    expect(screen.queryByText(/not before/)).toBeNull();
  });

  it('can be cancelled only while it is unfinished', async () => {
    let cancelled = 0;

    renderWith(
      <JobsScreen />,
      clientFor([job()], {
        'POST /api/v1/jobs/{jobId}/cancel': (): Stub => {
          cancelled += 1;

          return { data: job({ status: 'CANCELLED' }) };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /cancel/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }));

    await waitFor(() => expect(cancelled).toBe(1));
  });

  it('offers the result of a finished export', async () => {
    renderWith(
      <JobsScreen />,
      clientFor([
        job({ status: 'SUCCEEDED', result: { asset_id: 'a-1' }, finished_at: '2026-01-01T10:05:00Z' }),
      ]),
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /download the result/i })).toBeTruthy(),
    );
  });

  it('offers no download when a succeeded job produced no asset', async () => {
    // The result is an open object by contract. A job that succeeded without
    // naming one is possible, and the honest answer is no button rather than a
    // crash on a screen whose job is to list things.
    renderWith(<JobsScreen />, clientFor([job({ status: 'SUCCEEDED', result: { rows: 12 } })]));

    await waitFor(() => expect(screen.getByText('SUCCEEDED')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /download the result/i })).toBeNull();
  });
});

describe('the list', () => {
  it('says nothing has run without looking broken', async () => {
    renderWith(<JobsScreen />, clientFor([]));

    await waitFor(() => expect(screen.getByText(/nothing has run/i)).toBeTruthy());
  });
});
