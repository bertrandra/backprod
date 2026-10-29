import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { useSignIn } from '@/queries/auth';
import { keys } from '@/queries/keys';
import { sessionSnapshot, useSessionStore } from '@/state/session';
import { stubClient } from '@/test-utils';

import { ApiProvider } from './ApiProvider';
import { AppProviders, createQueryClient } from './AppProviders';

/**
 * One tab, two people (2026-10-29).
 *
 * A refresh refused behind a 401 ends the session from inside the API client's
 * middleware. It used to forget the token and keep the cache, so whoever signed
 * in next on the same tab was shown the previous person's name, roles and
 * permissions until each query happened to refetch.
 */
const ADA = { user_id: 'u-a', email: 'ada@acme.test', display_name: 'Ada', roles: ['TENANT_ADMIN'] };

function SignInAs({ email }: { email: string }) {
  const signIn = useSignIn();

  return (
    <button type="button" onClick={() => signIn.mutate({ email, password: 'correct horse battery' })}>
      Sign in
    </button>
  );
}

beforeEach(() => {
  window.localStorage.clear();
  useSessionStore.setState({ token: 'token-a', productCode: 'atlas', status: 'signed-in', expiresAt: null });
});

describe('a session ended by the transport, then a different person signing in', () => {
  it('leaves nothing of the first person in the cache', async () => {
    const queryClient = createQueryClient();
    queryClient.setQueryData(keys.session.me, ADA);

    render(
      <AppProviders queryClient={queryClient}>
        <ApiProvider
          client={stubClient({
            'POST /api/v1/auth/token': { data: { access_token: 'token-b', token_type: 'Bearer', expires_in: 3600 } },
          })}
        >
          <SignInAs email="bob@acme.test" />
        </ApiProvider>
      </AppProviders>,
    );

    // What the client's middleware calls when `/auth/refresh` answers 401.
    act(() => {
      sessionSnapshot.expired();
    });

    expect(useSessionStore.getState().status).toBe('anonymous');
    expect(queryClient.getQueryData(keys.session.me)).toBeUndefined();

    // Something read while signed out — or left by any path that forgot to
    // clear — must not survive the next sign-in either.
    queryClient.setQueryData(keys.session.me, ADA);
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => {
      expect(useSessionStore.getState().token).toBe('token-b');
    });
    expect(queryClient.getQueryData(keys.session.me)).toBeUndefined();
  });

  it('stops clearing once the providers are gone', () => {
    const queryClient = createQueryClient();
    const { unmount } = render(
      <AppProviders queryClient={queryClient}>
        <p>app</p>
      </AppProviders>,
    );

    unmount();
    queryClient.setQueryData(keys.session.me, ADA);
    useSessionStore.getState().forget();

    expect(queryClient.getQueryData(keys.session.me)).toEqual(ADA);
  });
});
