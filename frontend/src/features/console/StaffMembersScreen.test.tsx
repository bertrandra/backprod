import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, stubClient } from '@/test-utils';

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

function clientFor(members: unknown[], meId = 'someone-else') {
  return stubClient({
    'GET /api/v1/staff/members': { data: { members, roles: ROLES } },
    'GET /api/v1/staff/me': {
      data: { staff: { user_id: meId, roles: ['PLATFORM_ADMIN'], permissions: ['staff.grant'] } },
    },
    'POST /api/v1/staff/members': { data: { members, roles: ROLES } },
    'DELETE /api/v1/staff/members/{userId}/roles/{role}': { data: { members, roles: ROLES } },
  });
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
