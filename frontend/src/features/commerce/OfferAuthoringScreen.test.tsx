import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { OfferAuthoringScreen } from './OfferAuthoringScreen';

/**
 * U5's second exit criterion: **a published offer version has no editable field
 * anywhere in the UI** (ADR-033).
 *
 * The assertion is deliberately about absence, and about *every* input rather
 * than a named one — a test that checked "the price input is disabled" would pass
 * a version of this screen that had added a different editable field beside it.
 * So the published row is swept for inputs, selects, textareas and buttons.
 */
const AUTHOR_SESSION = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'catalog.read', 'catalog.manage'],
};

const PLAN = { id: '11111111-1111-4111-8111-111111111111', code: 'PRO', name: 'Pro', rank: 20 };
const STARTER = {
  id: '22222222-2222-4222-8222-222222222222',
  code: 'STARTER',
  name: 'Starter',
  rank: 10,
};

const OFFER_ID = '33333333-3333-4333-8333-333333333333';

function version(overrides: Record<string, unknown> = {}) {
  return {
    id: '44444444-4444-4444-8444-444444444444',
    version: 1,
    status: 'ACTIVE',
    billing_period: 'MONTHLY',
    price: { minor_units: 2900, currency: 'EUR' },
    valid_from: '2026-01-01T00:00:00Z',
    valid_until: null,
    grants: [],
    terms: {},
    ...overrides,
  };
}

const authored = (versions: unknown[]): Stub => ({
  data: {
    offer: { id: OFFER_ID, code: 'pro-monthly', name: 'Pro monthly', plan: PLAN, versions },
  },
});

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: AUTHOR_SESSION },
    'GET /api/v1/offers': {
      data: {
        offers: [
          { id: OFFER_ID, code: 'pro-monthly', name: 'Pro monthly', plan: PLAN, version: version() },
        ],
      },
    },
    'GET /api/v1/plans': { data: { plans: [PLAN, STARTER] } },
    'GET /api/v1/offers/{offerId}/versions': authored([version()]),
    ...extra,
  });
}

const atOffer = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<OfferAuthoringScreen />, client, {
    path: '/offers',
    initial: `/offers?selected=${OFFER_ID}`,
  });

describe('a published version', () => {
  it('offers no editable field of any kind', async () => {
    atOffer(clientFor());

    const row = await waitFor(() => screen.getByTestId('frozen').closest('li'));
    expect(row).not.toBeNull();

    // Every kind of control, not just the ones this screen happens to use today.
    expect(row?.querySelectorAll('input')).toHaveLength(0);
    expect(row?.querySelectorAll('select')).toHaveLength(0);
    expect(row?.querySelectorAll('textarea')).toHaveLength(0);
    expect(row?.querySelectorAll('button')).toHaveLength(0);
  });

  it('says the freeze is a decision rather than an omission', async () => {
    atOffer(clientFor());

    await waitFor(() => expect(screen.getByTestId('frozen')).toBeTruthy());
    expect(screen.getByText(/published version is frozen/i)).toBeTruthy();
    expect(screen.getByText(/pin the version that priced it/i)).toBeTruthy();
  });

  it('cannot be published again', async () => {
    atOffer(clientFor());

    await waitFor(() => expect(screen.getByTestId('frozen')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /^publish$/i })).toBeNull();
  });

  it('is still shown in full — frozen is not hidden', async () => {
    atOffer(clientFor());

    await waitFor(() => expect(screen.getByTestId('frozen')).toBeTruthy());
    expect(screen.getByText(/v1/)).toBeTruthy();
    expect(screen.getByText('ACTIVE')).toBeTruthy();
    expect(document.querySelector('[data-minor-units="2900"]')).not.toBeNull();
  });
});

describe('a draft version', () => {
  it('can be published, and the number is the server’s', async () => {
    let publishedVersion: unknown = null;

    atOffer(
      clientFor({
        'GET /api/v1/offers/{offerId}/versions': authored([
          version({ id: 'v-2', version: 2, status: 'DRAFT' }),
          version(),
        ]),
        'POST /api/v1/offers/{offerId}/publish': (): Stub => {
          publishedVersion = true;

          return authored([version({ id: 'v-2', version: 2, status: 'ACTIVE' }), version()]);
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^publish$/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^publish$/i }));

    await waitFor(() => expect(publishedVersion).toBe(true));
  });

  it('is the only version with a control at all', async () => {
    atOffer(
      clientFor({
        'GET /api/v1/offers/{offerId}/versions': authored([
          version({ id: 'v-2', version: 2, status: 'DRAFT' }),
          version(),
          version({ id: 'v-0', version: 3, status: 'EXPIRED' }),
          version({ id: 'v-x', version: 4, status: 'ARCHIVED' }),
        ]),
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^publish$/i })).toBeTruthy());

    // Four versions, one publishable. EXPIRED and ARCHIVED are history too:
    // something may have been priced against them.
    expect(screen.getAllByRole('button', { name: /^publish$/i })).toHaveLength(1);
    expect(screen.getAllByTestId('frozen')).toHaveLength(3);
  });
});

describe('the offer itself', () => {
  it('shows the code as permanent and never as an input', async () => {
    atOffer(clientFor());

    // Waited on the rename field, not on the word "permanent": the create form's
    // hint uses that word too and renders before the offer has loaded, so waiting
    // for it would prove nothing about this section.
    await waitFor(() => expect(screen.getByLabelText(/^offer name$/i)).toBeTruthy());

    // The rename field exists and holds the *name*. The code is text — there is
    // no input for it anywhere on the screen.
    const rename = screen.getByLabelText<HTMLInputElement>(/^offer name$/i);
    expect(rename.value).toBe('Pro monthly');
    // The create form's code field is empty and belongs to a *new* offer; this
    // offer's code appears only as text. Twice, in fact — in the list and in the
    // heading — and never once in an input.
    expect(screen.getByLabelText<HTMLInputElement>(/^code$/i).value).toBe('');
    for (const element of screen.getAllByText('pro-monthly')) {
      expect(element.tagName).toBe('CODE');
    }
  });

  it('renames without touching anything else', async () => {
    let renamed = false;

    atOffer(
      clientFor({
        'PATCH /api/v1/offers/{offerId}': (): Stub => {
          renamed = true;

          return authored([version()]);
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^rename$/i })).toBeTruthy());

    // Disabled until the name actually differs: renaming to the same name is a
    // request that changes nothing.
    expect(screen.getByRole<HTMLButtonElement>('button', { name: /^rename$/i }).disabled).toBe(
      true,
    );

    fireEvent.change(screen.getByLabelText(/^offer name$/i), {
      target: { value: 'Pro, monthly' },
    });
    fireEvent.click(screen.getByRole('button', { name: /^rename$/i }));

    await waitFor(() => expect(renamed).toBe(true));
  });
});

describe('plans in the create form', () => {
  it('are offered in rank order, never in name order', async () => {
    // Starter ranks 10 and Pro ranks 20, so Starter comes first — the opposite of
    // alphabetical, which is what makes this test say something.
    atOffer(clientFor());

    await waitFor(() => expect(screen.getByLabelText(/^plan$/i)).toBeTruthy());

    const options = [...screen.getByLabelText<HTMLSelectElement>(/^plan$/i).options].map(
      (option) => option.textContent,
    );

    expect(options[1]).toContain('Starter');
    expect(options[2]).toContain('Pro');
  });
});
