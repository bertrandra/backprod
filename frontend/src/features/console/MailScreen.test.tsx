import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, stubClient, type Stubs } from '@/test-utils';

import { MailScreen } from './MailScreen';

/**
 * The words the platform's mails say, editable, and a test of the host
 * (2026-09-19).
 */
const RESET = {
  type: 'account.password_reset',
  about: 'Somebody asked to set a new password.',
  placeholders: ['link', 'email'],
  default: { subject: 'Set a new password', body: 'Open this link: {link}' },
  subject: 'Set a new password',
  body: 'Open this link: {link}',
  customised: false,
};
const INVITATION = {
  ...RESET,
  type: 'account.invitation',
  about: 'Somebody was added by address.',
  default: { subject: 'You have been added', body: 'Choose your password: {link}' },
  subject: 'Bienvenue',
  body: 'Choisissez votre mot de passe : {link}',
  customised: true,
};

function stubs(live: boolean, extra: Stubs = {}): Stubs {
  return {
    'GET /api/v1/staff/mail/templates': { data: { live, templates: [RESET, INVITATION] } },
    ...extra,
  };
}

describe('the mail screen', () => {
  it('says whether mail leaves, and shows each kind with its placeholders and whether it was customised', async () => {
    renderWith(<MailScreen />, stubClient(stubs(false)));

    await waitFor(() => expect(screen.getByTestId('mail-live')).toBeTruthy());
    expect(screen.getByTestId('mail-live').getAttribute('data-live')).toBe('false');
    expect(screen.getByTestId('mail-live').textContent).toMatch(/no mail leaves/i);
    expect(screen.getByTestId('template-account.password_reset').getAttribute('data-customised')).toBe('false');
    expect(screen.getByTestId('template-account.invitation').getAttribute('data-customised')).toBe('true');
    expect(screen.getByTestId('template-account.password_reset').textContent).toContain('{link}, {email}');
    // No host, no test: the button says why on hover and does nothing.
    expect(screen.getByTestId<HTMLButtonElement>('test-account.password_reset').disabled).toBe(true);
  });

  it('saves the whole set of words, and puts a default back by emptying', async () => {
    const { client, requests } = recordingClient(
      stubs(true, {
        'PUT /api/v1/staff/mail/templates': { data: { live: true, templates: [{ ...RESET, subject: 'Nouveau mot de passe', customised: true }, { ...INVITATION, ...INVITATION.default, customised: false }] } },
      }),
    );
    renderWith(<MailScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Subject', { selector: '#account-password_reset-subject' })).toBeTruthy());
    expect(screen.getByTestId<HTMLButtonElement>('save-templates').disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Subject', { selector: '#account-password_reset-subject' }), { target: { value: 'Nouveau mot de passe' } });
    // The invitation goes back to its default: both fields emptied.
    fireEvent.click(screen.getAllByRole('button', { name: /back to the default/i })[1] as HTMLElement);
    expect(screen.getByTestId<HTMLButtonElement>('save-templates').disabled).toBe(false);

    fireEvent.click(screen.getByTestId('save-templates'));

    await waitFor(() => expect(screen.getByTestId('saved')).toBeTruthy());
    expect(requests.find((r) => r.method === 'PUT')?.body).toEqual({
      templates: {
        'account.password_reset': { subject: 'Nouveau mot de passe', body: 'Open this link: {link}' },
        'account.invitation': { subject: '', body: '' },
      },
    });
    expect(screen.getByTestId('template-account.invitation').getAttribute('data-customised')).toBe('false');
  });

  it('sends a test of one kind to the administrator, and says where it went', async () => {
    const { client, requests } = recordingClient(
      stubs(true, {
        'POST /api/v1/staff/mail/test': { data: { to: 'backprod@raillard.org', subject: 'Bienvenue', provider_message_id: 'msg-1' } },
      }),
    );
    renderWith(<MailScreen />, client);

    await waitFor(() => expect(screen.getByTestId('test-account.invitation')).toBeTruthy());
    fireEvent.click(screen.getByTestId('test-account.invitation'));

    await waitFor(() => expect(screen.getByTestId('tested-account.invitation')).toBeTruthy());
    expect(screen.getByTestId('tested-account.invitation').textContent).toContain('backprod@raillard.org');
    expect(requests.find((r) => r.path === '/api/v1/staff/mail/test')?.body).toEqual({ type: 'account.invitation' });
  });

  it('shows the mail host’s own reason when a test fails', async () => {
    renderWith(
      <MailScreen />,
      stubClient(
        stubs(true, {
          'POST /api/v1/staff/mail/test': {
            status: 409,
            error: { error: { code: 'MAIL_SEND_FAILED', message: 'The mail host refused or could not be reached: Connection refused', details: { error: 'TransportException' }, request_id: 'r' } },
          },
        }),
      ),
    );

    await waitFor(() => expect(screen.getByTestId('test-account.password_reset')).toBeTruthy());
    fireEvent.click(screen.getByTestId('test-account.password_reset'));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    expect(screen.getByRole('alert').textContent).toContain('Connection refused');
  });
});