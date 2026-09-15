import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';

import { expect, test } from './support/app';

/**
 * The last gap between a console-built product and money, in a browser.
 *
 * ADR-042 gave the console a way to create a product and ADR-043 a way to price
 * it, and a product built that way refused at the moment it had to raise a
 * document: an invoice must name its issuer, and the issuer is configuration
 * nothing on the platform could write.
 *
 * What only a browser can see here is the **form**: that the whole identity
 * leaves in one PUT (so clearing a field is possible at all), that two controls
 * never share a label, and that a warning about a legal refusal is announced and
 * not merely coloured. Eight text inputs in one section is exactly where a
 * duplicate label hides.
 */
const STAFF = {
  staff: {
    user_id: '11111111-1111-4111-8111-111111111111',
    roles: ['PLATFORM_ADMIN'],
    permissions: ['staff.self.read', 'staff.products.manage', 'staff.catalog.manage'],
  },
};

const PRODUCT = { id: '33333333-3333-4333-8333-333333333333', code: 'atlas', name: 'Atlas' };

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

const TAX = { country: 'FR', oss_registered: false, supply_type: 'DIGITAL_SERVICES', currency: 'EUR' };

/**
 * A product that cannot invoice, and a record of what the screen sent.
 */
async function stubInvoicing(page: Page) {
  const sent: { body: unknown }[] = [];
  const state = { configured: false };

  await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));
  // The switcher's list on the console (ADR-047): one product, named in the
  // address as well, so the screen administers atlas either way.
  await page.route(/\/api\/v1\/staff\/products$/, (route) =>
    route.fulfill({ json: { products: [{ id: 'p-1', code: 'atlas', name: 'Atlas', active: true }] } }),
  );

  await page.route(/\/api\/v1\/me$/, (route) =>
    route.fulfill({
      status: 403,
      json: {
        error: {
          code: 'PERMISSION_DENIED',
          message: 'Platform staff are not members of any tenant.',
          details: {},
          request_id: 'req-1',
        },
      },
    }),
  );

  await page.route(/\/api\/v1\/staff\/configuration\/billing-identity/, async (route) => {
    sent.push({ body: route.request().postDataJSON() });
    state.configured = true;

    return route.fulfill({
      json: { billing_supplier: { ...EMPTY_SUPPLIER, legal_name: 'Atlas SAS', country_code: 'FR' } },
    });
  });

  await page.route(/\/api\/v1\/staff\/configuration\/tax/, async (route) => {
    sent.push({ body: route.request().postDataJSON() });

    return route.fulfill({ json: { tax: TAX } });
  });

  // Registered last of the three, because the most recent route wins and this
  // one's pattern also matches the two above.
  await page.route(/\/api\/v1\/staff\/configuration(\?|$)/, (route) =>
    route.fulfill({
      json: state.configured
        ? {
            product: PRODUCT,
            billing_supplier: { ...EMPTY_SUPPLIER, legal_name: 'Atlas SAS', country_code: 'FR' },
            tax: TAX,
            can_invoice: true,
            missing: [],
          }
        : {
            product: PRODUCT,
            billing_supplier: EMPTY_SUPPLIER,
            tax: TAX,
            can_invoice: false,
            missing: ['legal_name', 'country_code'],
          },
    }),
  );

  return sent;
}

test.describe('a product that cannot invoice', () => {
  test('announces the refusal, names its code and lists the fields', async ({ page }) => {
    await stubInvoicing(page);
    await page.goto('/console/invoicing?product=atlas');

    const warning = page.getByTestId('cannot-invoice');

    await expect(warning).toBeVisible();
    // Announced, not merely red: this is a legal refusal that will surface to a
    // customer at the checkout.
    await expect(warning).toHaveAttribute('role', 'alert');
    await expect(warning).toContainText('BILLING_NOT_CONFIGURED');
    await expect(warning).toContainText('legal_name, country_code');
  });
});

test.describe('setting the issuer', () => {
  test('sends the whole document, so a field can be cleared at all', async ({ page }) => {
    const sent = await stubInvoicing(page);

    await page.goto('/console/invoicing?product=atlas');

    await page.getByLabel('Legal name').fill('Atlas SAS');
    await page.getByLabel('Country', { exact: true }).fill('FR');
    await page.getByRole('button', { name: 'Save the issuer' }).click();

    await expect(page.getByTestId('can-invoice')).toBeVisible();

    expect(sent).toHaveLength(1);
    // Every key present, the empty ones as null. Under a body that omitted them
    // a supplier could never remove a VAT number it no longer has.
    expect(sent[0]?.body).toEqual({
      legal_name: 'Atlas SAS',
      vat_number: null,
      registration_number: null,
      address_line1: null,
      address_line2: null,
      postal_code: null,
      city: null,
      country_code: 'FR',
    });
  });

  test('reports the refusal when the platform rejects the identity', async ({ page }) => {
    await stubInvoicing(page);

    await page.route(/\/api\/v1\/staff\/configuration\/billing-identity/, (route) =>
      route.fulfill({
        status: 400,
        json: {
          error: {
            code: 'VALIDATION_FAILED',
            message: 'An invoice must name who is issuing it and from which country.',
            details: { missing: ['country_code'] },
            request_id: 'req-2',
          },
        },
      }),
    );

    await page.goto('/console/invoicing?product=atlas');

    await page.getByLabel('Legal name').fill('Atlas SAS');
    await page.getByRole('button', { name: 'Save the issuer' }).click();

    // Shown rather than swallowed: a save that silently failed would leave
    // somebody believing their product can invoice.
    await expect(page.getByText(/must name who is issuing it/i)).toBeVisible();
  });
});

test.describe('the tax position', () => {
  test('sends all four fields, because omitting the flag would switch OSS off', async ({ page }) => {
    const sent = await stubInvoicing(page);

    await page.goto('/console/invoicing?product=atlas');

    await page.getByLabel('Registered for the One Stop Shop').check();
    await page.getByRole('button', { name: 'Save the tax position' }).click();

    await expect.poll(() => sent.length).toBeGreaterThan(0);

    expect(sent[0]?.body).toEqual({
      country: 'FR',
      currency: 'EUR',
      supply_type: 'DIGITAL_SERVICES',
      oss_registered: true,
    });
  });
});

test.describe('the screen itself', () => {
  test('passes an accessibility scan', async ({ page }) => {
    await stubInvoicing(page);
    await page.goto('/console/invoicing?product=atlas');
    await page.getByLabel('Legal name').waitFor();

    // Eight text inputs in one section and four in another, on a screen whose
    // own history includes two controls sharing the label "Name".
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations).toEqual([]);
  });

  test('never scrolls horizontally', async ({ page }) => {
    await stubInvoicing(page);
    await page.goto('/console/invoicing?product=atlas');
    await page.getByLabel('Legal name').waitFor();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );

    expect(overflow).toBe(false);
  });
});
