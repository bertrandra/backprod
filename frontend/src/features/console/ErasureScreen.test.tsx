import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, stubClient } from '@/test-utils';

import { ErasureScreen } from './ErasureScreen';

/**
 * Non-negotiable #15: **erasure does not delete everything, and an operator must
 * not be able to believe it does.**
 *
 * That is the whole of what these tests are about. An operator who thinks this
 * wipes a record will promise a customer it did, in writing, and the platform
 * will then have promised something the law forbids. So the grounds are asserted
 * present *before* the button — not only in the report afterwards, when the
 * decision has already been taken.
 *
 * The report is asserted to keep its two columns apart. "42 records processed" is
 * the exact confusion this screen exists to prevent, and a count without its
 * legal ground reads as a failure to delete rather than as an obligation.
 */
const USER_ID = '11111111-1111-4111-8111-111111111111';

const ERASURE = {
  user_id: USER_ID,
  erased: { identity: 1, sessions: 3, notification_recipients: 8 },
  retained: {
    invoices: { count: 4, ground: 'accounting_record' },
    vat_transactions: { count: 4, ground: 'fiscal_record' },
    audit_entries: { count: 17, ground: 'audit_trail' },
  },
};

function client() {
  return stubClient({ 'POST /api/v1/admin/erasures': { data: { erasure: ERASURE } } });
}

describe('before anything is erased', () => {
  it('says what the law will require be kept, and why', () => {
    renderWith(<ErasureScreen />, client());

    const grounds = screen.getByTestId('retention-grounds');

    // Every ground the contract enumerates, in words rather than as an enum.
    expect(grounds.textContent).toMatch(/accounting record/i);
    expect(grounds.textContent).toMatch(/fiscal record/i);
    expect(grounds.textContent).toMatch(/audit trail/i);
    expect(grounds.textContent).toMatch(/legal notice/i);
    expect(grounds.textContent).toMatch(/commercial traceability/i);

    // All five, so adding a sixth to the contract and not here is visible.
    expect(grounds.querySelectorAll('[data-ground]')).toHaveLength(5);
  });

  it('never describes itself as a delete', () => {
    renderWith(<ErasureScreen />, client());

    expect(screen.getByText(/It is not a delete/i)).toBeTruthy();
    expect(screen.getByText(/the row surviving/i)).toBeTruthy();
  });

  it('will not act on something that is not a user identifier', () => {
    renderWith(<ErasureScreen />, client());

    fireEvent.change(screen.getByLabelText('User identifier'), { target: { value: 'ada@acme' } });

    expect(screen.getByRole('alert').textContent).toMatch(/not a user identifier/i);
    expect(
      screen.getByRole<HTMLButtonElement>('button', { name: /Erase this person/ }).disabled,
    ).toBe(true);
  });
});

describe('the confirmation', () => {
  function confirm() {
    renderWith(<ErasureScreen />, client());

    fireEvent.change(screen.getByLabelText('User identifier'), { target: { value: USER_ID } });
    fireEvent.click(screen.getByRole('button', { name: /Erase this person/ }));

    return screen.getByTestId('erasure-confirmation');
  }

  it('names what becomes impossible rather than asking for confirmation', () => {
    const confirmation = confirm();

    expect(confirmation.textContent).not.toMatch(/are you sure/i);
    expect(confirmation.textContent).toMatch(/none of it can be undone/i);
    expect(confirmation.textContent).toMatch(/no search will ever find this person/i);
  });

  it('repeats that records stay, at the moment of deciding', () => {
    // The grounds are further up the page. Somebody who scrolled past them and
    // is now looking at a red button must still be told.
    expect(confirm().textContent).toMatch(/stay, with the identity stripped/i);
  });

  it('can be abandoned', () => {
    confirm();
    fireEvent.click(screen.getByRole('button', { name: 'Do not' }));

    expect(screen.queryByTestId('erasure-confirmation')).toBeNull();
  });
});

