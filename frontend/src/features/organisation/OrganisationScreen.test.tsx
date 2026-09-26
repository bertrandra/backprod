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
  default_product: null as string | null,
};

const HELD = [
  { id: 'p-1', code: 'plan', name: 'Plan', active: true },
  { id: 'p-2', code: 'atlas', name: 'Atlas', active: true },
];

function clientFor(me = SESSION, tenant = ACME) {
  return recordingClient({
    'GET /api/v1/me': { data: me },
    'GET /api/v1/tenants/current': { data: { tenant } },
    'GET /api/v1/tenants/current/usage': { data: { usage: [] } },
    'GET /api/v1/products': {
      data: { products: HELD, default: null, memberships: [], pending_memberships: [] },
    },
    'PATCH /api/v1/tenants/current': (): Stub => ({
      data: { tenant: { ...tenant, join_policy: 'DOMAIN', join_domains: ['acme.test'] } },
    }),
  });
}

/**
 * Which product the organisation opens on (2026-09-26).
 *
 * A courtesy and never an authority — it settles where a screen opens and
 * nothing about what anybody may reach there. Until today the only answer was
 * `VITE_DEFAULT_PRODUCT`, compiled into the bundle, so a deployment that
 * leads with Plan showed Atlas until somebody rebuilt it.
 */
describe('the opening product', () => {
  it('offers the products the organisation holds, and no preference', async () => {
    renderWith(<OrganisationScreen />, clientFor().client);

    await waitFor(() => expect(screen.getByTestId('default-product')).toBeTruthy());

    const select = screen.getByTestId<HTMLSelectElement>('default-product-select');

    expect(Array.from(select.options).map((option) => option.value)).toEqual(['', 'plan', 'atlas']);
    // Nothing chosen is the empty option, not the first product: an
    // organisation with no answer lets the deployment's default decide.
    expect(select.value).toBe('');
  });

  it('sends the code alone, and sends null to clear it', async () => {
    const { client, requests } = clientFor(SESSION, { ...ACME, default_product: 'plan' });
    renderWith(<OrganisationScreen />, client);

    await waitFor(() => expect(screen.getByTestId('default-product-select')).toBeTruthy());
    expect(screen.getByTestId<HTMLSelectElement>('default-product-select').value).toBe('plan');

    fireEvent.change(screen.getByTestId('default-product-select'), { target: { value: 'atlas' } });

    await waitFor(() =>
      expect(requests.some((request) => request.method === 'PATCH')).toBe(true),
    );

    const sent = requests.filter((request) => request.method === 'PATCH');
    // The code alone: the name and the join policy are not re-sent to change
    // which product the organisation opens on.
    expect(sent[0]?.body).toEqual({ default_product: 'atlas' });

    fireEvent.change(screen.getByTestId('default-product-select'), { target: { value: '' } });

    await waitFor(() => expect(requests.filter((r) => r.method === 'PATCH').length).toBe(2));
    // Null and not an absent field: clearing is a decision, and an absent
    // field means "leave it alone".
    expect(requests.filter((r) => r.method === 'PATCH')[1]?.body).toEqual({ default_product: null });
  });

  it('is read-only for somebody who does not administer the organisation', async () => {
    renderWith(<OrganisationScreen />, clientFor({ ...SESSION, permissions: [] }).client);

    await waitFor(() => expect(screen.getByTestId('default-product-select')).toBeTruthy());
    expect(screen.getByTestId<HTMLSelectElement>('default-product-select').disabled).toBe(true);
  });
});

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
