import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { TranslationsScreen } from './TranslationsScreen';

/**
 * The translation desk (2026-09-26).
 *
 * Three things are under test and only one of them is visible: that the filter
 * narrows on typing, that the English is beside every box, and — the one that
 * matters most — that saving one sentence does not delete another. The write
 * *replaces* the translation set, so a payload missing a language or a field is
 * a silent deletion, and it looks exactly like a successful save on screen.
 */
const FEATURE_NAME = {
  kind: 'feature',
  id: 'f-1',
  code: 'terrace_engine',
  product: null,
  field: 'name',
  source: 'Terrace engine',
  translations: { fr: 'Moteur de terrasse', es: 'Motor de terraza' },
};

const FEATURE_DESCRIPTION = {
  kind: 'feature',
  id: 'f-1',
  code: 'terrace_engine',
  product: null,
  field: 'description',
  source: 'Draws a terrace and works out what it costs.',
  // Nothing in French, which is the ordinary state of a catalogue somebody is
  // halfway through — and the row the desk exists to surface.
  translations: { es: 'Dibuja una terraza y calcula su coste.' },
};

const OFFER = {
  kind: 'offer',
  id: 'o-1',
  code: 'pro-monthly',
  product: 'plan',
  field: 'name',
  source: 'Terrace Pro monthly',
  translations: {},
};

const TEXTS = [FEATURE_NAME, FEATURE_DESCRIPTION, OFFER];

const ROUTE = { path: '/console/translations', initial: '/console/translations' } as const;

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
    'PATCH /api/v1/staff/features/{featureId}': { data: { feature: {} } },
    'PATCH /api/v1/staff/catalogue/offers/{offerId}': { data: { offer: {} } },
    ...extra,
  });
}

async function shown() {
  await waitFor(() => expect(screen.getByTestId('translation-list')).toBeTruthy());
}

describe('the desk', () => {
  it('asks once, unpaginated, and names no product', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    const reads = requests.filter((request) => request.path === '/api/v1/staff/translations');

    expect(reads).toHaveLength(1);
    // The one list here with no cursor: the whole point is to count what is
    // missing across the set, and a page could not answer that.
    expect(reads[0]?.query).toBeUndefined();
  });

  it('shows the English beside every box, and never as an input', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    expect(
      screen.getByTestId('translation-feature-terrace_engine-name-source').textContent,
    ).toContain('Terrace engine');

    // The key and the fallback. An input here would look like a translation and
    // behave like a rename of the row.
    expect(screen.queryByDisplayValue('Terrace engine')).toBeNull();
  });

  it('opens on a language that is not English', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    const chooser = screen.getByTestId<HTMLSelectElement>('translation-locale');

    expect(chooser.value).not.toBe('en');
    // English is not even offered: correcting it is a rename, not a translation.
    expect([...chooser.options].map((option) => option.value)).not.toContain('en');
  });

  it('marks a box the chosen language has nothing in', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    expect(
      screen
        .getByTestId('translation-feature-terrace_engine-name')
        .getAttribute('data-missing'),
    ).toBe('false');
    expect(
      screen
        .getByTestId('translation-feature-terrace_engine-description')
        .getAttribute('data-missing'),
    ).toBe('true');
  });

  it('names the product an offer belongs to, and no product for a feature', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    // An offer's code is unique only within a product, so two really can both
    // be `pro-monthly`.
    expect(screen.getByTestId('product-of-pro-monthly').textContent).toBe('plan');
    expect(screen.queryByTestId('product-of-terrace_engine')).toBeNull();
  });
});

describe('the filter', () => {
  it('narrows as it is typed, with no second request', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-search'), { target: { value: 'engine' } });

    await waitFor(() => expect(screen.queryByTestId('translate-offer-pro-monthly')).toBeNull());

    expect(screen.getByTestId('translate-feature-terrace_engine')).toBeTruthy();
    // Every sentence is already in hand, so a keystroke costs nothing.
    expect(requests.filter((request) => request.method === 'GET')).toHaveLength(1);
  });

  it('searches every language and not only the one on screen', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    // Spanish, while the desk is showing French: somebody who half-remembers a
    // word is looking for the row it is on.
    fireEvent.change(screen.getByTestId('translation-search'), { target: { value: 'terraza' } });

    await waitFor(() => expect(screen.queryByTestId('translate-offer-pro-monthly')).toBeNull());

    expect(screen.getByTestId('translate-feature-terrace_engine')).toBeTruthy();
  });

  it('keeps only what the chosen language is missing when asked', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    fireEvent.click(screen.getByTestId('translation-only-missing'));

    await waitFor(() =>
      expect(screen.queryByTestId('translation-feature-terrace_engine-name')).toBeNull(),
    );

    // The description has no French, and the offer has nothing at all.
    expect(screen.getByTestId('translation-feature-terrace_engine-description')).toBeTruthy();
    expect(screen.getByTestId('translation-offer-pro-monthly-name')).toBeTruthy();
  });

  it('counts the whole set, not the part being shown', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-search'), { target: { value: 'terraza' } });

    await waitFor(() =>
      expect(screen.getByTestId('translation-tally').textContent).toContain('of 3'),
    );

    // Two of three have no French. The number somebody came here for is how
    // much is left, which a count that shrank as they typed would not answer.
    expect(screen.getByTestId('translation-tally').textContent).toContain('2 without');
  });
});

