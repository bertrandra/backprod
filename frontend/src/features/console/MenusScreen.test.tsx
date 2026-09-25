import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stub } from '@/test-utils';

import { MenusScreen } from './MenusScreen';

/**
 * The menu setup screen: three checklists of the shell's own entries and,
 * per audience, whether what is empty is shown.
 *
 * What is ticked is what is *shown*; what is sent is the inverse. That
 * flip is the one thing here that could be quietly wrong, so it is what
 * the tests read on the wire.
 */
const EVERYTHING = { hidden: [], hide_empty: false };

const setup = (overrides: Record<string, unknown> = {}) => ({
  platform_admin: EVERYTHING,
  tenant_admin: EVERYTHING,
  user: EVERYTHING,
  ...overrides,
});

const ROUTE = { path: '/console/menus', initial: '/console/menus' } as const;

const box = (audience: string, entry: string) =>
  screen.getByTestId(`menus-${audience}`).querySelector<HTMLInputElement>(`[data-entry="${entry}"]`);

describe('the menu setup', () => {
  it('offers each audience the entries of its own scope, ticked where shown', async () => {
    renderAtRoute(
      <MenusScreen />,
      stubClient({
        'GET /api/v1/staff/navigation': { data: { navigation: setup({ user: { hidden: ['invoices'], hide_empty: true } }) } },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByTestId('menus-user')).toBeTruthy());

    // A member's checklist is the tenant entries; the console's is the platform's.
    expect(box('user', 'invoices')?.checked).toBe(false);
    expect(box('user', 'projects')?.checked).toBe(true);
    expect(box('user', 'audit')).toBeNull();
    expect(box('platform_admin', 'audit')?.checked).toBe(true);
    expect(box('platform_admin', 'invoices')).toBeNull();
    // This screen's own entry is never offered: it cannot be hidden from
    // the person who holds it.
    expect(box('platform_admin', 'menus')).toBeNull();

    expect(screen.getByTestId('menus-user').querySelector<HTMLInputElement>('[data-hide-empty="user"]')?.checked).toBe(true);
    expect(screen.getByTestId('menus-tenant_admin').querySelector<HTMLInputElement>('[data-hide-empty="tenant_admin"]')?.checked).toBe(false);
  });

  it('sends what was unticked as hidden, every audience, in one document', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/navigation': { data: { navigation: setup() } },
      'PUT /api/v1/staff/navigation': (): Stub => ({
        data: { navigation: setup({ user: { hidden: ['invoices', 'orders'], hide_empty: true } }) },
      }),
    });

    renderAtRoute(<MenusScreen />, client, ROUTE);
    await waitFor(() => expect(screen.getByTestId('menus-user')).toBeTruthy());

    const save = screen.getByRole('button', { name: 'Save menus' });
    expect(save.hasAttribute('disabled')).toBe(true);

    fireEvent.click(box('user', 'invoices') as HTMLInputElement);
    fireEvent.click(box('user', 'orders') as HTMLInputElement);
    fireEvent.click(screen.getByTestId('menus-user').querySelector('[data-hide-empty="user"]') as HTMLInputElement);
    expect(save.hasAttribute('disabled')).toBe(false);

    fireEvent.click(save);

    await waitFor(() => expect(screen.getByTestId('menus-saved')).toBeTruthy());

    const sent = requests.find((r) => r.method === 'PUT');
    expect(sent?.body).toEqual({
      navigation: {
        platform_admin: EVERYTHING,
        tenant_admin: EVERYTHING,
        user: { hidden: ['invoices', 'orders'], hide_empty: true },
      },
    });
    // The stored document is what is shown afterwards, not the draft.
    expect(box('user', 'invoices')?.checked).toBe(false);
  });

  it('re-ticking an entry takes it out of the hidden list', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/navigation': { data: { navigation: setup({ tenant_admin: { hidden: ['orders'], hide_empty: false } }) } },
      'PUT /api/v1/staff/navigation': (): Stub => ({ data: { navigation: setup() } }),
    });

    renderAtRoute(<MenusScreen />, client, ROUTE);
    await waitFor(() => expect(box('tenant_admin', 'orders')?.checked).toBe(false));

    fireEvent.click(box('tenant_admin', 'orders') as HTMLInputElement);
    fireEvent.click(screen.getByRole('button', { name: 'Save menus' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'PUT')).toBe(true));
    expect((requests.find((r) => r.method === 'PUT')?.body as { navigation: Record<string, unknown> }).navigation.tenant_admin).toEqual(EVERYTHING);
  });
});
