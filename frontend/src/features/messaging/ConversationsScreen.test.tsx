import { fireEvent, screen, waitFor } from '@testing-library/react';
import { StrictMode } from 'react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { ConversationsScreen } from './ConversationsScreen';

/**
 * The one optimistic update in this milestone, and the reasons it is allowed.
 *
 * A message has no legal number, allocates no sequence this client controls, and
 * carries no money — so showing it before the server confirms costs nothing that
 * cannot be taken back. What the tests below insist on is the taking back: a
 * failed post must be *visibly* not sent, because a placeholder that stayed on
 * screen would look delivered and the person would not say it again.
 */
const SESSION_WITH_MESSAGING = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'messages.read', 'messages.write'],
};

const THREAD_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const USER_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

const CONVERSATION = {
  id: THREAD_ID,
  kind: 'INTERNAL',
  subject: 'Scaffolding on the north wall',
  status: 'OPEN',
  created_by: USER_ID,
  closed_at: null,
  created_at: '2026-01-01T10:00:00Z',
  updated_at: '2026-01-01T10:00:00Z',
  unread: 1,
};

function message(seq: number, overrides: Record<string, unknown> = {}) {
  return {
    id: `m-${String(seq)}`,
    seq,
    author_user_id: USER_ID,
    author_kind: 'MEMBER',
    body: `message ${String(seq)}`,
    deleted: false,
    created_at: '2026-01-01T10:00:00Z',
    edited_at: null,
    ...overrides,
  };
}

function participant(overrides: Record<string, unknown> = {}) {
  return {
    user_id: USER_ID,
    kind: 'MEMBER',
    last_read_seq: 1,
    joined_at: '2026-01-01T10:00:00Z',
    left_at: null,
    ...overrides,
  };
}

function baseStubs(extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: SESSION_WITH_MESSAGING },
    'GET /api/v1/conversations': {
      data: { conversations: [CONVERSATION], total: 1, limit: 25, offset: 0 },
    },
    'GET /api/v1/conversations/{conversationId}': {
      data: { ...CONVERSATION, participants: [participant()] },
    },
    'GET /api/v1/conversations/{conversationId}/messages': {
      data: { messages: [message(1)], since_seq: 0, limit: 50 },
    },
    'POST /api/v1/conversations/{conversationId}/read': { data: { last_read_seq: 1 } },
    'GET /api/v1/tenants/current/members': {
      data: {
        members: [
          { user_id: participant().user_id, email: 'ada@acme.test', display_name: 'Ada', roles: ['TENANT_ADMIN'] },
          { user_id: '11111111-1111-4111-8111-111111111111', email: 'grace@acme.test', display_name: 'Grace', roles: ['USER'] },
        ],
      },
    },
    ...extra,
  });
}

const atThread = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<ConversationsScreen />, client, {
    path: '/conversations',
    initial: `/conversations?selected=${THREAD_ID}`,
  });

