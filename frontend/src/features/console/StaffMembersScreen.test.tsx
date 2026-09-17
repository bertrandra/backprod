import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, stubClient, type Stub } from '@/test-utils';

import { StaffMembersScreen } from './StaffMembersScreen';

/**
 * The screen exists so a fresh installation is not a dead end, and its one
 * interesting property is what it does about the last administrator.
 *
 * The database refuses to let the platform reach zero of them. A console that
 * offered the button anyway and surfaced the 409 afterwards would be correct
 * and useless: the person clicking has no way to know which of their options
 * are real. So the row explains itself first. These tests hold that line — and
 * hold the other one too, that a revoke which *is* allowed still reaches the
 * server, because an over-eager guard here would be its own kind of wrong.
 */
const member = (id: string, roles: string[], email = `${id}@test`) => ({
  user_id: id,
  email,
  display_name: null,
  roles,
  granted_at: '2026-05-01T10:00:00Z',
});

const ROLES = [
  { code: 'PLATFORM_ADMIN', name: 'Platform administrator' },
  { code: 'SUPPORT_ADMIN', name: 'Support' },
];

function stubsFor(members: unknown[], meId = 'someone-else', permissions = ['staff.grant'], extra = {}) {
  return {
    'GET /api/v1/staff/members': { data: { members, roles: ROLES } },
    'GET /api/v1/staff/me': {
      data: { staff: { user_id: meId, roles: ['PLATFORM_ADMIN'], permissions } },
    },
    ...extra,
    'POST /api/v1/staff/members': { data: { members, roles: ROLES } },
    'DELETE /api/v1/staff/members/{userId}/roles/{role}': { data: { members, roles: ROLES } },
  };
}

function clientFor(members: unknown[], meId = 'someone-else') {
  return stubClient(stubsFor(members, meId));
}

describe('the last administrator', () => {
  it('is explained rather than offered and refused', async () => {
    renderWith(<StaffMembersScreen />, clientFor([member('only-admin', ['PLATFORM_ADMIN'])]));

    await waitFor(() => expect(screen.getByTestId('staff-list')).toBeTruthy());

    expect(screen.getByTestId('role-protected').textContent).toContain(
      'the last administrator',
    );
    expect(screen.queryByRole('button', { name: 'Revoke' })).toBeNull();
  });

  it('stops being the last one once somebody else holds the role', async () => {
    renderWith(
      <StaffMembersScreen />,
      clientFor([
        member('admin-one', ['PLATFORM_ADMIN']),
        member('admin-two', ['PLATFORM_ADMIN']),
      ]),
    );

    await waitFor(() => expect(screen.getByTestId('staff-list')).toBeTruthy());

    // Neither is the last, so both may be revoked by a third administrator.
    expect(screen.getAllByRole('button', { name: 'Revoke' })).toHaveLength(2);
    expect(screen.queryByTestId('role-protected')).toBeNull();
  });
});

describe('your own administrator role', () => {
  it('is not revocable by you, and says who can', async () => {
    renderWith(
      <StaffMembersScreen />,
      clientFor(
        [member('me', ['PLATFORM_ADMIN']), member('them', ['PLATFORM_ADMIN'])],
        'me',
      ),
    );

    await waitFor(() => expect(screen.getByTestId('staff-list')).toBeTruthy());

    expect(screen.getByTestId('role-protected').textContent).toContain('another administrator');
    // Theirs is still revocable: only mine is protected.
    expect(screen.getAllByRole('button', { name: 'Revoke' })).toHaveLength(1);
  });
});

describe('appointing somebody', () => {
  it('offers the roles the platform actually has, not a list compiled in', async () => {
    renderWith(<StaffMembersScreen />, clientFor([member('only-admin', ['PLATFORM_ADMIN'])]));

    await waitFor(() => expect(screen.getByTestId('staff-list')).toBeTruthy());

    const options = screen.getAllByRole('option').map((o) => o.textContent);
    expect(options).toContain('Support (SUPPORT_ADMIN)');
    expect(options).toContain('Platform administrator (PLATFORM_ADMIN)');
  });

  it('finds the person in the directory where it may be read, and sends their id', async () => {
    const searches: string[] = [];
    const { client, requests } = recordingClient(stubsFor([member('only-admin', ['PLATFORM_ADMIN'])], 'someone-else', ['staff.grant', 'admin.directory.read'], {
      'GET /api/v1/admin/users': (): Stub => ({
        data: {
          users: [
            { id: 'u-ada', email: 'ada@example.test', display_name: 'Ada Lovelace', created_at: '2026-01-01T00:00:00Z', erased_at: null },
            { id: 'u-gone', email: null, display_name: null, created_at: '2026-01-01T00:00:00Z', erased_at: '2026-02-01T00:00:00Z' },
          ],
          total: 2,
          limit: 8,
          offset: 0,
        },
      }),
    }));

    renderWith(<StaffMembersScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Who')).toBeTruthy());
    expect(screen.queryByLabelText('User id')).toBeNull();

    fireEvent.change(screen.getByLabelText('Who'), { target: { value: 'ada' } });

    // Found, by the directory's own search — and an erased person, having
    // neither name nor address, is not offered.
    await waitFor(() => expect(screen.getByRole('option', { name: /Ada Lovelace/ })).toBeTruthy());
    expect(screen.queryByRole('option', { name: /u-gone/ })).toBeNull();
    searches.push(...requests.filter((r) => r.path === '/api/v1/admin/users').map((r) => String((r.query as { search?: string }).search)));
    expect(searches).toContain('ada');

    fireEvent.mouseDown(screen.getByRole('option', { name: /Ada Lovelace/ }));

    // Chosen: read back as name and address, with the id that will be sent.
    await waitFor(() => expect(document.querySelector('[data-picked="u-ada"]')).not.toBeNull());
    expect(screen.getByText('u-ada')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'SUPPORT_ADMIN' } });
    fireEvent.click(screen.getByRole('button', { name: 'Grant role' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'POST' && r.path === '/api/v1/staff/members')).toBe(true));
    const sent = requests.find((r) => r.method === 'POST' && r.path === '/api/v1/staff/members');
    expect((sent?.body as { user_id?: unknown }).user_id).toBe('u-ada');
  });

  it('cannot be submitted without both a user and a role', async () => {
    renderWith(<StaffMembersScreen />, clientFor([member('only-admin', ['PLATFORM_ADMIN'])]));

    await waitFor(() => expect(screen.getByTestId('staff-list')).toBeTruthy());

    const submit = screen.getByRole('button', { name: 'Grant role' });
    expect(submit.hasAttribute('disabled')).toBe(true);

    fireEvent.change(screen.getByLabelText('User id'), { target: { value: 'a-user' } });
    expect(submit.hasAttribute('disabled')).toBe(true);

    fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'SUPPORT_ADMIN' } });
    await waitFor(() => expect(submit.hasAttribute('disabled')).toBe(false));
  });
});
