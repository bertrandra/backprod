import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { InvoicingScreen } from './InvoicingScreen';

/**
 * The screen that closed the last gap between a console-built product and money.
 *
 * What it owes is narrow: say plainly when a product cannot invoice and **name
 * the fields**, because the person who has to fix it should not be comparing a
 * form against a specification; and send the whole identity every time, because
 * the endpoint is a PUT and clearing a field is the same act as changing one.
 */
const PRODUCT = { id: 'p-1', code: 'atlas', name: 'Atlas' };

const EMPTY_SUPPLIER = {
  legal_name: null,
  vat_number: null,
  registration_number: null,
  address_line1: null,
  address_line2: null,
  postal_code: null,
  city: null,
  country_code: null,
};

const CONFIGURED_SUPPLIER = {
  ...EMPTY_SUPPLIER,
  legal_name: 'Atlas SAS',
  vat_number: 'FR12345678901',
  city: 'Paris',
  country_code: 'FR',
};

const TAX = {
  country: 'FR',
  oss_registered: true,
  supply_type: 'DIGITAL_SERVICES',
  currency: 'EUR',
};

const ROUTE = {
  path: '/console/invoicing',
  initial: '/console/invoicing',
} as const;

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/configuration': {
      data: {
        product: PRODUCT,
        billing_supplier: EMPTY_SUPPLIER,
        tax: TAX,
        can_invoice: false,
        missing: ['legal_name', 'country_code'],
      },
    },
    'PUT /api/v1/staff/configuration/billing-identity': {
      data: { billing_supplier: CONFIGURED_SUPPLIER },
    },
    'PUT /api/v1/staff/configuration/tax': { data: { tax: TAX } },
    ...extra,
  });
}

describe('without a product', () => {
  it('says where to pick one, because the console has no ambient product', async () => {
    renderAtRoute(<InvoicingScreen />, clientFor(), { path: '/console/invoicing', product: null });

    await waitFor(() => expect(screen.getByText(/No product chosen/i)).toBeTruthy());
    expect(screen.getByRole('link', { name: /Go to Products/i })).toBeTruthy();
  });
});

describe('a product that cannot invoice', () => {
  it('says so, names the code a checkout would refuse with, and lists the fields', async () => {
    renderAtRoute(<InvoicingScreen />, clientFor(), ROUTE);

    const warning = await waitFor(() => screen.getByTestId('cannot-invoice'));

    // The error code, because that is what somebody read in a log before
    // opening this screen.
    expect(warning.textContent).toMatch(/BILLING_NOT_CONFIGURED/);
    // And the fields, not only that something is wrong.
    expect(warning.textContent).toMatch(/legal_name, country_code/);
  });

  it('announces the warning rather than only colouring it', async () => {
    renderAtRoute(<InvoicingScreen />, clientFor(), ROUTE);

    const warning = await waitFor(() => screen.getByTestId('cannot-invoice'));

    expect(warning.getAttribute('role')).toBe('alert');
  });
});

describe('a product that can invoice', () => {
  it('says that changing the identity never rewrites an invoice already raised', async () => {
    renderAtRoute(
      <InvoicingScreen />,
      clientFor({
        'GET /api/v1/staff/configuration': {
          data: {
            product: PRODUCT,
            billing_supplier: CONFIGURED_SUPPLIER,
            tax: TAX,
            can_invoice: true,
            missing: [],
          },
        },
      }),
      ROUTE,
    );

    const note = await waitFor(() => screen.getByTestId('can-invoice'));

    // The snapshot rule, said where somebody is about to change the thing it
    // applies to.
    expect(note.textContent).toMatch(/as it stood at that moment/i);
  });

  it('fills the form from what is stored', async () => {
    renderAtRoute(
      <InvoicingScreen />,
      clientFor({
        'GET /api/v1/staff/configuration': {
          data: {
            product: PRODUCT,
            billing_supplier: CONFIGURED_SUPPLIER,
            tax: TAX,
            can_invoice: true,
            missing: [],
          },
        },
      }),
      ROUTE,
    );

    const legalName = await waitFor(() => screen.getByLabelText<HTMLInputElement>('Legal name'));

    expect(legalName.value).toBe('Atlas SAS');
    expect(screen.getByLabelText<HTMLInputElement>('VAT number').value).toBe('FR12345678901');
  });
});

