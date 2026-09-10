import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stub, type Stubs } from '@/test-utils';

import { SupportConversationsScreen } from './SupportConversationsScreen';

/**
 * Answering as the platform, which is not the same act as answering as a member.
 *
 * U3 established that optimism is safe where nothing binding is created, and a
 * member writing in their own thread qualifies. This does not, so the test below
 * holds the response open and asserts the reply is **not** on screen while it is
 * in flight — the window an optimistic implementation would fill with an official
 * answer that may not exist.
 */
const READER = { staff: { user_id: 'staff-1', roles: ['SUPPORT'], permissions: ['support.read'] } };
const RESPONDER = {
  staff: { user_id: 'staff-1', roles: ['SUPPORT'], permissions: ['support.read', 'support.respond'] },
};

const THREAD = {
  id: 'c-1',
  kind: 'SUPPORT',
  subject: 'Invoice 2026-000042 looks wrong',
  status: 'OPEN',
  created_by: 'u-1',
  closed_at: null,
  created_at: '2026-05-01T09:00:00Z',
  updated_at: '2026-05-01T09:30:00Z',
};

const message = (over: Record<string, unknown> = {}) => ({
  id: 'm-1',
  seq: 1,
  author_user_id: 'u-1',
  author_kind: 'MEMBER',
  body: 'The VAT looks like 20% and we are reverse charge.',
  deleted: false,
  created_at: '2026-05-01T09:00:00Z',
  edited_at: null,
  ...over,
});

const detail = (over: Record<string, unknown> = {}, messages: unknown[] = [message()]): Stub => ({
  data: { ...THREAD, tenant_id: 't-1', product_id: 'p-1', messages, ...over },
});

function clientFor(session: unknown = RESPONDER, extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/me': { data: session },
    'GET /api/v1/staff/conversations': {
      data: { conversations: [THREAD], total: 1, limit: 25, offset: 0 },
    },
    'GET /api/v1/staff/conversations/{conversationId}': detail(),
    ...extra,
  });
}

const at = (id?: string) => ({
  path: '/console/conversations',
  ...(id === undefined ? {} : { initial: `/console/conversations?selected=${id}` }),
});

describe('a reply', () => {
  it('appears only once the server has taken it, and as the server tells it', async () => {
    let reads = 0;

    // The reply appears in the thread only on the *second* read. A screen
    // showing it earlier put it there itself; a screen showing it at all proves
    // the invalidation happened.
    const thread = (): Stub => {
      reads += 1;

      return reads === 1
        ? detail()
        : detail({}, [
            message(),
            message({ id: 'm-2', seq: 2, author_kind: 'STAFF', body: 'Looking into it.' }),
          ]);
    };

    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: RESPONDER },
      'GET /api/v1/staff/conversations': {
        data: { conversations: [THREAD], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/conversations/{conversationId}': thread,
      'POST /api/v1/staff/conversations/{conversationId}/messages': {
        data: message({ id: 'm-2', seq: 2, author_kind: 'STAFF', body: 'Looking into it.' }),
        // Held open, so the window an optimistic implementation would fill is
        // real time rather than a promise that has already settled.
        delayMs: 80,
      },
    });

    renderAtRoute(<SupportConversationsScreen />, client, at(THREAD.id));

    await waitFor(() => expect(screen.getByLabelText('Reply')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Reply'), { target: { value: 'Looking into it.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send' }));

    // Waited on the *request*, not on a render: the assertion below has to be
    // about the moment the answer is outstanding.
    await waitFor(() =>
      expect(requests.filter((request) => request.method === 'POST')).toHaveLength(1),
    );

    // In flight. An optimistic screen would already be showing an official
    // answer written by somebody acting with platform authority.
    //
    // Asserted against the message list rather than by text: the draft is still
    // in the textarea, and `queryByText` would match that and pass whatever the
    // thread did.
    expect(document.querySelector('[data-message="m-2"]')).toBeNull();

    await waitFor(() => expect(document.querySelector('[data-message="m-2"]')).not.toBeNull());
    expect(document.querySelector('[data-message="m-2"]')?.textContent).toContain(
      'Looking into it.',
    );
    expect(screen.getAllByTestId('author-kind').map((n) => n.textContent)).toEqual([
      'MEMBER',
      'STAFF',
    ]);
  });

  it('is sent as typed', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: RESPONDER },
      'GET /api/v1/staff/conversations': {
        data: { conversations: [THREAD], total: 1, limit: 25, offset: 0 },
      },
      'GET /api/v1/staff/conversations/{conversationId}': detail(),
      'POST /api/v1/staff/conversations/{conversationId}/messages': {
        data: message({ id: 'm-2', seq: 2, author_kind: 'STAFF', body: 'Looking into it.' }),
      },
    });

    renderAtRoute(<SupportConversationsScreen />, client, at(THREAD.id));

    await waitFor(() => expect(screen.getByLabelText('Reply')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Reply'), { target: { value: '  Looking into it.  ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send' }));

    const posts = () => requests.filter((request) => request.method === 'POST');

    await waitFor(() => expect(posts()).toHaveLength(1));
    expect(posts()[0]?.body).toEqual({ body: 'Looking into it.' });
  });

  it('cannot be empty', async () => {
    renderAtRoute(<SupportConversationsScreen />, clientFor(), at(THREAD.id));

    await waitFor(() => expect(screen.getByLabelText('Reply')).toBeTruthy());

    expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Send' }).disabled).toBe(true);
  });

  it('is not offered without support.respond', async () => {
    renderAtRoute(<SupportConversationsScreen />, clientFor(READER), at(THREAD.id));

    await waitFor(() => expect(screen.getByTestId('cannot-respond')).toBeTruthy());
    expect(screen.queryByLabelText('Reply')).toBeNull();
    expect(screen.queryByRole('button', { name: /Close the thread/ })).toBeNull();
  });
});

