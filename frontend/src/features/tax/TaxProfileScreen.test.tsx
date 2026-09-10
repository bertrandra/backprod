import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, SESSION, stubClient } from '@/test-utils';

import { TaxProfileScreen } from './TaxProfileScreen';

/**
 * A claim and a check are two different facts.
 *
 * `taxable_person` is what the tenant says about itself; `vat_number_status` is
 * what the registry answered. The tests below insist both reach the screen, that
 * UNAVAILABLE is not collapsed into "invalid", and that `reverse_charge_available`
 * is *read* rather than recomputed from the other two — recomputing would put the
 * fail-closed rule (R8) in a second place, and the copy in the browser would be
 * the one nobody updated.
 */
const READER = { ...SESSION, permissions: [...SESSION.permissions, 'tax.read'] };
const MANAGER = { ...READER, permissions: [...READER.permissions, 'tax.manage'] };

const PROFILE = {
  tenant_id: 't-1',
  customer_kind: 'B2B',
  country_code: 'FR',
  taxable_person: true,
  location_evidence: {},
  vat_number: 'FR12345678901',
  vat_number_status: 'VERIFIED',
  vat_number_verified_at: '2026-08-01T09:00:00Z',
  vat_number_country: 'FR',
  reverse_charge_available: true,
};

function clientFor(profile: Record<string, unknown>, session: unknown = MANAGER) {
  return stubClient({
    'GET /api/v1/me': { data: session },
    'GET /api/v1/tax/profile': { data: { profile } },
  });
}

describe('the verification state', () => {
  it('shows the number and what the registry concluded about it', async () => {
    renderWith(<TaxProfileScreen />, clientFor(PROFILE));

    await waitFor(() => expect(screen.getByTestId('vat-number').textContent).toBe('FR12345678901'));
    expect(screen.getByTestId('vat-status').textContent).toBe('Verified');
  });

  it('keeps UNAVAILABLE distinct from INVALID', async () => {
    renderWith(
      <TaxProfileScreen />,
      clientFor({
        ...PROFILE,
        vat_number_status: 'UNAVAILABLE',
        reverse_charge_available: false,
      }),
    );

    await waitFor(() => expect(screen.getByTestId('vat-status').textContent).toBe('Unavailable'));

    // The distinction is the point: "we asked and got no answer" is not a
    // refusal, and a screen that showed a red cross for both would tell
    // somebody their number was rejected when it was not.
    const explanation = screen.getByTestId('status-explanation').textContent ?? '';
    expect(explanation).toMatch(/gave no answer/i);
    expect(explanation).not.toMatch(/is not one of its own/i);
  });

  it('reads reverse charge from the backend rather than deriving it', async () => {
    // A taxable person with a verified number — the two facts a naive
    // derivation would multiply together — but the backend says no. The screen
    // must say no too, because the backend is where the fail-closed rule lives.
    renderWith(
      <TaxProfileScreen />,
      clientFor({ ...PROFILE, taxable_person: true, vat_number_status: 'VERIFIED', reverse_charge_available: false }),
    );

    await waitFor(() =>
      expect(screen.getByTestId('verification').getAttribute('data-reverse-charge')).toBe('false'),
    );
    expect(screen.getByTestId('reverse-charge').textContent).toMatch(/not available/i);
  });

  it('says a claim is not proof', async () => {
    renderWith(<TaxProfileScreen />, clientFor(PROFILE));

    await waitFor(() => expect(screen.getByLabelText(/taxable person/i)).toBeTruthy());
    expect(screen.getByText(/on its own it grants nothing/i)).toBeTruthy();
  });
});

describe('saving', () => {
  it('sends what was typed and shows what the check concluded', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: MANAGER },
      'GET /api/v1/tax/profile': { data: { profile: PROFILE } },
      'PUT /api/v1/tax/profile': {
        // Normalised and re-checked: the answer legitimately differs from the
        // input, and the screen must show the answer.
        data: {
          profile: {
            ...PROFILE,
            vat_number: 'FR99999999999',
            vat_number_status: 'INVALID',
            reverse_charge_available: false,
          },
        },
      },
    });

    renderWith(<TaxProfileScreen />, client);

    await waitFor(() => expect(screen.getByTestId('vat-number').textContent).toBe('FR12345678901'));

    fireEvent.change(screen.getByLabelText('VAT number'), {
      target: { value: 'FR 999 999 999 99' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(screen.getByTestId('vat-status').textContent).toBe('Invalid'));
    expect(screen.getByTestId('vat-number').textContent).toBe('FR99999999999');
    expect(requests.filter((request) => request.method === 'PUT')).toHaveLength(1);
  });

  it('sends an empty country as null rather than as an empty string', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: MANAGER },
      'GET /api/v1/tax/profile': { data: { profile: { ...PROFILE, country_code: null } } },
      'PUT /api/v1/tax/profile': { data: { profile: PROFILE } },
    });

    renderWith(<TaxProfileScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Country')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    const saves = () => requests.filter((request) => request.method === 'PUT');

    await waitFor(() => expect(saves()).toHaveLength(1));
    // Null, not '': a country code the API stores as an empty string is a
    // country code no rate lookup will ever match.
    expect(saves()[0]?.body).toMatchObject({ country_code: null });
  });

  it('refuses a country code that is not two letters, before sending it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: MANAGER },
      'GET /api/v1/tax/profile': { data: { profile: PROFILE } },
    });

    renderWith(<TaxProfileScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Country')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Country'), { target: { value: 'FRA' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/two letters/i));
    expect(requests.filter((request) => request.method === 'PUT')).toHaveLength(0);
  });
});

describe('without tax.manage', () => {
  it('shows the profile and disables editing it', async () => {
    renderWith(<TaxProfileScreen />, clientFor(PROFILE, READER));

    await waitFor(() => expect(screen.getByTestId('read-only')).toBeTruthy());
    expect(screen.getByTestId('vat-number').textContent).toBe('FR12345678901');
    // The `<fieldset disabled>` is what carries it — asserted on the fieldset
    // rather than on the input, because jsdom reflects only an element's own
    // `disabled` attribute and would answer false for every control a browser
    // actually disables here.
    const fieldset = screen.getByLabelText('VAT number').closest('fieldset');

    expect(fieldset).not.toBeNull();
    expect((fieldset as HTMLFieldSetElement).disabled).toBe(true);
    expect(screen.getByRole('button', { name: 'Save' }).closest('fieldset')).toBe(fieldset);
  });
});
