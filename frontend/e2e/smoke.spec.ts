import { expect, test } from '@playwright/test';

/**
 * The application builds, serves and mounts.
 *
 * U0 asserted a placeholder component; U1 replaced it with the shell, so this
 * asserts the shell's outermost region instead. Kept separate from shell.spec.ts
 * because this one stubs nothing: it is the check that the bundle executes at
 * all, and a failure here means the build is broken rather than the shell.
 */
test('the application mounts', async ({ page }) => {
  await page.goto('/');

  await expect(page.locator('[data-region="context-bar"]')).toBeVisible();
});

test('nothing renders horizontally scrollable at phone width', async ({ page }) => {
  await page.goto('/');

  const overflows = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );

  // The rule from ui-spec.md §4.2 that is easiest to break and cheapest to
  // check. Asserting it now means every later screen inherits the assertion.
  expect(overflows).toBe(false);
});
