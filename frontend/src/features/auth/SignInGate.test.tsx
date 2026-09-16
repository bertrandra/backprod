import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiProvider } from '@/app/providers/ApiProvider';
import { useSessionStore } from '@/state/session';
import { recordingClient, renderWith, stubClient } from '@/test-utils';

import { SignInGate } from './SignInGate';

/**
 * The reload, which is where "no token" being two things stopped being academic.
 *
 * A first paint with no token is either "not signed in" or "one round trip from
 * being signed in". Rendering the form for the second is the bug this file exists
 * to prevent, and it is invisible in a test that starts from a decided state — so
 * every test here starts from `restoring`, which is what a real page load starts
 * from.
 *
 * **What U12 changed:** this no longer looks in storage to decide whether there is
 * anything to resume. It asks the server, and the browser attaches an `HttpOnly`
 * cookie the tests never see. So "a returning visitor" is stubbed as *the server
 * answering 200*, and "not signed in" as the server answering 401 — which is what
 * the real thing distinguishes too, rather than guessing from a storage key.
 */
const REFRESH = 'POST /api/v1/auth/refresh';

const SESSION = { access_token: 'access', token_type: 'Bearer', expires_in: 3600 };

const REFUSED = {
  status: 401,
  error: {
    error: { code: 'UNAUTHENTICATED', message: 'Authentication is required.', details: {}, request_id: 'r' },
  },
};

beforeEach(() => {
  window.localStorage.clear();
  useSessionStore.setState({ token: null, productCode: null, status: 'restoring', expiresAt: null });
});

afterEach(() => {
  vi.useRealTimers();
});

describe('a page load with a session the server still honours', () => {
  it('resumes it and renders the application, never the sign-in form', async () => {
    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      stubClient({ [REFRESH]: { data: SESSION } }),
    );

    // Before the exchange answers, the honest thing is on screen — not a form
    // asking somebody who is already signed in to sign in again.
    expect(screen.getByRole('status').textContent).toContain('Restoring your session');

    await waitFor(() => {
      expect(screen.getByText('The application')).toBeDefined();
    });
    expect(useSessionStore.getState().token).toBe('access');
  });

  it('sends no body, because the credential is a cookie no script can read', async () => {
    const { client, requests } = recordingClient({ [REFRESH]: { data: SESSION } });

    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      client,
    );

    await waitFor(() => {
      expect(requests).toHaveLength(1);
    });
    // A body field here would mean a script had read the refresh token, which is
    // exactly what `HttpOnly` exists to prevent — so accepting one would quietly
    // reopen the hole the cookie closes.
    expect(requests[0]?.body).toBeUndefined();
  });

  it('asks once even when React mounts the page twice', async () => {
    // StrictMode mounts, unmounts and mounts again in development, which is
    // what `src/main.tsx` ships. Two refresh requests would carry the same
    // cookie; the server rotates it on the first and treats the second as a
    // replay — revoking every session (ADR-038). The bug was invisible to
    // every test that rendered once, and to the production build, which
    // mounts once. So this one renders the way development does.
    const { client, requests } = recordingClient({ [REFRESH]: { data: SESSION } });
    useSessionStore.getState().chooseProduct('atlas');

    render(
      <StrictMode>
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
          <ApiProvider client={client}>
            <SignInGate>
              <p>The application</p>
            </SignInGate>
          </ApiProvider>
        </QueryClientProvider>
      </StrictMode>,
    );

    await waitFor(() => {
      expect(screen.getByText('The application')).toBeDefined();
    });
    expect(requests.filter((request) => request.path === '/api/v1/auth/refresh')).toHaveLength(1);
    expect(useSessionStore.getState().token).toBe('access');
  });
});

describe('a page load with no session', () => {
  it('shows the form once the server says so', async () => {
    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      stubClient({ [REFRESH]: REFUSED }),
    );

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeDefined();
  });

  it('renders nothing of the application behind the form', async () => {
    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      stubClient({ [REFRESH]: REFUSED }),
    );

    await screen.findByRole('button', { name: 'Sign in' });

    // Not merely hidden: the children never mount, so no screen fires a query that
    // would come back 401. That is the reason the gate is above the router rather
    // than inside a shell.
    expect(screen.queryByText('The application')).toBeNull();
  });

  it('shows the form when the server cannot be reached at all', async () => {
    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      stubClient({ [REFRESH]: { status: 503, error: { error: { code: 'X', message: 'x', details: {}, request_id: 'r' } } } }),
    );

    // Waiting inside a blank page would not fix an outage. A form the person can
    // retry from at least says what is happening.
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeDefined();
  });
});

describe('a session already in hand', () => {
  it('renders the application and asks the server nothing', () => {
    const { client, requests } = recordingClient({ [REFRESH]: { data: SESSION } });

    useSessionStore.setState({ token: 'access', status: 'signed-in', expiresAt: null });

    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      client,
    );

    expect(screen.getByText('The application')).toBeDefined();
    // `expiresAt` is null, so there is nothing to schedule and nothing to renew.
    expect(requests).toEqual([]);
  });
});

describe('renewal', () => {
  it('renews shortly before the token expires rather than after it has', async () => {
    vi.useFakeTimers();

    const { client, requests } = recordingClient({ [REFRESH]: { data: SESSION } });

    useSessionStore.setState({
      token: 'access',
      status: 'signed-in',
      // Ninety seconds of life left: past the schedule point but not yet expired,
      // so a correct implementation renews in thirty seconds.
      expiresAt: Date.now() + 90_000,
    });

    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      client,
    );

    expect(requests).toEqual([]);

    await vi.advanceTimersByTimeAsync(31_000);

    expect(requests).toHaveLength(1);
    expect(requests[0]?.path).toBe('/api/v1/auth/refresh');
  });

  it('signs the person out when the renewal is refused', async () => {
    vi.useFakeTimers();

    useSessionStore.setState({ token: 'access', status: 'signed-in', expiresAt: Date.now() + 90_000 });

    renderWith(
      <SignInGate>
        <p>The application</p>
      </SignInGate>,
      stubClient({ [REFRESH]: REFUSED }),
    );

    await vi.advanceTimersByTimeAsync(31_000);

    expect(useSessionStore.getState().status).toBe('anonymous');
  });
});
