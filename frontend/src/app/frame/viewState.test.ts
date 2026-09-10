import { describe, expect, it } from 'vitest';

import { LIMIT_MAX, parseViewState } from './viewState';

/**
 * A URL is user input.
 *
 * These are the values that actually arrive: a stale bookmark, a hand-edited
 * query string, and someone trying their luck. The rule is that a bad link
 * opens the page rather than breaking it — so unparseable values fall back to
 * the default instead of throwing.
 */
describe('screen state read from the URL', () => {
  it('keeps the state a screen deep-links', () => {
    expect(
      parseViewState({ q: 'atlas', tab: 'lines', selected: 'inv-1', panel: 'true', limit: 50 }),
    ).toEqual({ q: 'atlas', tab: 'lines', selected: 'inv-1', panel: true, limit: 50 });
  });

  it('is empty when nothing is in the URL', () => {
    expect(parseViewState({})).toEqual({});
  });

  it('trims and drops a blank filter rather than filtering on nothing', () => {
    expect(parseViewState({ q: '   ' })).toEqual({});
    expect(parseViewState({ q: '  atlas  ' })).toEqual({ q: 'atlas' });
  });

  it('refuses a tab or selection that is not an identifier', () => {
    // The value ends up in an attribute and in a query key, so a tag or a quote
    // has no business here even though React would escape it.
    expect(parseViewState({ tab: '<script>alert(1)</script>' })).toEqual({});
    expect(parseViewState({ selected: "'; drop table" })).toEqual({});
  });

  it('opens the panel only for a real true', () => {
    expect(parseViewState({ panel: 'true' }).panel).toBe(true);
    expect(parseViewState({ panel: true }).panel).toBe(true);
    expect(parseViewState({ panel: 'yes' }).panel).toBeUndefined();
    expect(parseViewState({ panel: '1' }).panel).toBeUndefined();
  });

  it('ignores a page size outside the range the API accepts', () => {
    expect(parseViewState({ limit: 0 }).limit).toBeUndefined();
    expect(parseViewState({ limit: LIMIT_MAX + 1 }).limit).toBeUndefined();
    expect(parseViewState({ limit: 'all' }).limit).toBeUndefined();
    expect(parseViewState({ limit: 1.5 }).limit).toBeUndefined();
    expect(parseViewState({ limit: LIMIT_MAX }).limit).toBe(LIMIT_MAX);
  });

  it('caps a very long filter instead of sending it', () => {
    const parsed = parseViewState({ q: 'x'.repeat(5000) });

    expect(parsed.q).toHaveLength(200);
  });

  it('carries nothing it was not given, so a URL cannot inject a key', () => {
    const parsed = parseViewState({ q: 'a', evil: 'yes', __proto__: 'no' });

    expect(Object.keys(parsed)).toEqual(['q']);
  });
});
