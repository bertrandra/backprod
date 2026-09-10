import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useSessionStore } from '@/state/session';

import { SignInGate } from './SignInGate';

/**
 * The reload, which is where "no token" being two things stopped being academic.
 *
 * A first paint with no token is either "not signed in" or "one round trip from
 * being signed in". Rendering the form for the second is the bug this file
 * exists to prevent, and it is invisible in a test that starts from a decided
 * state — so every test here starts from `restoring`, which is what a real page
 * load starts from.
 */
function configure(): void {
  vi.stubEnv('VITE_SUPABASE_URL', 'https://project.supabase.test');
  vi.stubEnv('VITE_SUPABASE_ANON_KEY', 'anon-key');
}

const GRANT = { access_token: 'access', refresh_token: 'rotated', expires_in: 3600 };

beforeEach(() => {
  window.localStorage.clear();
  useSessionStore.setState({ token: null, productCode: null, status: 'restoring', expiresAt: null });
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe('a page load with a stored refresh token', () => {
  it('exchanges it and renders the application, never the sign-in form', async () => {
    configure();
    window.localStorage.setItem('backprod.refresh', 'stored');
    vi.stubGlobal('fetch', () =>
      Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(GRANT) } as Response),
    );

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    // Before the exchange answers, the honest thing is on screen — not a form
    // asking somebody who is already signed in to sign in again.
    expect(screen.getByRole('status').textContent).toContain('Restoring your session');

    await waitFor(() => {
      expect(screen.getByText('The application')).toBeDefined();
    });
    expect(useSessionStore.getState().token).toBe('access');
  });

  it('keeps the rotated refresh token, not the one it arrived with', async () => {
    configure();
    window.localStorage.setItem('backprod.refresh', 'stored');
    vi.stubGlobal('fetch', () =>
      Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(GRANT) } as Response),
    );

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    await waitFor(() => {
      expect(window.localStorage.getItem('backprod.refresh')).toBe('rotated');
    });
  });

  it('falls back to the form when the provider refuses the token, and forgets it', async () => {
    configure();
    window.localStorage.setItem('backprod.refresh', 'revoked');
    vi.stubGlobal('fetch', () =>
      Promise.resolve({ ok: false, status: 400, json: () => Promise.resolve({}) } as Response),
    );

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeDefined();
    // Left in place it would be retried on every load, forever, against a
    // provider that has already said no.
    expect(window.localStorage.getItem('backprod.refresh')).toBeNull();
  });

  it('falls back to the form when the provider cannot be reached at all', async () => {
    configure();
    window.localStorage.setItem('backprod.refresh', 'stored');
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')));

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    // Waiting inside a blank page would not fix an outage. A form the person can
    // retry from at least says what is happening.
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeDefined();
  });
});

describe('a page load with nothing stored', () => {
  it('goes straight to the form without asking the provider anything', async () => {
    configure();
    const calls: string[] = [];

    vi.stubGlobal('fetch', (url: string) => {
      calls.push(url);

      return Promise.resolve({ ok: true, json: () => Promise.resolve(GRANT) } as Response);
    });

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeDefined();
    expect(calls).toEqual([]);
  });

  it('renders nothing of the application behind the form', async () => {
    configure();

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    await screen.findByRole('button', { name: 'Sign in' });

    // Not merely hidden: the children never mount, so no screen fires a query
    // that would come back 401. That is the whole reason the gate is above the
    // router rather than inside a shell.
    expect(screen.queryByText('The application')).toBeNull();
  });
});

describe('a session that is already signed in', () => {
  it('renders the application and asks the provider nothing', () => {
    configure();
    const calls: string[] = [];

    vi.stubGlobal('fetch', (url: string) => {
      calls.push(url);

      return Promise.resolve({ ok: true, json: () => Promise.resolve(GRANT) } as Response);
    });

    useSessionStore.setState({ token: 'access', status: 'signed-in', expiresAt: null });

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    expect(screen.getByText('The application')).toBeDefined();
    // `expiresAt` is null, so there is nothing to schedule and nothing to renew.
    expect(calls).toEqual([]);
  });
});

describe('renewal', () => {
  it('renews shortly before the token expires rather than after it has', async () => {
    configure();
    vi.useFakeTimers();
    window.localStorage.setItem('backprod.refresh', 'stored');

    const calls: string[] = [];

    vi.stubGlobal('fetch', (url: string) => {
      calls.push(url);

      return Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ ...GRANT, expires_in: 3600 }),
      } as Response);
    });

    useSessionStore.setState({
      token: 'access',
      status: 'signed-in',
      // Ninety seconds of life left: past the schedule point but not yet expired,
      // so a correct implementation renews in thirty seconds.
      expiresAt: Date.now() + 90_000,
    });

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    expect(calls).toEqual([]);

    await vi.advanceTimersByTimeAsync(31_000);

    expect(calls).toHaveLength(1);
    expect(calls[0]).toContain('grant_type=refresh_token');

    vi.useRealTimers();
  });

  it('signs the person out when the renewal is refused', async () => {
    configure();
    vi.useFakeTimers();
    window.localStorage.setItem('backprod.refresh', 'stored');
    vi.stubGlobal('fetch', () =>
      Promise.resolve({ ok: false, status: 400, json: () => Promise.resolve({}) } as Response),
    );

    useSessionStore.setState({ token: 'access', status: 'signed-in', expiresAt: Date.now() + 90_000 });

    render(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
    );

    await vi.advanceTimersByTimeAsync(31_000);

    expect(useSessionStore.getState().status).toBe('anonymous');
    vi.useRealTimers();
  });
});
