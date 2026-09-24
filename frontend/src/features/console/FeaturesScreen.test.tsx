import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { FeaturesScreen } from './FeaturesScreen';

/**
 * The platform's one list of features (2026-09-24).
 *
 * What is under test is the absence of a product as much as anything on
 * screen: a feature is a word the platform and a product's code have agreed
 * on, so no request from here names one. `max_projects` existed once per
 * product until this screen, and the code reading it depended on every one
 * of those rows having been spelled the same.
 */
const QUOTA = {
  id: 'f-1',
  code: 'projects',
  name: 'Projects',
  description: null,
  kind: 'QUOTA',
  unit: 'projects',
  active: true,
  // What the operator wrote in the other languages. One filled and three
  // empty is the ordinary state of a catalogue, and the screen has to read
  // as ordinary in it.
  translations: { fr: { name: 'Projets', description: null } },
};

const RETIRED = {
  id: 'f-2',
  code: 'legacy_export',
  name: 'Legacy export',
  description: null,
  kind: 'BOOLEAN',
  unit: null,
  active: false,
  translations: {},
};

const ROUTE = {
  path: '/console/features',
  initial: '/console/features',
} as const;

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/features': { data: { features: [QUOTA] } },
    'POST /api/v1/staff/features': { status: 201, data: { feature: QUOTA } },
    'PATCH /api/v1/staff/features/{featureId}': { data: { feature: QUOTA } },
    ...extra,
  });
}

describe('the list', () => {
  it('asks for no product, because a feature belongs to none', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/features': { data: { features: [QUOTA] } },
    });

    renderAtRoute(<FeaturesScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByTestId('feature-list')).toBeTruthy());

    const read = requests.find((r) => r.path === '/api/v1/staff/features');

    expect(read).toBeTruthy();
    // Not "the product happens to be undefined here": the operation takes no
    // product at all, and a screen that sent one would be describing a
    // scoping this list does not have.
    expect(read?.path).not.toContain('product');
  });

  it('says the kind cannot be changed afterwards', async () => {
    renderAtRoute(<FeaturesScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('feature-list')).toBeTruthy());

    expect(screen.getByText(/cannot be changed afterwards/i)).toBeTruthy();
    expect(screen.getByText(/reinterpret prices somebody is already paying/i)).toBeTruthy();
  });

  it('shows a retired feature rather than hiding it, because its code is still taken', async () => {
    renderAtRoute(
      <FeaturesScreen />,
      clientFor({ 'GET /api/v1/staff/features': { data: { features: [QUOTA, RETIRED] } } }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByTestId('retired-legacy_export')).toBeTruthy());

    // And offers to put it back, which is the other half of retiring not
    // being a delete.
    expect(screen.getByTestId('retire-legacy_export').textContent).toContain('Reinstate');
  });

  it('offers no way to delete one', async () => {
    renderAtRoute(<FeaturesScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('feature-list')).toBeTruthy());

    // Entitlements rest on these rows, and they are what customers pay for.
    expect(screen.queryByRole('button', { name: /delete/i })).toBeNull();
  });
});

describe('creating', () => {
  it('refuses to send a unit on a switch', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/features': { data: { features: [] } },
      'POST /api/v1/staff/features': { status: 201, data: { feature: QUOTA } },
    });

    renderAtRoute(<FeaturesScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByLabelText('Feature code')).toBeTruthy());

    fireEvent.change(screen.getByLabelText('Feature code'), { target: { value: 'api' } });
    fireEvent.change(screen.getByLabelText('Feature name'), { target: { value: 'API access' } });
    fireEvent.change(screen.getByLabelText('Kind'), { target: { value: 'BOOLEAN' } });

    // The field is disabled rather than hidden, so the rule is visible instead
    // of being discovered through a 400.
    expect(screen.getByLabelText<HTMLInputElement>('Unit').disabled).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Add feature' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'POST')).toBe(true));

    expect(requests.find((r) => r.method === 'POST')?.body).toEqual({
      code: 'api',
      name: 'API access',
      kind: 'BOOLEAN',
      unit: null,
    });
  });
});

describe('correcting', () => {
  it('renames a feature in one language without losing the others', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/features': { data: { features: [QUOTA] } },
      'PATCH /api/v1/staff/features/{featureId}': { data: { feature: QUOTA } },
    });

    renderAtRoute(<FeaturesScreen />, client, ROUTE);

    fireEvent.click(await screen.findByTestId('rename-projects'));

    // The field opens on the reader's language — English in a test — and
    // the dots say which languages say something without opening anything.
    const written = screen.getByTestId('written-in-feature-name-projects');
    expect(written.querySelector('[data-locale="fr"]')?.getAttribute('data-written')).toBe('true');
    expect(written.querySelector('[data-locale="de"]')?.getAttribute('data-written')).toBe('false');

    // Correct the German, leave the French alone.
    fireEvent.click(screen.getByTestId('language-of-feature-name-projects'));
    fireEvent.click(screen.getByTestId('language-de-of-feature-name-projects'));
    fireEvent.change(screen.getByTestId('translated-feature-name-projects'), {
      target: { value: 'Projekte' },
    });

    fireEvent.submit(screen.getByTestId('rename-form-projects'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));

    // The English and every translation travel together: the server
    // replaces the set, so a language left out of this body is one it
    // removes — and the French must therefore still be in it.
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({
      name: 'Projects',
      description: null,
      translations: {
        fr: { name: 'Projets', description: null },
        de: { name: 'Projekte', description: null },
      },
    });
  });

  it('retires without touching the wording', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/features': { data: { features: [QUOTA] } },
      'PATCH /api/v1/staff/features/{featureId}': { data: { feature: { ...QUOTA, active: false } } },
    });

    renderAtRoute(<FeaturesScreen />, client, ROUTE);

    fireEvent.click(await screen.findByTestId('retire-projects'));

    await waitFor(() => expect(requests.some((r) => r.method === 'PATCH')).toBe(true));

    // No translations in the body: retiring is a decision about what may be
    // sold, and a call that also replaced the translation set would erase
    // whatever nobody had reopened the form to retype.
    expect(requests.find((r) => r.method === 'PATCH')?.body).toEqual({
      name: 'Projects',
      active: false,
    });
  });
});
