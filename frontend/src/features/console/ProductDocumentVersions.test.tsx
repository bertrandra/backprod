import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient } from '@/test-utils';

import { ProductDocumentVersions } from './ProductDocumentVersions';

/**
 * The document versions a product accepts, on the product's own row.
 *
 * These cases lived in `InvoicingScreen.test.tsx` until 2026-11-20, when the
 * form moved to where an operator looks for it. What they prove is unchanged:
 * the list is sent whole, nothing is added that the server would refuse, and
 * what the product declares is proposed and never applied.
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

/**
 * What a product with no address of its own answers: it runs inside this shell,
 * so there is nowhere to ask. The default here because it is what the console's
 * own `POST /staff/products` makes, and what every case below is really about.
 */
const NOT_ASKED = {
  'GET /api/v1/staff/configuration/product-manifest': {
    data: {
      product: { ...PRODUCT, app_url: null },
      declared: null,
      error: 'NO_ADDRESS',
      project_schema_versions: [1],
      adds: [],
    },
  },
};

/** The same answer from a product that does run beside the platform. */
function declaring(declared: number[], stored: number[], adds: number[], error: string | null = null) {
  return {
    'GET /api/v1/staff/configuration/product-manifest': {
      data: {
        product: { ...PRODUCT, app_url: 'https://atlas.example.test' },
        declared: error === null ? { app_version: '2.3.0', schema_versions: declared } : null,
        error,
        project_schema_versions: stored,
        adds,
      },
    },
  };
}

const ROUTE = {
  path: '/console/products',
  initial: '/console/products',
} as const;

describe('the accepted document versions', () => {
  function configuredWith(versions: number[]) {
    return {
      'GET /api/v1/staff/configuration': {
        data: {
          product: PRODUCT,
          billing_supplier: CONFIGURED_SUPPLIER,
          tax: TAX,
          project_schema_versions: versions,
          renewal: { automatic: false, lead_days: 7 },
          can_invoice: true,
          missing: [],
        },
      },
      'PUT /api/v1/staff/configuration/project-schema-versions': {
        data: { project_schema_versions: versions },
      },
      ...NOT_ASKED,
    };
  }

  it('warns when the product accepts none, because that is every product the console made', async () => {
    renderAtRoute(<ProductDocumentVersions productCode="atlas" />, stubClient(configuredWith([])), ROUTE);

    const warning = await waitFor(() => screen.getByTestId('accepts-no-version'));

    // The code somebody read in a log before opening this screen, and an alert
    // rather than a colour: an empty list looks exactly like a form that has
    // finished loading.
    expect(warning.textContent).toMatch(/UNSUPPORTED_SCHEMA_VERSION/);
    expect(warning.getAttribute('role')).toBe('alert');
  });

  it('cannot be saved empty, as a courtesy — the server refuses it either way', async () => {
    renderAtRoute(<ProductDocumentVersions productCode="atlas" />, stubClient(configuredWith([])), ROUTE);

    const save = await waitFor(() =>
      screen.getByRole('button', { name: /Save the accepted versions/i }),
    );

    expect((save as HTMLButtonElement).disabled).toBe(true);
  });

  it('sends the whole list, so that retiring a version is possible at all', async () => {
    const { client, requests } = recordingClient(configuredWith([1, 2]));

    renderAtRoute(<ProductDocumentVersions productCode="atlas" />, client, ROUTE);

    await waitFor(() => expect(screen.getByTestId('schema-versions')).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: 'Remove version 1' }));
    fireEvent.click(screen.getByRole('button', { name: /Save the accepted versions/i }));

    await waitFor(() =>
      expect(
        requests.some(
          (request) => request.path === '/api/v1/staff/configuration/project-schema-versions',
        ),
      ).toBe(true),
    );

    const sent = requests.find(
      (request) => request.path === '/api/v1/staff/configuration/project-schema-versions',
    );

    // Not a patch naming what to remove: the list is one answer, and under
    // "omitted means leave it" nothing could ever be taken away.
    expect(sent?.body).toEqual({ supported: [2] });
  });

  it('adds a version in ascending order and never twice', async () => {
    const { client, requests } = recordingClient(configuredWith([2]));

    renderAtRoute(<ProductDocumentVersions productCode="atlas" />, client, ROUTE);

    const entry = await waitFor(() => screen.getByLabelText<HTMLInputElement>('Add a version'));

    fireEvent.change(entry, { target: { value: '2' } });
    // Already in the list, so there is nothing to add.
    expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Add' }).disabled).toBe(true);

    fireEvent.change(entry, { target: { value: '1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add' }));
    fireEvent.click(screen.getByRole('button', { name: /Save the accepted versions/i }));

    await waitFor(() =>
      expect(
        requests.some(
          (request) => request.path === '/api/v1/staff/configuration/project-schema-versions',
        ),
      ).toBe(true),
    );

    const sent = requests.find(
      (request) => request.path === '/api/v1/staff/configuration/project-schema-versions',
    );

    expect(sent?.body).toEqual({ supported: [1, 2] });
  });

  it('refuses to add what the server would refuse, so no product displays a version it rejects', async () => {
    renderAtRoute(<ProductDocumentVersions productCode="atlas" />, stubClient(configuredWith([1])), ROUTE);

    const entry = await waitFor(() => screen.getByLabelText<HTMLInputElement>('Add a version'));
    const add = () => screen.getByRole<HTMLButtonElement>('button', { name: 'Add' });

    for (const rejected of ['0', '-1', '2.5', '']) {
      fireEvent.change(entry, { target: { value: rejected } });
      expect(add().disabled).toBe(true);
    }

    fireEvent.change(entry, { target: { value: '3' } });
    expect(add().disabled).toBe(false);
  });
});