describe('posting a message', () => {
  it('shows it at once, then replaces the placeholder with the server’s message', async () => {
    let posted = 0;

    atThread(
      baseStubs({
        'GET /api/v1/conversations/{conversationId}/messages': (): Stub => ({
          data: {
            messages: posted === 0 ? [message(1)] : [message(1), message(2, { body: 'on my way' })],
            since_seq: 0,
            limit: 50,
          },
        }),
        'POST /api/v1/conversations/{conversationId}/messages': (): Stub => {
          posted += 1;

          return { data: message(2, { body: 'on my way' }), status: 201 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText(/reply/i)).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/reply/i), { target: { value: 'on my way' } });
    fireEvent.click(screen.getByRole('button', { name: /^send$/i }));

    // The placeholder: present, marked pending, and carrying seq 0 — which no
    // delivered message has, so nothing can mistake it for one.
    await waitFor(() => expect(screen.getByText('on my way')).toBeTruthy());

    // And then reconciled: the pending marker is gone once the thread refetches.
    await waitFor(() =>
      expect(document.querySelectorAll('[data-pending="true"]')).toHaveLength(0),
    );
    expect(screen.getByText('on my way')).toBeTruthy();
    expect(posted).toBe(1);
  });

  it('takes the placeholder back when the post fails, and says why', async () => {
    let fetches = 0;

    atThread(
      baseStubs({
        // The thread loads at once; every refetch after that is held open. That
        // is what makes this a test of the *rollback* rather than of the refetch:
        // while the reconciling request is still in flight, only `onError`
        // putting the previous thread back can clear the placeholder. Without it
        // the unsent message sits on screen looking sent, which is the whole
        // reason the rollback exists.
        'GET /api/v1/conversations/{conversationId}/messages': (): Stub => {
          fetches += 1;
          const thread = { messages: [message(1)], since_seq: 0, limit: 50 };

          return fetches === 1 ? { data: thread } : { data: thread, delayMs: 30_000 };
        },
        'POST /api/v1/conversations/{conversationId}/messages': {
          error: {
            error: {
              code: 'VALIDATION_FAILED',
              message: 'too long',
              details: {},
              request_id: 'r-1',
            },
          },
          status: 422,
        },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText(/reply/i)).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/reply/i), { target: { value: 'never sent' } });
    fireEvent.click(screen.getByRole('button', { name: /^send$/i }));

    // The refusal is on screen and the text is not in the thread. A message that
    // stayed would look sent, which is the failure this rollback exists for.
    // The reason, while the reconciling refetch is still in flight: a failure
    // that only became visible once the network answered would leave the person
    // watching a button that says "Working…" about a message already taken back.
    await waitFor(() => expect(screen.getByText(/was not valid/i)).toBeTruthy());
    await waitFor(() => expect(screen.queryByText('never sent')).toBeNull());
    expect(document.querySelectorAll('[data-pending="true"]')).toHaveLength(0);
    // The thread it was posted into is still there, which is what "put back"
    // means — not an empty screen.
    expect(screen.getByText('message 1')).toBeTruthy();
  });
});

describe('the open thread', () => {
  it('opens from the URL alone, so a link is enough', async () => {
    atThread(baseStubs());

    // The reply box, not the subject: the subject is in the list too, so waiting
    // for it would prove only that the list rendered.
    await waitFor(() => expect(screen.getByLabelText(/reply/i)).toBeTruthy());
    expect(screen.getByText('message 1')).toBeTruthy();
  });

  it('goes into the URL when a thread is chosen, and comes back out', async () => {
    // The point of §4.3: a support handover is a link. Component state would
    // render identically and be unshareable, so the assertion is on the location
    // rather than on what is on screen.
    const { location } = renderAtRoute(<ConversationsScreen />, baseStubs(), {
      path: '/conversations',
    });

    await waitFor(() => expect(screen.getByText('Scaffolding on the north wall')).toBeTruthy());
    expect(location()).not.toContain('selected');

    fireEvent.click(screen.getByText('Scaffolding on the north wall'));

    await waitFor(() => expect(location()).toContain(`selected=${THREAD_ID}`));
    await waitFor(() => expect(screen.getByRole('button', { name: /^back$/i })).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /^back$/i }));

    await waitFor(() => expect(location()).not.toContain('selected'));
  });

  it('reports the newest message read once, even under StrictMode', async () => {
    let reads = 0;

    // StrictMode, because that is what the application runs (`main.tsx`) and it
    // mounts every effect twice — so a read wired to the mount rather than to the
    // watermark reports twice here and once in a production build, which is the
    // hardest kind of bug to see.
    renderAtRoute(
      <StrictMode>
        <ConversationsScreen />
      </StrictMode>,
      baseStubs({
        'POST /api/v1/conversations/{conversationId}/read': (): Stub => {
          reads += 1;

          return { data: { last_read_seq: 1 } };
        },
      }),
      { path: '/conversations', initial: `/conversations?selected=${THREAD_ID}` },
    );

    await waitFor(() => expect(reads).toBe(1));

    // And marking read invalidates the thread, which refetches it — so this also
    // catches the version that reports on every refetch, where the two would take
    // turns for as long as the thread stayed open.
    await new Promise((resolve) => setTimeout(resolve, 150));
    expect(reads).toBe(1);
  });

  it('keeps a deleted message in place rather than leaving a hole', async () => {
    atThread(
      baseStubs({
        'GET /api/v1/conversations/{conversationId}/messages': {
          data: {
            messages: [message(1), message(2, { deleted: true, body: '' }), message(3)],
            since_seq: 0,
            limit: 50,
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByText(/was deleted/i)).toBeTruthy());
    // Three rows, and #2 still occupies its place in the sequence.
    expect(document.querySelectorAll('[data-message]')).toHaveLength(3);
    expect(screen.getByText('#2')).toBeTruthy();
  });

  it('offers no reply box once it is closed', async () => {
    atThread(
      baseStubs({
        'GET /api/v1/conversations/{conversationId}': {
          data: {
            ...CONVERSATION,
            status: 'CLOSED',
            closed_at: '2026-02-01T10:00:00Z',
            participants: [participant()],
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByText(/thread is closed/i)).toBeTruthy());
    expect(screen.queryByRole('button', { name: /^send$/i })).toBeNull();
    // And no participant changes either: the backend refuses both on a closed
    // thread, so offering them would be offering a failure.
    expect(screen.queryByRole('button', { name: /^add$/i })).toBeNull();
  });
});

describe('participants', () => {
  it('come from the server, and adding one refetches rather than pushing a row', async () => {
    let added = 0;

    atThread(
      baseStubs({
        'GET /api/v1/conversations/{conversationId}': (): Stub => ({
          data: {
            ...CONVERSATION,
            participants:
              added === 0
                ? [participant()]
                : [participant(), participant({ user_id: '11111111-1111-4111-8111-111111111111' })],
          },
        }),
        'POST /api/v1/conversations/{conversationId}/participants': (): Stub => {
          added += 1;

          return { data: participant(), status: 201 };
        },
      }),
    );

    await waitFor(() => expect(document.querySelectorAll('[data-participant]')).toHaveLength(1));

    // By name, from the members the reader may list: the one already in the
    // thread is not offered again, and the id travels underneath the name.
    await waitFor(() => expect(screen.getByLabelText(/add a colleague/i)).toBeTruthy());
    const picker = screen.getByLabelText<HTMLSelectElement>(/add a colleague/i);
    expect([...picker.options].map((o) => o.textContent)).toEqual(['Choose a colleague…', 'Grace <grace@acme.test>']);
    expect(screen.getByText('Ada <ada@acme.test>')).toBeTruthy();

    fireEvent.change(picker, { target: { value: '11111111-1111-4111-8111-111111111111' } });
    fireEvent.click(screen.getByRole('button', { name: /^add$/i }));

    await waitFor(() => expect(document.querySelectorAll('[data-participant]')).toHaveLength(2));
    expect(added).toBe(1);
  });

  it('falls back to the user id where the members cannot be listed', async () => {
    atThread(
      baseStubs({
        'GET /api/v1/me': {
          data: { ...SESSION_WITH_MESSAGING, permissions: ['messages.read', 'messages.write'] },
        },
      }),
    );

    // No `members.read`: the list is not asked for, and the id field stays
    // rather than a picker with nothing in it.
    await waitFor(() => expect(screen.getByLabelText(/add by user id/i)).toBeTruthy());
    expect(screen.queryByLabelText(/add a colleague/i)).toBeNull();
  });

  it('refuses anything that is not a user id, before asking the API', async () => {
    let added = 0;

    atThread(
      baseStubs({
        'GET /api/v1/me': {
          data: { ...SESSION_WITH_MESSAGING, permissions: ['messages.read', 'messages.write'] },
        },
        'POST /api/v1/conversations/{conversationId}/participants': (): Stub => {
          added += 1;

          return { data: participant(), status: 201 };
        },
      }),
    );

    await waitFor(() => expect(screen.getByLabelText(/add by user id/i)).toBeTruthy());

    fireEvent.change(screen.getByLabelText(/add by user id/i), {
      target: { value: 'ada@acme.test' },
    });
    fireEvent.click(screen.getByRole('button', { name: /^add$/i }));

    await waitFor(() => expect(screen.getByText(/named by user id/i)).toBeTruthy());
    expect(added).toBe(0);
  });

  it('shows someone who left instead of dropping them', async () => {
    // Their messages stay attributed, so removing the row would leave those
    // unexplained.
    atThread(
      baseStubs({
        'GET /api/v1/conversations/{conversationId}': {
          data: {
            ...CONVERSATION,
            participants: [participant({ left_at: '2026-02-01T10:00:00Z' })],
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByText(/^left$/i)).toBeTruthy());
    expect(document.querySelectorAll('[data-remove-participant]')).toHaveLength(0);
  });
});

describe('the list', () => {
  it('shows no thread as open when the URL selects none', async () => {
    renderAtRoute(<ConversationsScreen />, baseStubs(), { path: '/conversations' });

    await waitFor(() => expect(screen.getByText(/no thread selected/i)).toBeTruthy());
    expect(screen.getByText('Scaffolding on the north wall')).toBeTruthy();
  });
});