describe('saving', () => {
  it('sends the whole set, so the other language is not deleted', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
      'PATCH /api/v1/staff/features/{featureId}': { data: { feature: {} } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-feature-terrace_engine-name'), {
      target: { value: 'Moteur de terrasse Pro' },
    });
    fireEvent.submit(screen.getByTestId('translate-feature-terrace_engine'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PATCH')).toBe(true));

    const write = requests.find((request) => request.method === 'PATCH');

    expect(write?.body).toEqual({
      name: 'Terrace engine',
      translations: {
        fr: { name: 'Moteur de terrasse Pro', description: null },
        // The operation replaces the set. Sending French alone would delete
        // this, and the screen would look as though it had saved.
        es: { name: 'Motor de terraza', description: 'Dibuja una terraza y calcula su coste.' },
      },
    });
  });

  it('leaves the English description alone rather than clearing it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
      'PATCH /api/v1/staff/features/{featureId}': { data: { feature: {} } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-feature-terrace_engine-description'), {
      target: { value: 'Dessine une terrasse.' },
    });
    fireEvent.submit(screen.getByTestId('translate-feature-terrace_engine'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PATCH')).toBe(true));

    const body = requests.find((request) => request.method === 'PATCH')?.body as
      | Record<string, unknown>
      | undefined;

    // Absent, not null: null would clear the English, and this screen does not
    // edit the English at all.
    expect(body).not.toHaveProperty('description');
    expect(body?.name).toBe('Terrace engine');
  });

  it('carries the product an offer is written in', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/translations': { data: { texts: TEXTS } },
      'PATCH /api/v1/staff/catalogue/offers/{offerId}': { data: { offer: {} } },
    });

    renderAtRoute(<TranslationsScreen />, client, ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-offer-pro-monthly-name'), {
      target: { value: 'Terrasse Pro mensuel' },
    });
    fireEvent.submit(screen.getByTestId('translate-offer-pro-monthly'));

    await waitFor(() => expect(requests.some((request) => request.method === 'PATCH')).toBe(true));

    const write = requests.find((request) => request.method === 'PATCH');

    // A staff route resolves no product of its own — a platform role grants no
    // membership — so the product is named in the request or the write is
    // refused.
    expect(write?.query).toEqual({ product: 'plan' });
    expect(write?.body).toEqual({
      name: 'Terrace Pro monthly',
      translations: { fr: { name: 'Terrasse Pro mensuel' } },
    });
  });

  it('offers nothing to save until something is typed', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    const save = screen.getByTestId<HTMLButtonElement>('save-offer-pro-monthly');

    expect(save.disabled).toBe(true);

    fireEvent.change(screen.getByTestId('translation-offer-pro-monthly-name'), {
      target: { value: 'Terrasse Pro mensuel' },
    });

    await waitFor(() => expect(save.disabled).toBe(false));
  });

  it('drops what was typed when the language changes', async () => {
    renderAtRoute(<TranslationsScreen />, clientFor(), ROUTE);
    await shown();

    fireEvent.change(screen.getByTestId('translation-offer-pro-monthly-name'), {
      target: { value: 'Terrasse Pro mensuel' },
    });
    fireEvent.change(screen.getByTestId('translation-locale'), { target: { value: 'es' } });

    // Kept, a French draft would show under a Spanish label and save as
    // Spanish — a translation filed against the wrong language reads as
    // correct on screen and wrong to every customer.
    await waitFor(() =>
      expect(
        screen.getByTestId<HTMLInputElement>('translation-offer-pro-monthly-name').value,
      ).toBe(''),
    );
  });

  it('says so when the English was cleared and a translation was left behind', async () => {
    renderAtRoute(
      <TranslationsScreen />,
      clientFor({
        'GET /api/v1/staff/translations': {
          data: {
            texts: [
              FEATURE_NAME,
              { ...FEATURE_DESCRIPTION, source: '', translations: { fr: 'Dessine une terrasse.' } },
            ],
          },
        },
      }),
      ROUTE,
    );
    await shown();

    // Listed rather than hidden: hiding it would hide the inconsistency, and
    // would hand the next save a set with the French missing.
    expect(
      screen.getByTestId('translation-feature-terrace_engine-description-no-source'),
    ).toBeTruthy();
  });
});
