import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useSessionStore } from '@/state/session';

import { SignInScreen } from './SignInScreen';

/**
 * The screen that had to exist for any of the other thirty-four to be reachable
 * by a real person.
 *
 * What is asserted here is what the person sees, not what was sent — the request
 * itself is `api/auth.test.ts`'s subject. The three that matter: a failure says
 * one sentence rather than the provider's, the password does not survive a failed
 * attempt, and an unconfigured deployment says so instead of offering a form that
 * cannot work.
 */
function configure(): void {
  vi.stubEnv('VITE_SUPABASE_URL', 'https://project.supabase.test');
  vi.stubEnv('VITE_SUPABASE_ANON_KEY', 'anon-key');
}

function answerWith(response: Partial<Response>): void {
  vi.stubGlobal('fetch', () => Promise.resolve(response as Response));
}

const GRANT = { access_token: 'access', refresh_token: 'refresh', expires_in: 3600 };

beforeEach(() => {
  window.localStorage.clear();
  useSessionStore.setState({ token: null, productCode: null, status: 'anonymous', expiresAt: null });
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

function fillIn(email: string, password: string): void {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: password } });
}

describe('signing in', () => {
  it('puts the token in the session, which is the only thing the API needs', async () => {
    configure();
    answerWith({ ok: true, status: 200, json: () => Promise.resolve(GRANT) });

    render(<SignInScreen />);
    fillIn('ada@acme.test', 'correct horse');
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => {
      expect(useSessionStore.getState().status).toBe('signed-in');
    });
    expect(useSessionStore.getState().token).toBe('access');
  });

  it('says one sentence about a rejected credential', async () => {
    configure();
    answerWith({
      ok: false,
      status: 400,
      json: () => Promise.resolve({ msg: 'Invalid login credentials' }),
    });

    render(<SignInScreen />);
    fillIn('ada@acme.test', 'wrong');
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    // Announced: somebody using a screen reader finds out that the attempt
    // failed without going looking for the reason.
    const alert = await screen.findByRole('alert');

    expect(alert.textContent).toBe('That email and password do not match an account.');
    expect(useSessionStore.getState().status).toBe('anonymous');
  });

  it('distinguishes being throttled from being wrong', async () => {
    configure();
    answerWith({ ok: false, status: 429, json: () => Promise.resolve({}) });

    render(<SignInScreen />);
    fillIn('ada@acme.test', 'correct horse');
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    // Telling somebody their password is wrong when it is right is how people
    // end up resetting a password they did not need to.
    expect((await screen.findByRole('alert')).textContent).toContain('Too many attempts');
  });

  it('clears the password after a failure', async () => {
    configure();
    answerWith({ ok: false, status: 400, json: () => Promise.resolve({}) });

    render(<SignInScreen />);
    fillIn('ada@acme.test', 'wrong');
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByRole('alert');

    // A form that still looks ready invites Enter, which resubmits the same
    // wrong password — and Supabase throttles, so the third one costs a minute.
    expect(screen.getByLabelText<HTMLInputElement>('Password').value).toBe('');
    expect(screen.getByLabelText<HTMLInputElement>('Email').value).toBe('ada@acme.test');
  });

  it('validates the address before sending anything', async () => {
    configure();
    const calls: string[] = [];

    vi.stubGlobal('fetch', (url: string) => {
      calls.push(url);

      return Promise.resolve({ ok: true, json: () => Promise.resolve(GRANT) } as Response);
    });

    render(<SignInScreen />);
    fillIn('not-an-address', 'correct horse');
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect((await screen.findByRole('alert')).textContent).toBe('That is not an email address.');
    expect(calls).toEqual([]);
  });
});

describe('a deployment with no identity provider', () => {
  it('says so, and offers no form that could not work', () => {
    // The backend's own default: an empty SUPABASE_JWKS verifies no key and
    // authenticates nobody. Before this screen existed, that fact reached the
    // person as thirty screens each explaining a 401.
    vi.stubEnv('VITE_SUPABASE_URL', '');
    vi.stubEnv('VITE_SUPABASE_ANON_KEY', '');

    render(<SignInScreen />);

    expect(screen.getByText(/no identity provider configured/)).toBeDefined();
    expect(screen.queryByLabelText('Email')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
  });
});
