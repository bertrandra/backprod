import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, stubClient } from '@/test-utils';

import { AccessLogScreen } from './AccessLogScreen';

/**
 * Non-negotiable #21: a staff access is traced, motivated and never silent.
 *
 * The *motivation* the platform records is the permission the read was made
 * under — the answer to "on what grounds?" that a log of who-looked-at-what
 * cannot give on its own. So the permission is asserted present on every entry,
 * and an entry missing one is asserted to be *shown* as missing: a crossing
 * whose grounds were not recorded is the interesting row, not the boring one.
 */
const entry = (id: string, permission: string | null) => ({
  id,
  staff_user_id: 'staff-1',
  tenant_id: 't-1',
  product_id: 'p-1',
  action: 'tenant.read',
  resource_type: 'tenant',
  resource_id: 't-1',
  permission,
  detail: {},
  occurred_at: '2026-05-01T10:00:00Z',
});

function clientFor(entries: unknown[], total = entries.length) {
  return stubClient({
    'GET /api/v1/staff/access-log': { data: { entries, total, limit: 50, offset: 0 } },
  });
}

describe('an entry', () => {
  it('says on what grounds the read was made', async () => {
    renderWith(<AccessLogScreen />, clientFor([entry('e-1', 'staff.tenants.read')]));

    await waitFor(() =>
      expect(screen.getByTestId('access-permission').textContent).toBe('staff.tenants.read'),
    );
    expect(screen.getByTestId('access-action').textContent).toBe('tenant.read');
  });

  it('marks a crossing whose grounds were not recorded', async () => {
    renderWith(<AccessLogScreen />, clientFor([entry('e-2', null)]));

    await waitFor(() =>
      expect(screen.getByTestId('access-permission').textContent).toBe('no permission recorded'),
    );
    // Said, not left blank: an access without recorded grounds is worth noticing.
    expect(screen.getByTestId('access-permission').getAttribute('data-permission')).toBe('');
  });

  it('names who looked and at what', async () => {
    renderWith(<AccessLogScreen />, clientFor([entry('e-3', 'staff.tenants.read')]));

    await waitFor(() => expect(screen.getByText('staff-1')).toBeTruthy());
    expect(screen.getByText(/tenant t-1/)).toBeTruthy();
  });
});

describe('the log', () => {
  it('tells the reader their own reads are in it', async () => {
    renderWith(<AccessLogScreen />, clientFor([entry('e-4', 'support.read')]));

    await waitFor(() => expect(screen.getByText(/Your own reads appear here too/i)).toBeTruthy());
  });

  it('reports the counted total', async () => {
    renderWith(<AccessLogScreen />, clientFor([entry('e-5', 'support.read')], 4_312));

    await waitFor(() =>
      expect(screen.getByTestId('entry-count').textContent).toBe('Showing 1 of 4312.'),
    );
  });

  it('distinguishes nothing recorded from a failed read', async () => {
    renderWith(<AccessLogScreen />, clientFor([]));

    await waitFor(() => expect(screen.getByText('Nothing recorded')).toBeTruthy());
  });
});
