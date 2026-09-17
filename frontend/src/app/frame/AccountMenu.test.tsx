import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { useSessionStore } from '@/state/session';
import { recordingClient, renderWith, SESSION, stubClient } from '@/test-utils';

import { AccountMenu } from './AccountMenu';

/**
 * Who is signed in, and the way out.
 *
 * Two people to name — a tenant member from `/me`, a platform staff member
 * from `/staff/me` — and one action. The menu is a menu: it opens on click,
 * says the name, offers Sign out, and closes on Escape.
 */
const REFUSED = {
  status: 403,
  error: { error: { code: 'PERMISSION_DENIED', message: 'Not allowed.', details: {}, request_id: 'r' } },
};

const STAFF = {
  staff: {
    user_id: 'u-sam',
    email: 'sam@demo.test',
    display_name: 'Sam Staff',
    roles: ['PLATFORM_ADMIN'],
    permissions: ['staff.self.read'],
  },
};

beforeEach(() => {
  useSessionStore.setState({ token: 'access', status: 'signed-in', expiresAt: Date.now() + 3_600_000 });
});

describe('a tenant member', () => {
  it('is shown by initial, named on hover, and in full once opened', async () => {
    renderWith(
      <AccountMenu />,
      stubClient({ 'GET /api/v1/me': { data: SESSION }, 'GET /api/v1/staff/me': REFUSED }),
    );

    await waitFor(() => expect(screen.getByTestId('account-menu').textContent).toBe('A'));
    expect(screen.getByTestId('account-menu').getAttribute('title')).toBe('Ada');
    expect(screen.queryByTestId('account-menu-panel')).toBeNull();

    fireEvent.click(screen.getByTestId('account-menu'));

    expect(screen.getByRole('menu', { name: 'Account' })).toBeTruthy();
    expect(screen.getByTestId('account-name').textContent).toBe('Ada');
    expect(screen.getByTestId('account-email').textContent).toBe('ada@acme.test');
    expect(screen.getByRole('menuitem', { name: 'Sign out' })).toBeTruthy();
  });

  it('signs out from the menu', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: SESSION },
      'GET /api/v1/staff/me': REFUSED,
      'POST /api/v1/auth/sign-out': { status: 204 },
    });

    renderWith(<AccountMenu />, client);

    await waitFor(() => expect(screen.getByTestId('account-menu').textContent).toBe('A'));
    fireEvent.click(screen.getByTestId('account-menu'));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Sign out' }));

    await waitFor(() => expect(useSessionStore.getState().status).toBe('anonymous'));
    expect(requests.some((r) => r.method === 'POST' && r.path === '/api/v1/auth/sign-out')).toBe(true);
    // And lands on the home page: the address moved before the session went,
    // so the gate shows the storefront rather than a form for the page left.
    expect(window.location.pathname).toBe('/');
  });

  it('closes on Escape and gives focus back to the circle', async () => {
    renderWith(
      <AccountMenu />,
      stubClient({ 'GET /api/v1/me': { data: SESSION }, 'GET /api/v1/staff/me': REFUSED }),
    );

    await waitFor(() => expect(screen.getByTestId('account-menu').textContent).toBe('A'));
    fireEvent.click(screen.getByTestId('account-menu'));
    expect(screen.getByTestId('account-menu-panel')).toBeTruthy();

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(screen.queryByTestId('account-menu-panel')).toBeNull();
    expect(document.activeElement).toBe(screen.getByTestId('account-menu'));
  });
});

describe('a platform staff member', () => {
  it('is named from the staff identity, since /me refuses them', async () => {
    renderWith(
      <AccountMenu />,
      stubClient({ 'GET /api/v1/me': REFUSED, 'GET /api/v1/staff/me': { data: STAFF } }),
    );

    await waitFor(() => expect(screen.getByTestId('account-menu').textContent).toBe('S'));
    expect(screen.getByTestId('account-menu').getAttribute('title')).toBe('Sam Staff');

    fireEvent.click(screen.getByTestId('account-menu'));
    expect(screen.getByTestId('account-name').textContent).toBe('Sam Staff');
    expect(screen.getByTestId('account-email').textContent).toBe('sam@demo.test');
  });

  it('is still somebody who can sign out when erased', async () => {
    renderWith(
      <AccountMenu />,
      stubClient({
        'GET /api/v1/me': REFUSED,
        'GET /api/v1/staff/me': { data: { staff: { ...STAFF.staff, email: null, display_name: null } } },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('account-menu')).toBeTruthy());
    expect(screen.getByTestId('account-menu').textContent).toBe('?');

    fireEvent.click(screen.getByTestId('account-menu'));
    expect(screen.getByRole('menuitem', { name: 'Sign out' })).toBeTruthy();
  });
});
