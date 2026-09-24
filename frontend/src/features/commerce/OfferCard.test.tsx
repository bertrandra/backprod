import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { setLocale } from '@/i18n';

import { OfferCard } from './OfferCard';

/**
 * One offer, as a price with a reason to believe it.
 *
 * Every case here came off a real catalogue the operator read on
 * 2026-09-24, which is why they are worth pinning: each of them looked
 * fine in the source and wrong on the page.
 */
const GRANT = {
  feature: 'exports',
  name: 'Exports',
  kind: 'QUOTA',
  unit: 'exports',
  limit: 10,
  unlimited: false,
} as const;

function offer(over: Partial<{ grants: unknown[]; billing_period: string }> = {}) {
  return {
    id: 'offer-1',
    code: 'pro-monthly',
    name: 'Pro, monthly',
    plan: { id: 'plan-1', code: 'pro', name: 'Pro', rank: 20 },
    version: {
      id: 'v-1',
      version: 1,
      billing_period: over.billing_period ?? 'MONTHLY',
      price: { minor_units: 3900, currency: 'EUR' },
      valid_from: '2026-01-01T00:00:00Z',
      valid_until: null,
      grants: over.grants ?? [GRANT],
    },
  } as unknown as Parameters<typeof OfferCard>[0]['offer'];
}

describe('what a grant says', () => {
  afterEach(async () => {
    await setLocale('en');
  });

  it('says the feature once, however the unit is spelled', () => {
    // `10 exports exports`, on the live catalogue. The feature is called
    // `Exports` and its unit is `exports`, and the line printed both.
    render(<OfferCard offer={offer()} />);

    const grants = screen.getByTestId('grants-offer-1');

    expect(grants.textContent).toContain('10 Exports');
    expect(grants.textContent).not.toContain('exports exports');
  });

  it('folds an English plural against the number one', () => {
    // `1 users users` — where a unit existed the singular fold was skipped
    // as well, so this is the same defect twice in one line.
    render(<OfferCard offer={offer({ grants: [{ ...GRANT, name: 'Users', unit: 'users', limit: 1 }] })} />);

    expect(screen.getByTestId('grants-offer-1').textContent).toContain('1 User');
    expect(screen.getByTestId('grants-offer-1').textContent).not.toContain('1 Users');
  });

  it('folds the plural in a language whose plural is also a trailing s', async () => {
    // French for an afternoon on 2026-09-24 said **1 Utilisateurs**: the
    // fold was made English-only out of caution, which is right about German
    // and Italian and wrong about the two languages that form the plural
    // exactly as English does.
    await setLocale('fr');

    render(<OfferCard offer={offer({ grants: [{ ...GRANT, name: 'Utilisateurs', limit: 1 }] })} />);

    expect(screen.getByTestId('grants-offer-1').textContent).toContain('1 Utilisateur');
  });

  it('leaves a language whose plural is not a trailing s alone', async () => {
    // `Nutzer` is already both numbers; stripping a letter invents a word.
    await setLocale('de');

    render(<OfferCard offer={offer({ grants: [{ ...GRANT, name: 'Nutzers', limit: 1 }] })} />);

    expect(screen.getByTestId('grants-offer-1').textContent).toContain('1 Nutzers');
  });

  it('leaves a name whose plural is not at the end alone', () => {
    // `Documents Plan` — the safe failure, and the reason this does not
    // attempt grammar.
    render(<OfferCard offer={offer({ grants: [{ ...GRANT, name: 'Documents Plan', limit: 1 }] })} />);

    expect(screen.getByTestId('grants-offer-1').textContent).toContain('1 Documents Plan');
  });

  it('spells unlimited rather than showing a missing limit', () => {
    render(<OfferCard offer={offer({ grants: [{ ...GRANT, limit: null, unlimited: true }] })} />);

    expect(screen.getByTestId('grants-offer-1').textContent).toContain('Unlimited Exports');
  });

  it('says a boolean feature and nothing else', () => {
    render(
      <OfferCard
        offer={offer({ grants: [{ feature: 'white_label', name: 'White label', kind: 'BOOLEAN', unit: null, limit: null, unlimited: false }] })}
      />,
    );

    expect(screen.getByTestId('grants-offer-1').textContent).toContain('White label');
  });
});

describe('how often it is paid', () => {
  afterEach(async () => {
    await setLocale('en');
  });

  it('is a word, in the reader’s language', async () => {
    // **monthly** sat under a price on a page whose every other word was
    // French: the contract's enumeration leaking through as if it were a
    // sentence.
    await setLocale('fr');

    render(<OfferCard offer={offer()} />);

    expect(screen.getByText('par mois')).toBeTruthy();
  });

  it('says a period it has never heard of as itself', () => {
    render(<OfferCard offer={offer({ billing_period: 'EVERY_OTHER_WEEK' })} />);

    // Better than nothing at all, which is what a bare lookup would give a
    // period the contract gains before this function hears about it.
    expect(screen.getByText('every other week')).toBeTruthy();
  });
});

describe('what the price excludes', () => {
  it('says so where the card can be acted on', () => {
    // The offer price is the taxable base — VAT is calculated on top of it
    // at invoicing (§25.3) — so a Buy beside a silent figure quotes a
    // number the customer will not be charged.
    render(<OfferCard offer={offer()} onChoose={() => {}} />);

    expect(screen.getByTestId('price-excludes-tax').textContent).toBe('excl. VAT');
  });

  it('stays quiet where the card only states a price', () => {
    render(<OfferCard offer={offer()} />);

    expect(screen.queryByTestId('price-excludes-tax')).toBeNull();
  });
});
