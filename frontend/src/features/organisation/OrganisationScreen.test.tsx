import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, SESSION, type Stub } from '@/test-utils';

import { OrganisationScreen } from './OrganisationScreen';

/**
 * Who may join (2026-09-17): the organisation's say in what a sign-up at its
 * root does. Read with the organisation, set by whoever may manage it, and
 * sent as a partial update — the name is not re-sent to change the policy.
 */
const ACME = {
  id: 't-1',
  name: 'Acme Ltd',
  slug: 'acme',
  may_author_offers: false,
  join_policy: 'APPROVAL',
  join_domains: [],
};

function clientFor(me = SESSION, tenant = ACME) {
  return recordingClient({
    'GET /api/v1/me': { data: me },
    'GET /api/v1/tenants/current': { data: { tenant } },
    'GET /api/v1/tenants/current/usage': { data: { usage: [] } },
    'PATCH /api/v1/tenants/current': (): Stub => ({
      data: { tenant: { ...tenant, join_policy: 'DOMAIN', join_domains: ['acme.test'] } },
    }),
  });
}

describe('who may join', () => {
  it('shows the policy in force, with the domains only under DOMAIN', async () => {
    renderWith(<OrganisationScreen />, clientFor().client);

    await waitFor(() => expect(screen.getByTestId('join-policy')).toBeTruthy());
    expect(screen.getByLabelText<HTMLInputElement>(/Ask an administrator/).checked).toBe(true);
    expect(screen.queryByLabelText('Email domains')).toBeNull();

    fireEvent.click(screen.getByLabelText(/By email domain/));
    expect(screen.getByLabelText('Email domains')).toBeTruthy();
  });

  it('sends the policy and the domains as a partial update, without the name', async () => {
    const { client, requests } = clientFor();

    renderWith(<OrganisationScreen />, client);

    await waitFor(() => expect(screen.getByTestId('join-policy')).toBeTruthy());
    fireEvent.click(screen.getByLabelText(/By email domain/));
    fireEvent.change(screen.getByLabelText('Email domains'), { target: { value: 'acme.test, Acme.example' } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[1] as HTMLElement);

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));

    // Split and trimmed here; lower-cased and validated by the server.
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({
      join_policy: 'DOMAIN',
      join_domains: ['acme.test', 'Acme.example'],
    });
  });

  it('is read-only for somebody who may not manage the organisation', async () => {
    const reader = { ...SESSION, permissions: ['tenant.read'] };

    renderWith(<OrganisationScreen />, clientFor(reader).client);

    await waitFor(() => expect(screen.getByTestId('join-policy')).toBeTruthy());
    // Disabled through the fieldset, which the property does not reflect.
    expect(screen.getByLabelText(/Ask an administrator/).matches(':disabled')).toBe(true);
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
  });
});
