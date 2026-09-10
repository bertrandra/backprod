import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { BillingProfileScreen } from './BillingProfileScreen';

/**
 * The legal identity, and the assumption it has to correct.
 *
 * The profile is **copied onto each document at issue**, so changing it never
 * rewrites an invoice already sent. That is natural to get wrong — somebody
 * fixing a typo in their company name would reasonably expect last quarter's
 * invoices to change — so the screen says it, and this asserts that it does.
 */
const BILLER = { ...SESSION, permissions: [...SESSION.permissions, 'billing.read', 'billing.manage'] };

const PROFILE = {
  legal_name: 'Acme Ltd',
  vat_number: 'GB999',
  registration_number: null,
  address_line1: '1 High Street',
  address_line2: null,
  postal_code: 'LS1 1AA',
  city: 'Leeds',
  country_code: 'GB',
  billing_email: 'billing@acme.test',
};

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: BILLER },
    'GET /api/v1/billing/profile': { data: { profile: PROFILE } },
    ...extra,
  });
}

describe('the profile', () => {
  it('is filled from the server once it arrives', async () => {
    renderWith(<BillingProfileScreen />, clientFor());

    await waitFor(() =>
      expect(screen.getByLabelText<HTMLInputElement>(/legal name/i).value).toBe('Acme Ltd'),
    );
    expect(screen.getByLabelText<HTMLInputElement>(/^city$/i).value).toBe('Leeds');
  });

  it('says a change does not rewrite an invoice already sent', async () => {
    renderWith(<BillingProfileScreen />, clientFor());

    await waitFor(() => expect(screen.getByText(/never one already sent/i)).toBeTruthy());
  });

  it('sends an empty optional field as nothing, not as an empty string', async () => {
    let sent: Record<string, unknown> | null = null;

    renderWith(
      <BillingProfileScreen />,
      clientFor({
        'PUT /api/v1/billing/profile': (): Stub => {
          sent = { seen: true };

          return { data: { profile: PROFILE } };
        },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText(/legal name/i)).toBeTruthy());

    // A document with `address_line2: ""` prints a blank line; a missing one
    // prints nothing.
    fireEvent.change(screen.getByLabelText(/address, continued/i), { target: { value: '   ' } });
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => expect(sent).not.toBeNull());
    await waitFor(() => expect(screen.getByTestId('saved')).toBeTruthy());
  });

  it('refuses a legal name that is empty — it is what appears on the invoice', async () => {
    let saved = 0;

    renderWith(
      <BillingProfileScreen />,
      clientFor({
        'PUT /api/v1/billing/profile': (): Stub => {
          saved += 1;

          return { data: { profile: PROFILE } };
        },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText(/legal name/i)).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/legal name/i), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => expect(screen.getByText(/appears on the invoice/i)).toBeTruthy());
    expect(saved).toBe(0);
  });

  it('refuses a country code that is not two letters', async () => {
    renderWith(<BillingProfileScreen />, clientFor());

    await waitFor(() => expect(screen.getByLabelText(/^country$/i)).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/^country$/i), { target: { value: 'GBR' } });
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    // The code the tax rules are looked up by — a wrong one is a wrong rate.
    await waitFor(() => expect(screen.getByText(/ISO 3166/i)).toBeTruthy());
  });
});