describe('what the product says about itself', () => {
  function configured(versions: number[]) {
    return {
      'GET /api/v1/staff/configuration': {
        data: {
          product: PRODUCT,
          billing_supplier: CONFIGURED_SUPPLIER,
          tax: TAX,
          project_schema_versions: versions,
          renewal: { automatic: false, lead_days: 7 },
          can_invoice: true,
          missing: [],
        },
      },
      'PUT /api/v1/staff/configuration/project-schema-versions': {
        data: { project_schema_versions: versions },
      },
    };
  }

  it('shows what the product declares beside what is stored', async () => {
    renderAtRoute(
      <ProductDocumentVersions productCode="atlas" />,
      stubClient({ ...configured([1]), ...declaring([1, 2, 3], [1], [2, 3]) }),
      ROUTE,
    );

    const panel = await waitFor(() => screen.getByTestId('product-manifest'));

    expect(screen.getByTestId('declared-versions').textContent).toBe('1, 2, 3');
    // The release, so somebody can tell a stale answer from a current one.
    expect(panel.textContent).toMatch(/2\.3\.0/);
    expect(panel.textContent).toMatch(/does not cover all of it/i);
  });

  it('proposes and never applies, so what is stored is what the operator could see', async () => {
    const { client, requests } = recordingClient({
      ...configured([1]),
      ...declaring([1, 2, 3], [1], [2, 3]),
    });

    renderAtRoute(<ProductDocumentVersions productCode="atlas" />, client, ROUTE);

    fireEvent.click(await waitFor(() => screen.getByTestId('adopt-declared-versions')));

    // Nothing has been sent. The versions are in the list on screen, and the
    // form's own Save is still what writes them — a remote file may propose a
    // list and must not decide one.
    expect(
      requests.some(
        (request) => request.path === '/api/v1/staff/configuration/project-schema-versions',
      ),
    ).toBe(false);

    await waitFor(() =>
      expect(screen.getByTestId('schema-versions').textContent).toMatch(/1.*2.*3/s),
    );

    fireEvent.click(screen.getByRole('button', { name: /Save the accepted versions/i }));

    await waitFor(() =>
      expect(
        requests.find(
          (request) => request.path === '/api/v1/staff/configuration/project-schema-versions',
        )?.body,
      ).toEqual({ supported: [1, 2, 3] }),
    );
  });

  it('stops offering what is already in the list, so pressing twice cannot duplicate it', async () => {
    // `adds` is what the *server* said was missing, and it does not change when
    // the operator adds a version here — the list on screen is unsaved. A panel
    // reading it alone would keep the button, and a second press would append
    // the same versions again.
    renderAtRoute(
      <ProductDocumentVersions productCode="atlas" />,
      stubClient({ ...configured([1]), ...declaring([1, 2, 3], [1], [2, 3]) }),
      ROUTE,
    );

    fireEvent.click(await waitFor(() => screen.getByTestId('adopt-declared-versions')));

    await waitFor(() => expect(screen.queryByTestId('adopt-declared-versions')).toBeNull());
    expect(screen.getAllByRole('listitem').map((item) => item.getAttribute('data-version'))).toEqual(
      ['1', '2', '3'],
    );
  });

  it('offers nothing when the stored list already covers what the product declares', async () => {
    renderAtRoute(
      <ProductDocumentVersions productCode="atlas" />,
      stubClient({ ...configured([1, 2, 3, 4]), ...declaring([1, 2, 3], [1, 2, 3, 4], []) }),
      ROUTE,
    );

    const panel = await waitFor(() => screen.getByTestId('product-manifest'));

    expect(panel.textContent).toMatch(/already covers it/i);
    expect(screen.queryByTestId('adopt-declared-versions')).toBeNull();
  });

  it('treats a product that says nothing as ordinary, not as a failure', async () => {
    // Most products serve no manifest, and a single-page app answers its index
    // for every path. A red failure for each of those teaches an operator to
    // ignore the one that matters.
    renderAtRoute(
      <ProductDocumentVersions productCode="atlas" />,
      stubClient({ ...configured([1]), ...declaring([], [1], [], 'NOT_SERVED') }),
      ROUTE,
    );

    const said = await waitFor(() => screen.getByTestId('product-declares-nothing'));

    expect(said.textContent).toMatch(/does not say which versions it accepts/i);
    expect(said.className).not.toMatch(/danger/);
  });

  it('calls out a manifest that names another product, because an address is pointing at somebody else', async () => {
    renderAtRoute(
      <ProductDocumentVersions productCode="atlas" />,
      stubClient({ ...configured([1]), ...declaring([], [1], [], 'WRONG_PRODUCT') }),
      ROUTE,
    );

    const said = await waitFor(() => screen.getByTestId('product-declares-nothing'));

    expect(said.textContent).toMatch(/different product/i);
    // The address, so it is visible which host answered.
    expect(said.textContent).toMatch(/atlas\.example\.test/);
    expect(said.className).toMatch(/danger/);
  });
});
