import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, stubClient } from '@/test-utils';

import { AuditScreen } from './AuditScreen';

/**
 * Three answers to "who", not two.
 *
 * The contract carries `actor.erased` for one reason: *"collapsing them into a
 * bare null would turn every erasure into a system action."* A screen that
 * rendered a missing actor as "the platform" would quietly reassign a person's
 * acts to the platform, which is a false record and the kind that gets quoted in
 * a dispute.
 */
const entry = (id: string, actor: Record<string, unknown>) => ({
  id,
  occurred_at: '2026-05-01T10:00:00Z',
  action: 'invoice.issued',
  subject_type: 'invoice',
  subject_id: 'inv-1',
  tenant_id: 't-1',
  product_id: 'p-1',
  actor,
  project_id: null,
  request_id: 'req-7',
  detail: {},
});

const NAMED = entry('a-1', { user_id: 'u-1', erased: false });
const ERASED = entry('a-2', { user_id: 'u-2', erased: true });
const SYSTEM = entry('a-3', {});

function clientFor(entries: unknown[]) {
  return stubClient({
    'GET /api/v1/admin/audit': { data: { entries, total: entries.length, limit: 50, offset: 0 } },
  });
}

describe('who performed an act', () => {
  it('is the person, when there is one', async () => {
    renderWith(<AuditScreen />, clientFor([NAMED]));

    await waitFor(() => expect(screen.getByTestId('actor').getAttribute('data-actor')).toBe('user'));
    expect(screen.getByTestId('actor').textContent).toBe('u-1');
  });

  it('is "a person since erased" when the identity is gone', async () => {
    renderWith(<AuditScreen />, clientFor([ERASED]));

    await waitFor(() =>
      expect(screen.getByTestId('actor').getAttribute('data-actor')).toBe('erased'),
    );
    expect(screen.getByTestId('actor').textContent).toMatch(/since erased/i);
  });

  it('is the platform only when nobody performed it', async () => {
    renderWith(<AuditScreen />, clientFor([SYSTEM]));

    await waitFor(() =>
      expect(screen.getByTestId('actor').getAttribute('data-actor')).toBe('system'),
    );
  });

  it('never renders an erased person as the platform', async () => {
    renderWith(<AuditScreen />, clientFor([NAMED, ERASED, SYSTEM]));

    await waitFor(() => expect(screen.getAllByTestId('actor')).toHaveLength(3));

    const kinds = screen.getAllByTestId('actor').map((node) => node.getAttribute('data-actor'));

    // Three distinct answers from three entries. Two would mean one collapsed
    // into another, and it is always the erasure that collapses.
    expect(kinds).toEqual(['user', 'erased', 'system']);
    expect(new Set(kinds).size).toBe(3);
  });
});

describe('the trail', () => {
  it('quotes the request id, which is what a support thread needs', async () => {
    renderWith(<AuditScreen />, clientFor([NAMED]));

    await waitFor(() => expect(screen.getByText('req-7')).toBeTruthy());
  });

  it('says an erased author does not unmake the act', async () => {
    renderWith(<AuditScreen />, clientFor([ERASED]));

    await waitFor(() => expect(screen.getByText(/does not unmake it/i)).toBeTruthy());
  });

  it('reports the counted total', async () => {
    renderWith(
      <AuditScreen />,
      stubClient({
        'GET /api/v1/admin/audit': {
          data: { entries: [NAMED], total: 9_812, limit: 50, offset: 0 },
        },
      }),
    );

    await waitFor(() =>
      expect(screen.getByTestId('audit-count').textContent).toBe('Showing 1 of 9812.'),
    );
  });
});

describe('with nothing recorded', () => {
  it('says so rather than showing an empty list', async () => {
    renderWith(<AuditScreen />, clientFor([]));

    await waitFor(() => expect(screen.getByText('Nothing recorded')).toBeTruthy());
  });
});
