import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute } from '@/test-utils';

import { ConfirmWhileSignedIn } from './ConfirmYourAddress';

/**
 * The confirmation link, followed by somebody already signed in (ADR-061) —
 * the usual case, and the one that used to confirm nothing: `/sign-in`
 * forwarded to the landing with the token unspent.
 */
describe('a confirmation link followed while signed in', () => {
  it('spends the token once, then carries on without it in the address', async () => {
    const { client, requests } = recordingClient({
      'POST /api/v1/auth/verify-email': { data: { verified: true } },
    });

    const { location } = renderAtRoute(<ConfirmWhileSignedIn token="abc123" />, client, {
      path: '/sign-in',
      initial: '/sign-in?verify=abc123',
    });

    await waitFor(() => expect(location()).not.toContain('/sign-in'));
    const spent = requests.filter((request) => request.path === '/api/v1/auth/verify-email');
    expect(spent).toHaveLength(1);
    expect(spent[0]?.body).toEqual({ token: 'abc123' });
    expect(location()).not.toContain('verify=');
  });

  it('says so when the link is spent, and offers a new one', async () => {
    const { client } = recordingClient({
      'POST /api/v1/auth/verify-email': {
        status: 400,
        error: { error: { code: 'VERIFICATION_FAILED', message: 'No.', details: {}, request_id: 'r' } },
      },
    });

    renderAtRoute(<ConfirmWhileSignedIn token="spent" />, client, { path: '/sign-in', initial: '/sign-in?verify=spent' });

    expect(await screen.findByTestId('confirmation-failed')).toBeTruthy();
    expect(screen.getByTestId('resend-confirmation')).toBeTruthy();
  });
});
