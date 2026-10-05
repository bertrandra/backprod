import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { DEMO_RATIOS, DemoBand, sandboxFor, sized } from './DemoBand';
import type { DemoRow, ShowcaseRow } from './content';

/**
 * The band that shows the product working (2026-09-30).
 *
 * What it owes is narrow and mostly about the frame: the size it asks for
 * has to be the size it drew at, the address has to have no token left in
 * it, and a row showing a picture must not render a frame at all.
 */
const ADDRESS = 'https://plan.example/?mode=demo&x={width}&y={height}&hrsstart=7&hrsend=19';

function row(overrides: Partial<DemoRow> = {}): ShowcaseRow<DemoRow> {
  return {
    id: 'd-1',
    content: { caption: 'The terrace, in three dimensions', embedUrl: ADDRESS, ratio: '4:3', ...overrides },
    image: null,
  };
}

/** `4 / 3` and `1.3333` both mean the same shape. */
function asNumber(ratio: string): number {
  const [width, height] = ratio.split('/').map((part) => Number(part.trim()));

  return height === undefined || Number.isNaN(height) ? (width ?? 0) : (width ?? 0) / height;
}

describe('the address it asks for', () => {
  it('puts the measured size in, and leaves no token behind', () => {
    const asked = sized(ADDRESS, 900, DEMO_RATIOS['4:3']);

    expect(asked).toContain('x=900');
    expect(asked).toContain('y=675');
    // A token left in the address is read as a literal by whatever is at the
    // other end, and the failure looks like a drawing at the wrong size with
    // nothing to say so.
    expect(asked).not.toContain('{');
  });

  it('leaves the operator’s other numbers alone', () => {
    // The reference size used to be read back from the numbers in the query
    // string, which on this very address picked `hrsstart=7` over
    // `hrsend=19`. The shape is declared now, and these are untouched.
    const asked = sized(ADDRESS, 800, DEMO_RATIOS['4:3']);

    expect(asked).toContain('hrsstart=7');
    expect(asked).toContain('hrsend=19');
  });

  it('asks for a desktop width before the box has been measured', () => {
    // There has to be one: a frame rendered only after the first measurement
    // is a frame that never appears where there is no ResizeObserver.
    expect(sized(ADDRESS, null, DEMO_RATIOS['4:3'])).toContain('x=1024');
  });
});

describe('the frame', () => {
  it('is sandboxed, keeps another origin its own, and never navigates the page', () => {
    render(<DemoBand rows={[row()]} />);

    const frame = screen.getByTestId('demo-frame');
    const sandbox = frame.getAttribute('sandbox') ?? '';

    expect(sandbox).toContain('allow-scripts');
    // Another origin keeps its own (2026-10-05). Without it the frame ran as
    // `null`, and Plan could reach neither its own server nor the IGN's
    // tiles: the live demonstration showed an empty scene.
    expect(sandbox).toContain('allow-same-origin');
    expect(sandbox).not.toContain('allow-top-navigation');
  });

  it('stays opaque for this page’s own origin, or an address it cannot read', () => {
    // On this page's own origin, scripts plus same-origin could lift the
    // sandbox — that is the case the old rule was about.
    expect(sandboxFor('https://www.example.test/demo', 'https://www.example.test')).not.toContain('allow-same-origin');
    expect(sandboxFor('not an address', 'https://www.example.test')).not.toContain('allow-same-origin');
    expect(sandboxFor('data:text/html,hi', 'https://www.example.test')).not.toContain('allow-same-origin');
    expect(sandboxFor('https://plan.example/?mode=demo', 'https://www.example.test')).toContain('allow-same-origin');
  });

  it('reserves the shape the operator named', () => {
    render(<DemoBand rows={[row({ ratio: '4:3' })]} />);

    // What the value *means*, not how the browser spells it: jsdom writes
    // `1` back as `1 / 1`, and an assertion on the spelling would pass or
    // fail on a detail no reader of this page can see.
    expect(asNumber(screen.getByTestId('demo-frame').style.aspectRatio)).toBeCloseTo(4 / 3);
  });

  it('is not rendered at all when the row shows a picture', () => {
    render(
      <DemoBand
        rows={[{ ...row({ embedUrl: null }), image: { url: '/a.png', alt: 'The terrace', ratio: 4 / 3 } }]}
      />,
    );

    expect(screen.queryByTestId('demo-frame')).toBeNull();
    expect(screen.getByAltText('The terrace')).toBeTruthy();
  });

  it('says what it shows outside the frame, whatever the browser decides', () => {
    render(<DemoBand rows={[row()]} />);

    // A blocked frame shows nothing and says nothing, so the caption is the
    // one thing a reader always gets.
    expect(screen.getByText('The terrace, in three dimensions')).toBeTruthy();
  });
});
