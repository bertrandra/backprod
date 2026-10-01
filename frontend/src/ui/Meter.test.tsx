import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Meter, Meters, type QuotaUsage } from './Meter';

/**
 * A quota on screen, and the four answers it can give.
 *
 * The row carries `unlimited` and `metered` beside the numbers for a reason,
 * and this file is that reason: three of the four cases have no bar to draw,
 * and the one that reads worst is the limit nothing counts. "0 used" there
 * would tell a customer their allowance is being watched when nothing is
 * watching it.
 */
function quota(over: Partial<QuotaUsage> = {}): QuotaUsage {
  return {
    feature: 'max_projects',
    name: 'Projects',
    unit: 'projects',
    limit: 3,
    unlimited: false,
    metered: true,
    used: 1,
    remaining: 2,
    ...over,
  };
}

describe('a counted quota with a limit', () => {
  it('draws the bar and reads the server’s own figures', () => {
    render(<Meter quota={quota()} />);

    expect(screen.getByTestId('quota-figure').textContent).toBe('1 of 3 projects');
    // `remaining`, not `limit - used`: the server answers it, and the answer
    // that refuses the next project is the same one.
    expect(screen.getByText('2 projects left.')).toBeTruthy();

    const bar = screen.getByRole('progressbar');

    expect(bar.getAttribute('aria-valuenow')).toBe('1');
    expect(bar.getAttribute('aria-valuemax')).toBe('3');
    // Named, or the only graphic answer on the row is decoration to anybody
    // who cannot see it.
    expect(bar.getAttribute('aria-label')).toBe('Projects');

    expect(screen.getByTestId('quota-fill').getAttribute('style')).toContain('width: 33%');
  });

  it('shows the server’s remaining and not its own subtraction', () => {
    // Deliberately inconsistent data: no local `limit - used` produces seven
    // from three and one. It is the only way to prove which number reaches the
    // screen, and the claim is worth proving — the figure that says how many
    // more you may create has to be the figure that refuses the next one.
    render(<Meter quota={quota({ limit: 3, used: 1, remaining: 7 })} />);

    expect(screen.getByText('7 projects left.')).toBeTruthy();
    expect(screen.queryByText('2 projects left.')).toBeNull();
  });

  it('says none left rather than zero left', () => {
    // "0 left" reads as a measurement. "None left" reads as the answer to the
    // question somebody is about to ask.
    render(<Meter quota={quota({ used: 3, remaining: 0 })} />);

    expect(screen.getByText('None left.')).toBeTruthy();
    expect(screen.getByTestId('quota-fill').getAttribute('data-full')).toBe('yes');
  });

  it('clamps the bar past the limit and does not clamp the words', () => {
    // An offer moved down leaves eleven projects against a limit of three. A
    // bar drawn past its track is a drawing bug; a figure rounded down to the
    // limit is a lie about the customer's own data.
    render(<Meter quota={quota({ limit: 3, used: 11, remaining: 0 })} />);

    expect(screen.getByTestId('quota-figure').textContent).toBe('11 of 3 projects');
    expect(screen.getByTestId('quota-fill').getAttribute('style')).toContain('width: 100%');
  });

  it('survives a quota of zero rather than dividing by it', () => {
    render(<Meter quota={quota({ limit: 0, used: 0, remaining: 0 })} />);

    expect(screen.getByTestId('quota-fill').getAttribute('style')).toContain('width: 0%');
  });

  it('drops the unit where the feature has none', () => {
    render(<Meter quota={quota({ unit: null, remaining: 2 })} />);

    expect(screen.getByTestId('quota-figure').textContent).toBe('1 of 3');
    expect(screen.getByText('2 left.')).toBeTruthy();
  });
});

describe('a quota with no limit', () => {
  it('draws no bar for an unlimited one', () => {
    // A bar at 0% would say "none of your allowance used" about an allowance
    // with no end.
    render(<Meter quota={quota({ unlimited: true, limit: null, remaining: null })} />);

    expect(screen.queryByRole('progressbar')).toBeNull();
    expect(screen.getByText('No limit on this.')).toBeTruthy();
  });

  it('reads the figure alone where a limit was never recorded', () => {
    // `limit: null` with `unlimited: false` should not occur. Inventing a
    // denominator for it would draw a bar against a number nobody sold.
    render(<Meter quota={quota({ limit: null, remaining: null, used: 4 })} />);

    expect(screen.queryByRole('progressbar')).toBeNull();
    expect(screen.getByTestId('quota-figure').textContent).toBe('4 used');
  });
});

describe('a quota nothing counts', () => {
  it('says so instead of reporting a zero', () => {
    // The whole reason `metered` is on the row. `max_storage` is a real limit
    // on a real offer and nothing measures it, so the honest answer is the
    // allowance and the words — not a bar at zero.
    render(<Meter quota={quota({ metered: false, used: null, remaining: null, limit: 10, unit: 'GB', name: 'Storage' })} />);

    expect(screen.queryByRole('progressbar')).toBeNull();
    expect(screen.getByTestId('quota-figure').textContent).toBe('10 GB');
    expect(screen.getByText(/nothing is enforcing it/i)).toBeTruthy();
    // And no zero anywhere on the row, which is the thing being prevented.
    expect(screen.queryByText(/\b0\b/)).toBeNull();
  });

  it('distinguishes metered-but-silent from unmetered', () => {
    // A product that meters itself (ADR-051 §4) and has reported nothing yet
    // is a different state: something is watching, it has not spoken. Saying
    // "nothing is enforcing it" there would be wrong.
    render(<Meter quota={quota({ metered: true, used: null, remaining: null })} />);

    expect(screen.getByText('Nothing has reported a reading for this yet.')).toBeTruthy();
    expect(screen.queryByText(/nothing is enforcing it/i)).toBeNull();
  });
});

describe('the list', () => {
  it('keeps the server’s order rather than sorting by name', () => {
    // Re-sorting here would put a product's own quotas in a different order on
    // every screen that shows them.
    render(
      <Meters
        quotas={[
          quota({ feature: 'max_projects', name: 'Projects' }),
          quota({ feature: 'users', name: 'Users', unit: 'users' }),
        ]}
      />,
    );

    const names = Array.from(screen.getByTestId('quota-meters').querySelectorAll('[data-quota]')).map(
      (node) => node.getAttribute('data-quota'),
    );

    expect(names).toEqual(['max_projects', 'users']);
  });

  it('renders nothing visible for no quotas', () => {
    // An offer selling only boolean capabilities has none, which is not an
    // error — the caller decides whether to show a heading above this.
    render(<Meters quotas={[]} />);

    expect(screen.getByTestId('quota-meters').children.length).toBe(0);
  });
});