describe('the report', () => {
  async function erase() {
    const { client: api, requests } = recordingClient({
      'POST /api/v1/admin/erasures': { data: { erasure: ERASURE } },
    });

    renderWith(<ErasureScreen />, api);

    fireEvent.change(screen.getByLabelText('User identifier'), { target: { value: USER_ID } });
    fireEvent.click(screen.getByRole('button', { name: /Erase this person/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Erase permanently' }));

    await waitFor(() => expect(screen.getByTestId('erasure-report')).toBeTruthy());

    return requests;
  }

  it('keeps what was anonymised and what was kept in separate columns', async () => {
    await erase();

    const report = screen.getByTestId('erasure-report');

    expect(screen.getByTestId('erased-heading').textContent).toMatch(/anonymised/i);
    expect(screen.getByTestId('retained-heading').textContent).toMatch(/kept, as the law requires/i);

    // Three of each, never summed into one figure.
    expect(report.querySelectorAll('[data-erased]')).toHaveLength(3);
    expect(report.querySelectorAll('[data-retained]')).toHaveLength(3);
    expect(report.textContent).not.toMatch(/records processed/i);
  });

  it('gives every retained count its legal ground', async () => {
    await erase();

    const retained = screen.getByTestId('erasure-report').querySelectorAll('[data-retained]');

    for (const row of retained) {
      // A count with no reason reads as a failure to delete.
      expect(row.getAttribute('data-ground')).not.toBe('');
      expect(row.textContent).toMatch(/which|who bought/i);
    }

    expect(
      screen.getByTestId('erasure-report').querySelector('[data-retained="invoices"]')?.textContent,
    ).toMatch(/statutory period/i);
  });

  it('sends the identifier that was typed', async () => {
    const requests = await erase();
    const posts = requests.filter((request) => request.method === 'POST');

    expect(posts).toHaveLength(1);
    expect(posts[0]?.body).toEqual({ user_id: USER_ID });
  });
});

describe('a second erasure of the same person', () => {
  it('shows the refusal rather than a second, emptier report', async () => {
    renderWith(
      <ErasureScreen />,
      stubClient({
        'POST /api/v1/admin/erasures': {
          status: 409,
          error: {
            error: {
              code: 'ALREADY_ERASED',
              message: 'This person has already been erased.',
              details: {},
              request_id: 'req-1',
            },
          },
        },
      }),
    );

    fireEvent.change(screen.getByLabelText('User identifier'), { target: { value: USER_ID } });
    fireEvent.click(screen.getByRole('button', { name: /Erase this person/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Erase permanently' }));

    await waitFor(() =>
      expect(screen.getByRole('alert').textContent).toMatch(/already been erased/i),
    );
    // No report: a second run would file a second, emptier account of one act.
    expect(screen.queryByTestId('erasure-report')).toBeNull();
  });
});

describe('finding the person', () => {
  it('searches the directory where it may be read, and the id it sends is the one chosen', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': {
        data: { staff: { user_id: 'staff-1', roles: ['PLATFORM_ADMIN'], permissions: ['admin.privacy.erase', 'admin.directory.read'] } },
      },
      'GET /api/v1/admin/users': {
        data: {
          users: [{ id: USER_ID, email: 'ada@acme.test', display_name: 'Ada Lovelace', created_at: '2026-01-01T00:00:00Z', erased_at: null }],
          total: 1,
          limit: 8,
          offset: 0,
        },
      },
      'POST /api/v1/admin/erasures': { data: { erasure: ERASURE } },
    });

    renderWith(<ErasureScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Who')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Who'), { target: { value: 'ada' } });
    await waitFor(() => expect(screen.getByRole('option', { name: /Ada Lovelace/ })).toBeTruthy());
    fireEvent.mouseDown(screen.getByRole('option', { name: /Ada Lovelace/ }));

    // Read back as a person before the irreversible step, and the step is
    // still the confirmation.
    await waitFor(() => expect(document.querySelector(`[data-picked="${USER_ID}"]`)).not.toBeNull());
    fireEvent.click(screen.getByRole('button', { name: /Erase this person/ }));
    expect(screen.getByTestId('erasure-confirmation')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Erase permanently' }));

    await waitFor(() => expect(requests.some((r) => r.method === 'POST')).toBe(true));
    expect((requests.find((r) => r.method === 'POST')?.body as { user_id?: unknown }).user_id).toBe(USER_ID);
  });
});
