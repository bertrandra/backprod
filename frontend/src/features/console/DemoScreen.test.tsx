import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useSessionStore } from '@/state/session';
import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { DemoScreen } from './DemoScreen';

/**
 * The demonstration screen (2026-09-18): the public page's switch and the
 * world's reset, each offered to whoever holds its permission and nobody
 * else, and each sending exactly what it says.
 */
const ATLAS = { id: 'p-1', code: 'atlas', name: 'Atlas', active: true };

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/demo/page': { data: { published: false } },
    ...extra,
  });
}

describe('the demonstration world', () => {
  const ADMIN = { staff: { user_id: 'u-sam', roles: ['PLATFORM_ADMIN'], permissions: ['staff.products.manage', 'staff.demo.reset'] } };
  const SUPPORT = { staff: { user_id: 'u-hedy', roles: ['SUPPORT_ADMIN'], permissions: ['staff.tenants.read'] } };
  const WORLD = {
    products: [{ code: 'atlas', name: 'Atlas' }, { code: 'boreas', name: 'Boreas' }],
    tenants: [{ slug: 'acme', name: 'Acme Ltd', holds: ['atlas', 'boreas'] }],
    people: [
      { email: 'ada@demo.test', name: 'Ada Lovelace', scope: 'tenant', role: 'TENANT_ADMIN', tenants: ['acme'] },
      { email: 'sam@demo.test', name: 'Sam Staff', scope: 'platform', role: 'PLATFORM_ADMIN', tenants: [] },
    ],
    password: 'demo-password-1234',
    invoices: ['2026-000001'],
  };

  it('is offered only to whoever may reset it', async () => {
    renderAtRoute(
      <DemoScreen />,
      clientFor({ 'GET /api/v1/staff/me': { data: SUPPORT } }),
      { path: '/console/demo' },
    );

    await waitFor(() => expect(screen.getByText('Demonstration')).toBeTruthy());
    // Hiding is courtesy; the server refuses regardless. But a button that
    // always 403s is a button that should not be there.
    await waitFor(() => expect(screen.queryByTestId('demo-world')).toBeNull());
  });

  it('asks twice, and sends nothing until the second yes', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'GET /api/v1/staff/me': { data: ADMIN },
      'POST /api/v1/staff/demo/reset': { data: { world: WORLD } },
    });

    renderAtRoute(<DemoScreen />, client, { path: '/console/demo' });

    await waitFor(() => expect(screen.getByTestId('demo-world-arm')).toBeTruthy());
    expect(screen.getByTestId('demo-world').textContent).toMatch(/including yours/i);

    fireEvent.click(screen.getByTestId('demo-world-arm'));
    expect(screen.getByTestId('demo-world-confirm')).toBeTruthy();
    expect(requests.filter((r) => r.method === 'POST')).toHaveLength(0);

    fireEvent.click(screen.getByRole('button', { name: 'Keep it' }));
    expect(screen.queryByTestId('demo-world-confirm')).toBeNull();
    expect(requests.filter((r) => r.method === 'POST')).toHaveLength(0);
  });

  it('shows who to sign in as, and signs out only when asked', async () => {
    useSessionStore.setState({ token: 'access', status: 'signed-in', expiresAt: Date.now() + 3_600_000 });
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/products': { data: { products: [ATLAS] } },
      'GET /api/v1/staff/me': { data: ADMIN },
      'POST /api/v1/staff/demo/reset': { data: { world: WORLD } },
    });

    renderAtRoute(<DemoScreen />, client, { path: '/console/demo' });

    await waitFor(() => expect(screen.getByTestId('demo-world-arm')).toBeTruthy());
    fireEvent.click(screen.getByTestId('demo-world-arm'));
    fireEvent.click(screen.getByTestId('demo-world-reset'));

    await waitFor(() => expect(screen.getByTestId('demo-world-done')).toBeTruthy());
    expect(requests.filter((r) => r.method === 'POST').map((r) => r.path)).toEqual(['/api/v1/staff/demo/reset']);
    expect(screen.getByTestId('demo-world-people').textContent).toContain('sam@demo.test');
    expect(screen.getByTestId('demo-world-done').textContent).toContain('demo-password-1234');
    // Nothing was refetched: every row the cache described is gone, and a
    // refetch would 401 and sign out under the person reading the answer.
    expect(requests.filter((r) => r.method === 'GET' && r.path !== '/api/v1/staff/me' && r.path !== '/api/v1/staff/demo/page')).toHaveLength(0);
    expect(useSessionStore.getState().status).toBe('signed-in');

    fireEvent.click(screen.getByTestId('demo-world-sign-in'));
    expect(useSessionStore.getState().status).toBe('anonymous');
  });

  it('says why when the platform is not a demonstration', async () => {
    renderAtRoute(
      <DemoScreen />,
      clientFor({
        'GET /api/v1/staff/me': { data: ADMIN },
        'POST /api/v1/staff/demo/reset': {
          status: 409,
          error: { error: { code: 'NOT_A_DEMO_DEPLOYMENT', message: "This platform hosts products that are not the demonstration's; resetting would destroy them.", details: { products: ['real-thing'] }, request_id: 'r' } },
        },
      }),
      { path: '/console/demo' },
    );

    await waitFor(() => expect(screen.getByTestId('demo-world-arm')).toBeTruthy());
    fireEvent.click(screen.getByTestId('demo-world-arm'));
    fireEvent.click(screen.getByTestId('demo-world-reset'));

    await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/not the demonstration/i));
    expect(screen.queryByTestId('demo-world-done')).toBeNull();
  });
});

describe('the demonstration page switch', () => {
  const PUBLISHER = { staff: { user_id: 'u-sam', roles: ['PLATFORM_ADMIN'], permissions: ['staff.products.manage', 'staff.demo.publish'] } };
  const SUPPORT = { staff: { user_id: 'u-hedy', roles: ['SUPPORT_ADMIN'], permissions: ['staff.tenants.read'] } };

  it('is offered only to whoever may publish, and reads the switch', async () => {
    renderAtRoute(
      <DemoScreen />,
      clientFor({ 'GET /api/v1/staff/me': { data: SUPPORT }, 'GET /api/v1/staff/demo/page': { data: { published: true } } }),
      { path: '/console/demo' },
    );

    await waitFor(() => expect(screen.getByText('Demonstration')).toBeTruthy());
    await waitFor(() => expect(screen.queryByTestId('demo-page')).toBeNull());
  });

  it('sends the switch as PUT and shows what the server wrote', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: PUBLISHER },
      'GET /api/v1/staff/products': { data: { products: [] } },
      'GET /api/v1/staff/demo/page': { data: { published: false } },
      'PUT /api/v1/staff/demo/page': { data: { published: true } },
    });

    renderAtRoute(<DemoScreen />, client, { path: '/console/demo' });

    await waitFor(() => expect(screen.getByTestId<HTMLInputElement>('demo-page-switch')).toBeTruthy());
    expect(screen.getByTestId<HTMLInputElement>('demo-page-switch').checked).toBe(false);
    expect(screen.getByTestId('demo-page').textContent).toContain('404');

    fireEvent.click(screen.getByTestId('demo-page-switch'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT' && r.path === '/api/v1/staff/demo/page')).toBe(true));
    expect(requests.find((r) => r.method === 'PUT')?.body).toEqual({ published: true });
    await waitFor(() => expect(screen.getByTestId<HTMLInputElement>('demo-page-switch').checked).toBe(true));
    expect(screen.getByTestId('demo-page').textContent).toContain('anybody');
  });
});
