import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { useViewState } from '@/app/frame/viewState';
import {
  staffAccess,
  useCloseSupportConversation,
  usePostSupportMessage,
  useStaffIdentity,
  useSupportConversation,
  useSupportConversations,
  type AccessMotive,
  type Message,
} from '@/queries/staff';

import { AccessMotiveGate, MotiveInEffect } from './AccessMotiveGate';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';

/**
 * `console.support.conversations` — answering, from the other side.
 *
 * **Nothing here is optimistic, unlike the tenant's own reply in U3.** The rule
 * U3 established is that optimism is safe where nothing binding is created, and
 * a member writing in their own thread qualifies. This does not: an answer here
 * is written by somebody acting with platform authority into a company's thread
 * and logged as such, so showing it as sent before the server took it would be
 * showing an official reply that may not exist.
 *
 * **Only SUPPORT threads are reachable**, and that is enforced in the database
 * rather than by this screen: a composite foreign key confines platform staff to
 * threads of that kind, *"so staff cannot be added to an internal one even by a
 * bug"*. The screen shows the kind rather than filtering silently — an operator
 * should be able to see that the confinement exists.
 *
 * **Closing does not end the conversation.** The tenant reopens it by writing
 * again, and the button says so instead of implying finality it does not have.
 */
export function SupportConversationsScreen() {
  const { selected } = useViewState();
  const navigate = useNavigate();
  const list = useSupportConversations();

  const select = (id: string | null) => {
    void navigate({
      to: '/console/conversations',
      search: id === null ? {} : { selected: id },
    });
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title={'Support conversations'}
        description={'Threads a customer opened with the platform. Opening one reveals which company is asking, and that read is recorded.'}
      />

      <div className="grid gap-8 lg:grid-cols-[22rem_1fr]">
        <section className="space-y-2">
          {list.isPending ? (
            <SkeletonRows rows={6} />
          ) : list.error !== null ? (
            <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />
          ) : list.data.conversations.length === 0 ? (
            <EmptyState title="No conversations" description="Nobody has written in." />
          ) : (
            <>
              <p data-testid="thread-count" className="text-xs text-subtle">
                Showing {list.data.conversations.length} of {list.data.total}.
              </p>

              <ul className="space-y-2">
                {list.data.conversations.map((conversation) => (
                  <li key={conversation.id}>
                    <button
                      type="button"
                      data-thread={conversation.id}
                      aria-current={selected === conversation.id ? 'true' : undefined}
                      onClick={() => select(conversation.id)}
                      className={`w-full rounded border p-3 text-left text-sm focus-visible:outline-2 focus-visible:outline-offset-2 ${
                        selected === conversation.id
                          ? 'border-ink'
                          : 'border-line hover:bg-canvas dark:hover:bg-inverse'
                      }`}
                    >
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="min-w-0 flex-1 truncate font-medium">
                          {conversation.subject}
                        </span>
                        <span
                          data-testid="thread-status"
                          data-status={conversation.status}
                          className="rounded bg-well px-1.5 py-0.5 text-xs"
                        >
                          {conversation.status}
                        </span>
                      </span>
                      {/* The kind, shown rather than filtered away: staff are
                          confined to SUPPORT by a foreign key, and seeing it is
                          how somebody knows the confinement is real. */}
                      <span className="mt-1 block text-xs text-subtle">
                        {conversation.kind} · updated{' '}
                        {new Date(conversation.updated_at).toLocaleDateString()}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            </>
          )}
        </section>

        <section className="min-w-0">
          {selected === undefined ? (
            <EmptyState
              title="No thread open"
              description="Opening one shows the messages and which tenant they belong to."
            />
          ) : (
            <Thread key={selected} conversationId={selected} />
          )}
        </section>
      </div>
    </div>
  );
}

/**
 * A thread, once somebody has said why they are opening it (R14).
 *
 * The contract puts a conversation's `tenant_id` on this detail and not on the
 * list, which is exactly where the boundary is crossed — skimming the queue
 * reveals no customer, opening a thread reveals which company is asking and what
 * about. So the motive is required here and on no listing.
 */
function Thread({ conversationId }: { conversationId: string }) {
  const [motive, setMotive] = useState<AccessMotive | null>(null);
  const { data: identity } = useStaffIdentity();
  const thread = useSupportConversation(conversationId, motive);
  const post = usePostSupportMessage(conversationId);
  const close = useCloseSupportConversation(conversationId);

  const [body, setBody] = useState('');
  const [confirmingClose, setConfirmingClose] = useState(false);

  const mayRespond = can(staffAccess(identity), 'support.respond');

  if (motive === null) {
    return <AccessMotiveGate what="this thread" onGiven={setMotive} />;
  }

  if (thread.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (thread.error !== null) {
    return <ErrorSurface error={thread.error} onRetry={() => void thread.refetch()} />;
  }

  const conversation = thread.data;
  const closed = conversation.status === 'CLOSED';

  return (
    <div className="space-y-4">
      <header className="space-y-1">
        <h2 className="text-xl font-semibold">{conversation.subject}</h2>
        <p className="text-xs text-muted">
          tenant <code data-testid="thread-tenant">{conversation.tenant_id}</code> · opened{' '}
          {new Date(conversation.created_at).toLocaleDateString()}
        </p>

        <MotiveInEffect motive={motive} onChange={() => setMotive(null)} />
      </header>

      {conversation.messages.length === 0 ? (
        <EmptyState title="No messages" description="The thread exists but nothing was written." />
      ) : (
        <ul className="space-y-3">
          {conversation.messages.map((message) => (
            <MessageRow key={message.id} message={message} />
          ))}
        </ul>
      )}

      {!mayRespond ? (
        <p data-testid="cannot-respond" className="text-sm text-muted">
          You can read this thread. Answering needs <code>support.respond</code>.
        </p>
      ) : closed ? (
        <p data-testid="thread-closed" className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm text-muted">
          This thread is closed, so it takes no reply. The customer reopens it by writing again —
          closing it is not the end of the conversation, only of this turn.
        </p>
      ) : (
        <form
          className="space-y-3 border-t border-line pt-4"
          onSubmit={(event) => {
            event.preventDefault();

            if (body.trim() !== '') {
              post.mutate(body.trim(), { onSuccess: () => setBody('') });
            }
          }}
        >
          <Field
            id="reply"
            label="Reply"
            hint="Written as the platform, into a customer's thread, and recorded as such."
          >
            <textarea
              id="reply"
              rows={4}
              className={inputClass()}
              value={body}
              onChange={(event) => setBody(event.target.value)}
            />
          </Field>

          {post.error !== null && <ErrorSurface error={post.error} />}

          <Button type="submit" pending={post.isPending} disabled={body.trim() === ''}>
            Send
          </Button>
        </form>
      )}

      {mayRespond && !closed && (
        <div className="border-t border-line pt-4">
          {close.error !== null && <ErrorSurface error={close.error} />}

          {confirmingClose ? (
            <div data-testid="close-confirmation" className="space-y-2 text-sm">
              <p>
                Closing marks this turn finished. It does not delete anything and it does not stop
                the customer: writing again reopens the thread.
              </p>
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  pending={close.isPending}
                  onClick={() => close.mutate(undefined, { onSettled: () => setConfirmingClose(false) })}
                >
                  Close the thread
                </Button>
                <Button type="button" variant="secondary" onClick={() => setConfirmingClose(false)}>
                  Leave it open
                </Button>
              </div>
            </div>
          ) : (
            <Button type="button" variant="secondary" onClick={() => setConfirmingClose(true)}>
              Close the thread…
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * One message, with who wrote it.
 *
 * `author_kind` is rendered rather than inferred from whether the id matches the
 * reader: a message from STAFF and a message from a MEMBER read very differently
 * in a dispute, and the contract carries the distinction so nobody has to guess.
 *
 * A deleted message keeps its place — *"the sequence never develops a hole where
 * a reply used to be"* — and an empty body may mean deleted **or** that the
 * author was erased under RGPD. Both are said rather than rendered as silence.
 */
function MessageRow({ message }: { message: Message }) {
  return (
    <li
      data-message={message.id}
      data-author-kind={message.author_kind}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span data-testid="author-kind" className="text-xs font-medium uppercase tracking-wide text-subtle">
          {message.author_kind}
        </span>
        <span className="text-xs text-subtle">#{message.seq}</span>
        <span className="ml-auto text-xs text-subtle">
          {new Date(message.created_at).toLocaleString()}
        </span>
      </div>

      {message.deleted ? (
        <p data-testid="deleted" className="mt-1 italic text-subtle">
          This message was deleted. Its place in the sequence is kept.
        </p>
      ) : message.body === '' ? (
        <p data-testid="empty-body" className="mt-1 italic text-subtle">
          Nothing to show — the author was erased, and the words went with the identity.
        </p>
      ) : (
        <p className="mt-1 whitespace-pre-wrap">{message.body}</p>
      )}
    </li>
  );
}
