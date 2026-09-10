import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, stubClient, type Stubs } from '@/test-utils';

import { QueueScreen } from './QueueScreen';

/**
 * A lapse is a fact about the clock.
 *
 * The queue is cron-polled, so nothing writes "broken" anywhere when it stops:
 * every counter simply stays where it was. That is why `never_ran` exists —
 * *"that state is all zeroes and reads exactly like a calm idle queue"* — and why
 * the test below feeds exactly those zeroes and insists the screen still says
 * something different.
 */
const HEALTHY = {
  never_ran: false,
  last_run: {
    started_at: '2026-05-01T10:00:00Z',
    finished_at: '2026-05-01T10:00:05Z',
    seconds_since_started: 40,
    seconds_since_finished: 35,
  },
  unfinished_runs: 0,
  oldest_unfinished_seconds: null,
  backlog: { due: 2, oldest_due_seconds: 12 },
  stale_after_seconds: 300,
  stale: false,
};

/** All zeroes, and the runner has never started. */
const NEVER_RAN = {
  never_ran: true,
  last_run: {
    started_at: null,
    finished_at: null,
    seconds_since_started: null,
    seconds_since_finished: null,
  },
  unfinished_runs: 0,
  oldest_unfinished_seconds: null,
  backlog: { due: 0, oldest_due_seconds: null },
  stale_after_seconds: 300,
  stale: false,
};

const JOB = {
  id: 'j-1',
  type: 'notifications.deliver',
  status: 'FAILED',
  tenant_id: 't-1',
  product_id: 'p-1',
  priority: 0,
  attempts: 3,
  max_attempts: 3,
  run_after: null,
  leased_until: null,
  failure_reason: 'SMTP refused the recipient',
  started_at: '2026-05-01T09:00:00Z',
  finished_at: '2026-05-01T09:00:01Z',
  created_at: '2026-05-01T08:59:00Z',
};

function clientFor(health: unknown, extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/admin/queue': { data: health },
    'GET /api/v1/admin/jobs': { data: { job: [JOB], total: 1, limit: 25, offset: 0 } },
    ...extra,
  });
}

describe('a runner that has never run', () => {
  it('is not reported as a calm idle queue', async () => {
    renderWith(<QueueScreen />, clientFor(NEVER_RAN));

    await waitFor(() =>
      expect(screen.getByTestId('liveness').getAttribute('data-verdict')).toBe('never-ran'),
    );

    // Every count in that payload is zero. Only `never_ran` distinguishes it,
    // and the screen must be reading it rather than the zeroes.
    expect(screen.getByText(/has never run/i)).toBeTruthy();
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.queryByText(/keeping up/i)).toBeNull();
  });
});

describe('a runner that is keeping up', () => {
  it('says so, and shows the clock behind the verdict', async () => {
    renderWith(<QueueScreen />, clientFor(HEALTHY));

    await waitFor(() =>
      expect(screen.getByTestId('liveness').getAttribute('data-verdict')).toBe('live'),
    );
    expect(screen.getByTestId('last-finished').textContent).toMatch(/35 s ago/);
    expect(screen.getByTestId('backlog').textContent).toMatch(/2/);
  });
});

describe('a runner past the threshold', () => {
  it('is called stale, and the threshold is named', async () => {
    renderWith(
      <QueueScreen />,
      clientFor({
        ...HEALTHY,
        stale: true,
        last_run: { ...HEALTHY.last_run, seconds_since_finished: 4_000 },
      }),
    );

    await waitFor(() =>
      expect(screen.getByTestId('liveness').getAttribute('data-verdict')).toBe('stale'),
    );
    // The threshold the verdict was measured against, not just the verdict.
    expect(screen.getByTestId('verdict').textContent).toMatch(/within 5 min/);
  });

  it('is the server verdict, not one this screen computed', async () => {
    // A long silence, and yet `stale` is false — the backend measured against a
    // threshold it holds. A screen doing its own arithmetic would disagree with
    // the platform about whether an incident is happening.
    renderWith(
      <QueueScreen />,
      clientFor({
        ...HEALTHY,
        stale: false,
        last_run: { ...HEALTHY.last_run, seconds_since_finished: 9_999 },
      }),
    );

    await waitFor(() =>
      expect(screen.getByTestId('liveness').getAttribute('data-verdict')).toBe('live'),
    );
  });
});

describe('the threshold', () => {
  it('is sent to the server, because it is what turns a clock into a verdict', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/admin/queue': { data: HEALTHY },
      'GET /api/v1/admin/jobs': { data: { job: [], total: 0, limit: 25, offset: 0 } },
    });

    renderWith(<QueueScreen />, client);

    const asked = () =>
      requests
        .filter((request) => request.path === '/api/v1/admin/queue')
        .map((request) => (request.query as { stale_after?: number } | undefined)?.stale_after);

    await waitFor(() => expect(asked()).toContain(300));

    fireEvent.change(screen.getByLabelText('Call it stale after'), { target: { value: '60' } });

    await waitFor(() => expect(asked()).toContain(60));
  });

  it('cannot be set outside what the API accepts', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/admin/queue': { data: HEALTHY },
      'GET /api/v1/admin/jobs': { data: { job: [], total: 0, limit: 25, offset: 0 } },
    });

    renderWith(<QueueScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Call it stale after')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Call it stale after'), { target: { value: '99999' } });

    const asked = requests
      .filter((request) => request.path === '/api/v1/admin/queue')
      .map((request) => (request.query as { stale_after?: number } | undefined)?.stale_after);

    // 86400 is the contract's ceiling, so the screen never asks for what the
    // API will refuse.
    expect(asked).not.toContain(99_999);
  });
});

describe('the job list', () => {
  it('shows a failure with its reason and its attempts', async () => {
    renderWith(<QueueScreen />, clientFor(HEALTHY));

    await waitFor(() => expect(screen.getByTestId('job-status').textContent).toBe('FAILED'));
    expect(screen.getByTestId('job-failure').textContent).toBe('SMTP refused the recipient');
    expect(screen.getByText(/attempt 3 of 3/)).toBeTruthy();
  });

  it('says an empty list and a stopped runner look alike', async () => {
    renderWith(
      <QueueScreen />,
      clientFor(HEALTHY, {
        'GET /api/v1/admin/jobs': { data: { job: [], total: 0, limit: 25, offset: 0 } },
      }),
    );

    await waitFor(() => expect(screen.getByText('No jobs')).toBeTruthy());
    expect(screen.getByText(/the clock above says which/i)).toBeTruthy();
  });

  it('asks the server for a status rather than filtering locally', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/admin/queue': { data: HEALTHY },
      'GET /api/v1/admin/jobs': { data: { job: [JOB], total: 1, limit: 25, offset: 0 } },
    });

    renderWith(<QueueScreen />, client);

    await waitFor(() => expect(screen.getByTestId('job-status')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'PENDING' } });

    await waitFor(() =>
      expect(
        requests.some(
          (request) =>
            request.path === '/api/v1/admin/jobs' &&
            (request.query as { status?: string } | undefined)?.status === 'PENDING',
        ),
      ).toBe(true),
    );
  });
});
