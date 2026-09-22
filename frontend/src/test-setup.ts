import '@testing-library/react';
import { beforeEach } from 'vitest';

import { setLocale } from '@/i18n';

/**
 * jsdom has no scrolling, and the router restores scroll position on every
 * navigation. Left alone that prints "Not implemented: Window's scrollTo()" for
 * each one, which buries the output a failing test needs — so it is stubbed
 * rather than tolerated. Nothing asserts on scroll position; a real browser does
 * the real thing in the Playwright suite.
 */
window.scrollTo = () => undefined;

/**
 * Every test starts in English (2026-09-22).
 *
 * The language is module state, not React state — `t()` has to be callable
 * from anywhere, including outside a component — so a test that changes it
 * changes it for the next one in the same file. Two screens do exactly that
 * on purpose: the profile and the staff profile save a person's language,
 * and saving applies it.
 *
 * That was showing up as *load*. `setLocale` awaits the catalogue's chunk,
 * so whether the next test rendered in English or in German depended on
 * when that import resolved — and a screen rendered in German fails
 * `findByLabelText('Display name')` the only way a query can fail, by
 * timing out after five seconds. Three separate bundle builds were called
 * off for "flaky timeouts" that were this, in a different file each time.
 *
 * Reset before each test rather than after: a test that leaves the language
 * changed is not doing anything wrong, and an `afterEach` would still leave
 * the very first test of a file at whatever the file before it left behind
 * if isolation is ever relaxed.
 */
beforeEach(async () => {
  await setLocale('en');
});
