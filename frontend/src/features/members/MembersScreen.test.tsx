import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, SESSION, stubClient } from '@/test-utils';

import { MembersScreen } from './MembersScreen';

/**
 * The people waiting to join (2026-09-17).
 *
 * A sign-up at the organisation's root writes a PENDING membership under the
 * APPROVAL policy. It is not a member — `listMembers` leaves it out — so the
 * screen shows it as a request, to anybody who may read the members, and
 * lets those who may manage them decide. Nothing on the screen when nobody
 * waits: an empty "requests" section would announce a feature, not a fact.
 */
const READER = { ...SESSION, permissions: ['members.read'] };
const MANAGER = { ...READER, permissions: [...READER.permissions, 'members.manage'] };

const ANN = { user_id: 'u-ann', email: 'ann@acme.test', display_name: 'Ann', roles: ['TENANT_ADMIN'], status: 'ACTIVE' };
const ZED = { user_id: 'u-zed', email: 'zed@elsewhere.test', display_name: 'Zed', roles: ['USER'], status: 'PENDING' };

function stubsFor(me: typeof READER, requests: unknown[]) {
  return {
    'GET /api/v1/me': { data: me },
    'GET /api/v1/tenants/current/members': { data: { members: [ANN] } },
    'GET /api/v1/tenants/current/members/requests': { data: { requests } },
    'POST /api/v1/tenants/current/members/{userId}/accept': { status: 204, data: {} },
    'POST /api/v1/tenants/current/members/{userId}/decline': { status: 204, data: {} },
  };
}

describe('who is waiting to join', () => {
  it('is listed apart from the members, and not at all when nobody is', async () => {
    renderWith(<MembersScreen />, stubClient(stubsFor(MANAGER, [ZED])));

    await waitFor(() => expect(screen.getByTestId('join-requests')).toBeTruthy());
    expect(screen.getByTestId('join-request-u-zed').textContent).toContain('zed@elsewhere.test');

    // Ann is a member; Zed is not one yet.
    const members = screen.getAllByRole('list')[0];
    expect(members?.textContent).toContain('ann@acme.test');
    expect(members?.textContent).not.toContain('zed@elsewhere.test');
  });

  it('shows nothing about requests when there are none', async () => {
    renderWith(<MembersScreen />, stubClient(stubsFor(MANAGER, [])));

    await waitFor(() => expect(screen.getByText('ann@acme.test')).toBeTruthy());
    expect(screen.queryByTestId('join-requests')).toBeNull();
  });

  it('lets a reader see the queue and only a manager decide', async () => {
    renderWith(<MembersScreen />, stubClient(stubsFor(READER, [ZED])));

    await waitFor(() => expect(screen.getByTestId('join-requests')).toBeTruthy());
    expect(screen.queryByRole('button', { name: 'Accept' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Decline' })).toBeNull();
  });

  it('accepts through the accept operation, on that person, and asks both lists again', async () => {
    const { client, requests } = recordingClient(stubsFor(MANAGER, [ZED]));

    renderWith(<MembersScreen />, client);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Accept' })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Accept' }));

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/tenants/current/members/{userId}/accept')).toBe(true),
    );

    // Invalidated, not patched: accepting moves a person from one list to the
    // other, and which roles they arrive with is the server's to say.
    await waitFor(() =>
      expect(requests.filter((r) => r.path === '/api/v1/tenants/current/members').length).toBeGreaterThan(1),
    );
    await waitFor(() =>
      expect(requests.filter((r) => r.path === '/api/v1/tenants/current/members/requests').length).toBeGreaterThan(1),
    );
  });

  it('declines through the decline operation, never the removal', async () => {
    const { client, requests } = recordingClient(stubsFor(MANAGER, [ZED]));

    renderWith(<MembersScreen />, client);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Decline' })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Decline' }));

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/tenants/current/members/{userId}/decline')).toBe(true),
    );
    expect(requests.some((r) => r.method === 'DELETE')).toBe(false);
  });
});