describe('a closed thread', () => {
  it('takes no reply, and says the customer can reopen it', async () => {
    renderAtRoute(
      <SupportConversationsScreen />,
      clientFor(RESPONDER, {
        'GET /api/v1/staff/conversations/{conversationId}': detail({
          status: 'CLOSED',
          closed_at: '2026-05-02T09:00:00Z',
        }),
      }),
      at(THREAD.id),
    );

    await waitFor(() => expect(screen.getByTestId('thread-closed')).toBeTruthy());
    expect(screen.queryByLabelText('Reply')).toBeNull();
    // Closing is the end of this turn, not of the conversation.
    expect(screen.getByTestId('thread-closed').textContent).toMatch(/reopens it by writing again/i);
  });
});

describe('closing', () => {
  it('says what it does and does not do before doing it', async () => {
    renderAtRoute(<SupportConversationsScreen />, clientFor(), at(THREAD.id));

    await waitFor(() => expect(screen.getByRole('button', { name: /Close the thread/ })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /Close the thread/ }));

    const confirmation = screen.getByTestId('close-confirmation');

    expect(confirmation.textContent).toMatch(/does not delete anything/i);
    expect(confirmation.textContent).toMatch(/writing again reopens the thread/i);
    expect(confirmation.textContent).not.toMatch(/are you sure/i);
  });
});

describe('a message', () => {
  it('says who wrote it rather than leaving it to be inferred', async () => {
    renderAtRoute(
      <SupportConversationsScreen />,
      clientFor(RESPONDER, {
        'GET /api/v1/staff/conversations/{conversationId}': detail({}, [
          message(),
          message({ id: 'm-2', seq: 2, author_kind: 'STAFF', body: 'We will check.' }),
        ]),
      }),
      at(THREAD.id),
    );

    await waitFor(() => expect(screen.getAllByTestId('author-kind')).toHaveLength(2));
    expect(screen.getAllByTestId('author-kind').map((n) => n.textContent)).toEqual([
      'MEMBER',
      'STAFF',
    ]);
  });

  it('keeps its place when deleted', async () => {
    renderAtRoute(
      <SupportConversationsScreen />,
      clientFor(RESPONDER, {
        'GET /api/v1/staff/conversations/{conversationId}': detail({}, [
          message({ deleted: true, body: '' }),
        ]),
      }),
      at(THREAD.id),
    );

    await waitFor(() => expect(screen.getByTestId('deleted')).toBeTruthy());
    // The sequence never develops a hole where a reply used to be.
    expect(screen.getByTestId('deleted').textContent).toMatch(/place in the sequence is kept/i);
  });

  it('distinguishes a deleted message from one whose author was erased', async () => {
    renderAtRoute(
      <SupportConversationsScreen />,
      clientFor(RESPONDER, {
        'GET /api/v1/staff/conversations/{conversationId}': detail({}, [
          message({ deleted: false, body: '', author_user_id: null }),
        ]),
      }),
      at(THREAD.id),
    );

    // Both come back with an empty body, and they are not the same fact.
    await waitFor(() => expect(screen.getByTestId('empty-body')).toBeTruthy());
    expect(screen.queryByTestId('deleted')).toBeNull();
    expect(screen.getByTestId('empty-body').textContent).toMatch(/author was erased/i);
  });
});

describe('the thread list', () => {
  it('shows the kind, so the confinement to SUPPORT is visible', async () => {
    renderAtRoute(<SupportConversationsScreen />, clientFor(), at());

    await waitFor(() => expect(screen.getByText(/SUPPORT ·/)).toBeTruthy());
  });

  it('reveals which tenant is asking only on the detail', async () => {
    renderAtRoute(<SupportConversationsScreen />, clientFor(), at());

    await waitFor(() => expect(screen.getByText(THREAD.subject)).toBeTruthy());
    // Knowing which company is asking is already a crossing, and it happens when
    // a thread is opened rather than when the queue is skimmed.
    expect(screen.queryByTestId('thread-tenant')).toBeNull();
  });
});