describe('setting the issuer', () => {
  it('sends every field, including the ones left empty', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/configuration': {
        data: {
          product: PRODUCT,
          billing_supplier: EMPTY_SUPPLIER,
          tax: TAX,
          can_invoice: false,
          missing: ['legal_name', 'country_code'],
        },
      },
      'PUT /api/v1/staff/configuration/billing-identity': {
        data: { billing_supplier: CONFIGURED_SUPPLIER },
      },
    });

    renderAtRoute(<InvoicingScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Legal name')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Legal name'), { target: { value: '  Atlas SAS  ' } });
    fireEvent.change(screen.getByLabelText('Country'), { target: { value: 'FR' } });
    fireEvent.click(screen.getByRole('button', { name: /Save the issuer/i }));

    await waitFor(() =>
      expect(
        requests.some((request) => request.path === '/api/v1/staff/configuration/billing-identity'),
      ).toBe(true),
    );

    const sent = requests.find(
      (request) => request.path === '/api/v1/staff/configuration/billing-identity',
    );

    // The whole document. Under a PATCH-shaped body an omitted field would mean
    // "leave it", and a supplier that stopped being liable for VAT could never
    // remove its number.
    expect(sent?.body).toEqual({
      legal_name: 'Atlas SAS',
      vat_number: null,
      registration_number: null,
      address_line1: null,
      address_line2: null,
      postal_code: null,
      city: null,
      country_code: 'FR',
    });

    expect(sent?.query).toEqual({ product: 'atlas' });
  });

  it('clears a field by sending null rather than an empty string', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/configuration': {
        data: {
          product: PRODUCT,
          billing_supplier: CONFIGURED_SUPPLIER,
          tax: TAX,
          can_invoice: true,
          missing: [],
        },
      },
      'PUT /api/v1/staff/configuration/billing-identity': {
        data: { billing_supplier: { ...CONFIGURED_SUPPLIER, vat_number: null } },
      },
    });

    renderAtRoute(<InvoicingScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('VAT number')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('VAT number'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: /Save the issuer/i }));

    await waitFor(() => expect(requests.length).toBeGreaterThan(1));

    const sent = requests.find(
      (request) => request.path === '/api/v1/staff/configuration/billing-identity',
    );

    expect((sent?.body as { vat_number?: unknown } | undefined)?.vat_number).toBeNull();
  });
});

describe('the tax position', () => {
  it('sends all four fields, because omitting the flag would switch OSS off', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/configuration': {
        data: {
          product: PRODUCT,
          billing_supplier: CONFIGURED_SUPPLIER,
          tax: { country: 'FR', oss_registered: false, supply_type: 'SERVICES', currency: 'EUR' },
          can_invoice: true,
          missing: [],
        },
      },
      'PUT /api/v1/staff/configuration/tax': { data: { tax: TAX } },
    });

    renderAtRoute(<InvoicingScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Jurisdiction')).toBeTruthy());

    fireEvent.click(screen.getByLabelText(/One Stop Shop/i));
    fireEvent.click(screen.getByRole('button', { name: /Save the tax position/i }));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/staff/configuration/tax')).toBe(
        true,
      ),
    );

    const sent = requests.find((request) => request.path === '/api/v1/staff/configuration/tax');

    // Read back from the form, flag included and flipped by the click.
    expect(sent?.body).toEqual({
      country: 'FR',
      currency: 'EUR',
      supply_type: 'SERVICES',
      oss_registered: true,
    });
  });

  it('offers the jurisdiction by name and sends its ISO code', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/configuration': {
        data: {
          product: PRODUCT,
          billing_supplier: CONFIGURED_SUPPLIER,
          tax: TAX,
          can_invoice: true,
          missing: [],
        },
      },
      'PUT /api/v1/staff/configuration/tax': { data: { tax: TAX } },
    });

    renderAtRoute(<InvoicingScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Jurisdiction')).toBeTruthy());

    // A picker, not a two-letter box: "Belgium (BE)" is what is read, BE is
    // what is sent, and there is no lowercase or three-letter code to refuse.
    const jurisdiction = screen.getByLabelText<HTMLSelectElement>('Jurisdiction');
    expect(jurisdiction.tagName).toBe('SELECT');
    expect([...jurisdiction.options].map((o) => o.value)).toContain('BE');
    fireEvent.change(jurisdiction, { target: { value: 'BE' } });
    expect(jurisdiction.selectedOptions[0]?.textContent).toMatch(/\(BE\)$/);
    fireEvent.click(screen.getByRole('button', { name: /Save the tax position/i }));

    await waitFor(() =>
      expect(requests.some((request) => request.path === '/api/v1/staff/configuration/tax')).toBe(
        true,
      ),
    );

    const sent = requests.find((request) => request.path === '/api/v1/staff/configuration/tax');

    expect((sent?.body as { country?: unknown } | undefined)?.country).toBe('BE');
  });
});
