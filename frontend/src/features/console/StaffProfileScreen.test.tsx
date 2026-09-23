import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute } from '@/test-utils';

import { StaffProfileScreen } from './StaffProfileScreen';

/**
 * The profile a platform staff member with no membership has nowhere else
 * (2026-09-22): the name and the language, through `/staff/me`.
 */
const SAM = {
  user_id: 'u-sam',
  email: 'sam@demo.test',
  display_name: 'Sam Staff',
  locale: 'fr',
  roles: ['PLATFORM_ADMIN'],
  permissions: ['staff.self.read'],
};

describe('a staff member’s own profile', () => {
  it('reads the name and the language from the staff identity and writes them back through it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: { staff: SAM } },
      'PATCH /api/v1/staff/me': { data: { staff: { ...SAM, display_name: 'Samuel', locale: 'de' } } },
    });

    renderAtRoute(<StaffProfileScreen />, client, { path: '/console/profile' });

    const name = await screen.findByLabelText('Display name');
    expect(name).toHaveProperty('value', 'Sam Staff');
    expect(screen.getByTestId('locale')).toHaveProperty('value', 'fr');
    // Nothing about a default product: that is a choice among memberships.
    expect(screen.queryByTestId('default-product')).toBeNull();

    fireEvent.change(name, { target: { value: 'Samuel' } });
    fireEvent.change(screen.getByTestId('locale'), { target: { value: 'de' } });
    fireEvent.submit(name.closest('form') as HTMLFormElement);

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH' && r.path === '/api/v1/staff/me')).toBe(true));
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({ display_name: 'Samuel', locale: 'de' });
    // Never the tenant route: this person may hold no membership at all.
    expect(requests.some((r) => r.path === '/api/v1/me')).toBe(false);
  });

  it('asks for a new password at the staff address, by the one mechanism there is', async () => {
    // Staff sign in with an address and a password like anybody else, so the
    // link that replaces one belongs to the account, not to the authority:
    // the same operation the sign-in screen offers, sent to `/staff/me`'s
    // address rather than to `/me`'s, which this person may not have.
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: { staff: SAM } },
      'POST /api/v1/auth/password/forgot': { data: { accepted: true }, status: 202 },
    });

    renderAtRoute(<StaffProfileScreen />, client, { path: '/console/profile' });

    fireEvent.click(await screen.findByTestId('ask-for-password-link'));

    await waitFor(() => expect(screen.getByTestId('password-link-sent')).toBeTruthy());
    expect(requests.find((r) => r.path === '/api/v1/auth/password/forgot')?.body).toEqual({
      email: SAM.email,
    });
    expect(requests.some((r) => r.path === '/api/v1/me')).toBe(false);
  });

  it('clears the name with null rather than an empty string', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: { staff: SAM } },
      'PATCH /api/v1/staff/me': { data: { staff: { ...SAM, display_name: null } } },
    });

    renderAtRoute(<StaffProfileScreen />, client, { path: '/console/profile' });

    const name = await screen.findByLabelText('Display name');
    fireEvent.change(name, { target: { value: '' } });
    fireEvent.submit(name.closest('form') as HTMLFormElement);

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({ display_name: null, locale: 'fr' });
  });
});
