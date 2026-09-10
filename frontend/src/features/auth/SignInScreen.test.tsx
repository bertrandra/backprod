import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { useSessionStore } from '@/state/session';
import { recordingClient, renderWith, stubClient } from '@/test-utils';

import { SignInScreen } from './SignInScreen';

/**
 * The screen U11 found missing and U12 made this platform's own.
 *
 * The request is now an ordinary contract operation, so these tests assert it the
 * way every other screen's tests assert theirs — through the recording client,
 * with no `fetch` stub anywhere. That is the readable summary of the whole change.
 *
 * The three that matter: a failure says one sentence rather than the server's, the
 * password does not survive a failed attempt, and the password is sent exactly as
 * typed.
 */
const TOKEN = 'POST /api/v1/auth/token';

const SESSION = { access_token: 'access', token_type: 'Bearer', expires_in: 3600 };

beforeEach(() => {
  window.localStorage.clear();
  useSessionStore.setState({ token: null, productCode: null, status: 'anonymous', expiresAt: null });
});

function fillIn(email: string, password: string): void {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: password } });
}

function submit(): void {
  fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
}

describe('signing in', () => {
  it('puts the token in the session, which is the only thing the API needs', async () => {
    renderWith(<SignInScreen />, stubClient({ [TOKEN]: { data: SESSION } }));

    fillIn('ada@acme.test', 'correct horse battery');
    submit();

    await waitFor(() => {
      expect(useSessionStore.getState().status).toBe('signed-in');
    });
    expect(useSessionStore.getState().token).toBe('access');

    // No *credential* is stored anywhere a script can read. The one key that is
    // there is `backprod.product` — which `renderWith` sets, and which is a
    // per-browser convenience about what this tab is acting as rather than a
    // secret. Asserting "storage is empty" instead would have been asserting that
    // the product is not remembered, which is a different feature.
    expect(window.localStorage.getItem('backprod.refresh')).toBeNull();
    expect(Object.keys(window.localStorage)).toEqual(['backprod.product']);
  });

  it('sends the password exactly as typed, spaces and all', async () => {
    const { client, requests } = recordingClient({ [TOKEN]: { data: SESSION } });

    renderWith(<SignInScreen />, client);
    // Leading and trailing spaces, which are legitimate characters in a password.
    // Trimming would change the credential — and asymmetrically, since whichever
    // end trimmed would decide what was stored.
    fillIn('ada@acme.test', '  spaces at both ends  ');
    submit();

    await waitFor(() => {
      expect(requests).toHaveLength(1);
    });
    expect(requests[0]?.body).toEqual({
      email: 'ada@acme.test',
      password: '  spaces at both ends  ',
    });
  });

  it('says one sentence about a rejected credential', async () => {
    renderWith(
      <SignInScreen />,
      stubClient({ [TOKEN]: {
            status: 401,
            error: { error: { code: 'UNAUTHENTICATED', message: 'Authentication is required.', details: {}, request_id: 'r' } },
          },
      }),
    );

    fillIn('ada@acme.test', 'wrong password here');
    submit();

    // The server refuses to say whether it was the address or the password — that
    // difference is how somebody learns which addresses have accounts — and the
    // screen does not invent the distinction.
    const alert = await screen.findByRole('alert');

    expect(alert.textContent).toBe('That email and password do not match an account.');
  });

  it('distinguishes being throttled from being wrong', async () => {
    renderWith(
      <SignInScreen />,
      stubClient({ [TOKEN]: {
            status: 429,
            error: { error: { code: 'TOO_MANY_REQUESTS', message: 'Slow down.', details: {}, request_id: 'r' } },
          },
      }),
    );

    fillIn('ada@acme.test', 'correct horse battery');
    submit();

    // Telling somebody their password is wrong when it is right is how people end
    // up resetting a password they did not need to.
    expect((await screen.findByRole('alert')).textContent).toContain('Too many attempts');
  });

  it('says plainly when the deployment cannot issue sessions at all', async () => {
    renderWith(
      <SignInScreen />,
      stubClient({ [TOKEN]: {
            status: 503,
            error: { error: { code: 'AUTHENTICATION_NOT_CONFIGURED', message: 'No signing secret.', details: {}, request_id: 'r' } },
          },
      }),
    );

    fillIn('ada@acme.test', 'correct horse battery');
    submit();

    // A deployment fault, not a credential one. No amount of retrying or password
    // resetting will help, and saying "wrong password" would send somebody to do
    // both.
    expect((await screen.findByRole('alert')).textContent).toContain('no signing secret');
  });

  it('clears the password after a failure', async () => {
    renderWith(
      <SignInScreen />,
      stubClient({ [TOKEN]: {
            status: 401,
            error: { error: { code: 'UNAUTHENTICATED', message: 'no', details: {}, request_id: 'r' } },
          },
      }),
    );

    fillIn('ada@acme.test', 'wrong password here');
    submit();

    await screen.findByRole('alert');

    expect(screen.getByLabelText<HTMLInputElement>('Password').value).toBe('');
    expect(screen.getByLabelText<HTMLInputElement>('Email').value).toBe('ada@acme.test');
  });

  it('checks the address and the length before sending anything', async () => {
    const { client, requests } = recordingClient({ [TOKEN]: { data: SESSION } });

    renderWith(<SignInScreen />, client);
    fillIn('not-an-address', 'short');
    submit();

    // Both fields object, so there are two alerts. `findByRole` throws on two,
    // which is a fair complaint about the test rather than about the screen: what
    // this asserts is that each field said its own thing.
    const alerts = await screen.findAllByRole('alert');

    expect(alerts.map((alert) => alert.textContent)).toEqual([
      'That is not an email address.',
      'A password is at least 12 characters.',
    ]);
    expect(requests).toEqual([]);
  });

  it('rejects a password longer than bcrypt will actually hash', async () => {
    const { client, requests } = recordingClient({ [TOKEN]: { data: SESSION } });

    renderWith(<SignInScreen />, client);
    // 80 characters. bcrypt ignores everything past 72, so this would verify
    // against its own first 72 and the last eight would be decoration.
    fillIn('ada@acme.test', 'a'.repeat(80));
    submit();

    expect((await screen.findByRole('alert')).textContent).toContain('at most 72');
    expect(requests).toEqual([]);
  });
});
