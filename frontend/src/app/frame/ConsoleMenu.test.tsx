import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, stubClient } from '@/test-utils';

import { ConsoleMenuBar, ConsoleMenuSheet } from './ConsoleMenu';
import { APP_NAV, firstTenantEntry, platformSections, visibleNav } from './navigation';

/**
 * The console's own menu (ADR-047): every admin screen the person may open,
 * grouped, with the current one marked and one way back to the application.
 *
 * Rendered from the real tree through the real filter, so a section that
 * appears here is one `visibleNav` would show — the menu invents nothing.
 */
const platform = (permissions: string[]) =>
  visibleNav(APP_NAV, { tenant: undefined, platform: { permissions, capabilities: [] } });

const EVERYTHING = APP_NAV.flatMap((s) => s.entries)
  .filter((e) => e.scope === 'platform')
  .map((e) => e.permission);

describe('the menu bar', () => {
  it('has one disclosure per platform section, and names the current screen', async () => {
    const sections = platformSections(platform(EVERYTHING));

    renderAtRoute(
      <ConsoleMenuBar sections={sections} tenantApp={undefined} currentPath="/console/tenants" />,
      stubClient({}),
      { path: '/console/tenants' },
    );

    await waitFor(() => expect(screen.getByTestId('console-menu')).toBeTruthy());

    // Setup, Customers, Platform — the three the tree leads with.
    expect(
      [...screen.getByTestId('console-menu').querySelectorAll('details')].map((d) =>
        d.getAttribute('data-console-section'),
      ),
    ).toEqual(['setup', 'demo', 'customers', 'platform']);

    // The current screen, and only it, is the page.
    const current = screen.getAllByRole('link').filter((l) => l.getAttribute('aria-current') === 'page');
    expect(current.map((l) => l.getAttribute('data-console-nav'))).toEqual(['tenants']);
  });

  it('closes a dropdown once a screen is chosen, and closes the others when one opens', async () => {
    // A native disclosure stays open over the page that changed underneath
    // it (the operator's report, 2026-09-18); choosing must close it.
    const sections = platformSections(platform(EVERYTHING));

    renderAtRoute(
      <ConsoleMenuBar sections={sections} tenantApp={undefined} currentPath="/console/tenants" />,
      stubClient({}),
      { path: '/console/tenants' },
    );

    await waitFor(() => expect(screen.getByTestId('console-menu')).toBeTruthy());
    const [setup, demo] = [...screen.getByTestId('console-menu').querySelectorAll('details')];
    expect(setup).toBeDefined();
    expect(demo).toBeDefined();

    setup!.open = true;
    fireEvent(setup!, new Event('toggle'));
    expect(setup!.open).toBe(true);

    // Opening a second closes the first.
    demo!.open = true;
    fireEvent(demo!, new Event('toggle'));
    expect(setup!.open).toBe(false);
    expect(demo!.open).toBe(true);

    // Choosing closes the one it was chosen from.
    fireEvent.click(demo!.querySelector('a[data-console-nav]') as HTMLElement);
    expect(demo!.open).toBe(false);
  });

  it('points the way back at the application when there is one, else the front door', async () => {
    const both = visibleNav(APP_NAV, {
      tenant: { permissions: ['projects.read'], capabilities: [] },
      platform: { permissions: ['staff.tenants.read'], capabilities: [] },
    });

    renderAtRoute(
      <ConsoleMenuBar
        sections={platformSections(both)}
        tenantApp={firstTenantEntry(both)}
        currentPath="/console/tenants"
      />,
      stubClient({}),
      { path: '/console/tenants' },
    );

    await waitFor(() => expect(screen.getByTestId('tenant-app-link')).toBeTruthy());
    expect(screen.getByTestId('tenant-app-link').getAttribute('href')).toBe('/projects');
  });

  it('shows only what the platform role allows', async () => {
    const support = platformSections(platform(['staff.tenants.read', 'support.read']));

    renderAtRoute(
      <ConsoleMenuBar sections={support} tenantApp={undefined} currentPath="/console/tenants" />,
      stubClient({}),
      { path: '/console/tenants' },
    );

    await waitFor(() => expect(screen.getByTestId('console-menu')).toBeTruthy());

    expect(screen.getAllByRole('link').map((l) => l.getAttribute('data-console-nav'))).toEqual([
      'tenants',
      'threads',
      null, // the way back
    ]);
    expect(screen.getByTestId('tenant-app-link').getAttribute('href')).toBe('/');
  });
});

describe('the sheet on a phone', () => {
  it('lists every admin screen, one tap each, and closes on the way', async () => {
    const sections = platformSections(platform(EVERYTHING));
    let closed = 0;

    renderAtRoute(
      <ConsoleMenuSheet
        open
        onClose={() => {
          closed += 1;
        }}
        sections={sections}
        tenantApp={undefined}
        currentPath="/console/queue"
      />,
      stubClient({ 'POST /api/v1/auth/sign-out': { status: 204 } }),
      { path: '/console/queue' },
    );

    await waitFor(() => expect(screen.getByTestId('console-menu-sheet')).toBeTruthy());

    const links = screen.getAllByRole('link').filter((l) => l.getAttribute('data-console-nav') !== null);
    expect(links.length).toBe(EVERYTHING.length);
    expect(links.find((l) => l.getAttribute('aria-current') === 'page')?.getAttribute('data-console-nav')).toBe('queue');

    fireEvent.click(links[0] as HTMLElement);
    expect(closed).toBe(1);

    // The way out is here too: on a phone this is the only place it fits.
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeTruthy();
  });

  it('renders nothing while closed', () => {
    renderAtRoute(
      <ConsoleMenuSheet open={false} onClose={() => undefined} sections={[]} tenantApp={undefined} currentPath="/console" />,
      stubClient({}),
      { path: '/console' },
    );

    expect(screen.queryByTestId('console-menu-sheet')).toBeNull();
  });
});
