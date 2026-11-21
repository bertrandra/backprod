import type { Page } from '@playwright/test';

import { expect, test } from './support/app';

/**
 * The product's page, written in the page editor (2026-11-21; with the page
 * beside the form since 2026-11-20).
 *
 * In a browser and not only in Vitest because the two claims that matter
 * here are about a real one: that a React tree portalled into a frame is
 * styled by the bundle's stylesheet, and that **a frame 390 pixels wide
 * draws the phone's page** — its media queries reading the frame and not
 * the window. jsdom has neither a stylesheet nor a layout to ask.
 */
const STAFF = {
  staff: {
    user_id: '11111111-1111-4111-8111-111111111111',
    roles: ['PLATFORM_ADMIN'],
    permissions: ['staff.self.read', 'staff.products.manage'],
  },
};

const PRODUCT = { id: '33333333-3333-4333-8333-333333333333', code: 'atlas', name: 'Atlas', active: true, display_order: 10 };

const row = (id: string, block: string, position: number, content: Record<string, string>) => ({
  id,
  block,
  position,
  content,
  translations: {},
  asset_id: null,
  image: null,
});

const BLOCKS = [
  row('b-1', 'HEADLINE', 10, { headline: 'Draw a terrace', subline: 'And print the file.' }),
  row('b-2', 'STEPS', 10, { title: 'Draw the parcel', body: 'From the land registry.' }),
  row('b-3', 'STEPS', 20, { title: 'The terrace follows', body: 'To scale.' }),
  row('b-4', 'QUESTION', 10, { question: 'Can I cancel?', answer: 'Whenever you like.' }),
];

async function stubStory(page: Page) {
  await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));
  await page.route(/\/api\/v1\/staff\/products$/, (route) => route.fulfill({ json: { products: [PRODUCT] } }));
  await page.route(/\/api\/v1\/me$/, (route) =>
    route.fulfill({
      status: 403,
      json: { error: { code: 'PERMISSION_DENIED', message: 'Platform staff are not members of any tenant.', details: {}, request_id: 'req-1' } },
    }),
  );
  await page.route(/\/api\/v1\/staff\/products\/[^/]+\/showcase$/, (route) =>
    route.fulfill({ json: { product: PRODUCT, published_at: null, blocks: BLOCKS } }),
  );
  await page.route(/\/api\/v1\/public\/offers/, (route) =>
    route.fulfill({ json: { product: { code: 'atlas', name: 'Atlas' }, offers: [] } }),
  );
}

async function openStudio(page: Page) {
  await stubStory(page);
  await page.goto('/console/products?product=atlas');
  await page.getByTestId('story-atlas').locator('summary').click();
  await page.getByTestId('open-studio').click();

  return page.frameLocator('[data-testid="preview-frame"]');
}

test.describe('editing a product’s page with its preview', () => {
  test('draws the draft as the styled page, and follows what is typed', async ({ page }) => {
    const inside = await openStudio(page);

    const headline = inside.getByTestId('showcase-headline');

    await expect(headline).toHaveText('Draw a terrace');

    // Styled by the bundle's stylesheet, which the frame was handed a copy
    // of: an unstyled h1 is 32px, and this one is display type.
    const size = await headline.evaluate((node) => Number.parseFloat(getComputedStyle(node).fontSize));

    expect(size).toBeGreaterThan(36);

    // Typed in the band's panel, not saved: the page already says it. On a
    // narrow window the panes take turns, so the band is chosen from the
    // list, which shows its panel.
    const bands = page.getByTestId('studio-show-structure');

    if (await bands.isVisible()) {
      await bands.click();
    }

    await page.getByTestId('structure-HEADLINE').click();
    await page.locator('#b-1-headline').fill('Draw a pergola');
    await expect(headline).toHaveText('Draw a pergola');
  });

  test('rewrites a sentence where it is read, and takes it back', async ({ page }) => {
    const inside = await openStudio(page);
    const turn = page.getByTestId('studio-show-page');

    if (await turn.isVisible()) {
      await turn.click();
    }

    // A real browser, because this is its claim: an element inside a frame
    // becomes editable, takes the keys, and React keeps drawing it after.
    const headline = inside.getByTestId('showcase-headline');

    await headline.dblclick();
    await expect(headline).toHaveAttribute('data-rewriting', '');
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('Draw a pergola');
    await page.keyboard.press('Enter');

    await expect(headline).toHaveText('Draw a pergola');
    await expect(headline).not.toHaveAttribute('contenteditable');
    await expect(page.getByTestId('editor-state')).toHaveAttribute('data-dirty', 'true');

    // And again, on the sentence React has since redrawn.
    await headline.dblclick();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('Draw a carport');
    await page.keyboard.press('Enter');
    await expect(headline).toHaveText('Draw a carport');

    await page.getByTestId('editor-undo').click();
    await expect(headline).toHaveText('Draw a pergola');
    await page.getByTestId('editor-undo').click();
    await expect(headline).toHaveText('Draw a terrace');
    await expect(page.getByTestId('editor-state')).toHaveAttribute('data-dirty', 'false');
  });

  test('previews a phone as a phone: the breakpoints read the frame, not the window', async ({ page }) => {
    const inside = await openStudio(page);

    // On a narrow window the form and the page take turns; show the page.
    const turn = page.getByTestId('studio-show-page');

    if (await turn.isVisible()) {
      await turn.click();
    }

    const steps = inside.getByTestId('showcase-steps').locator('ol');

    await expect(steps).toBeVisible();

    const columns = () =>
      steps.evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').length);

    // 1280 wide: the steps sit side by side, whatever the window is.
    await page.getByTestId('preview-desktop').click();
    await expect.poll(columns).toBeGreaterThan(1);

    // 390 wide: one column, in the same window.
    await page.getByTestId('preview-mobile').click();
    await expect.poll(columns).toBe(1);
  });
});
