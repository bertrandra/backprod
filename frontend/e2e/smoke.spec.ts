import { expect, test } from '@playwright/test';

test('the application mounts', async ({ page }) => {
  await page.goto('/');

  // U0 ships no screens. What this proves is that the bundle builds, serves and
  // executes — so U1 starts from a working harness rather than building one
  // while also debugging its first screen.
  await expect(page.getByTestId('u0-placeholder')).toBeVisible();
});

test('nothing renders horizontally scrollable at phone width', async ({ page }) => {
  await page.goto('/');

  const overflows = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );

  // The rule from ui-spec.md §4.2 that is easiest to break and cheapest to
  // check. Asserting it now means every later screen inherits the assertion
  // instead of it being added after somebody reports a sideways-scrolling page.
  expect(overflows).toBe(false);
});
