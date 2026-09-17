import { zodResolver } from '@hookform/resolvers/zod';
import { useNavigate } from '@tanstack/react-router';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { can } from '@/app/access/access';
import { useViewState } from '@/app/frame/viewState';

import {
  CONVERSATION_KINDS,
  useAddParticipant,
  useCloseConversation,
  useConversation,
  useConversations,
  useDeleteMessage,
  useMarkConversationRead,
  useMessages,
  usePostMessage,
  useRemoveParticipant,
  useStartConversation,
  type Participant,
} from '@/queries/conversations';
import { useMembers } from '@/queries/members';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { PersonSelect, personLabel, type Person } from '@/ui/pickers/Select';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `messaging.conversations` — threads, and the thread you have open.
 *
 * **The selected thread is in the URL**, not in component state (ui-spec.md §4.3):
 * `?selected=` is what makes a thread linkable in a support handover. On a phone
 * the same route shows the thread instead of the list — pushed, not revealed —
 * which is §4.2's list-and-detail rule.
 */
const startSchema = z.object({
  kind: z.enum(CONVERSATION_KINDS),
  subject: z.string().trim().min(1, 'Give the thread a subject.'),
});

const replySchema = z.object({
  body: z.string().trim().min(1),
});

const participantSchema = z.object({
  user_id: z.string().uuid('A participant is named by user id.'),
});

export function ConversationsScreen() {
  // The selected thread comes from the URL, validated (ui-spec.md §4.3) — so a
  // support handover is a link, and `?selected=<script>` opens the list rather
  // than rendering anything.
  const { selected } = useViewState();
  const navigate = useNavigate();
  const list = useConversations();
  const start = useStartConversation();

  const select = (id: string | null) => {
    void navigate({
      to: '/conversations',
      search: id === null ? {} : { selected: id },
    });
  };

  const startForm = useForm<z.infer<typeof startSchema>>({
    resolver: zodResolver(startSchema),
    defaultValues: { kind: 'INTERNAL', subject: '' },
  });

  if (list.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (list.error !== null) {
    return <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />;
  }

  return (
    <div className="lg:flex lg:gap-6">
      {/* Hidden on a phone when a thread is open: the same route, one thing at a
          time, which is what "pushed rather than revealed" means in practice. */}
      <div className={selected === undefined ? 'lg:w-80' : 'hidden lg:block lg:w-80'}>
        <h1 className="mb-3 text-lg font-semibold">Conversations</h1>

        {list.data.conversations.length === 0 ? (
          <EmptyState title="No conversations" description="Start one below." />
        ) : (
          <ul className="space-y-2">
            {list.data.conversations.map((conversation) => (
              <li key={conversation.id}>
                <button
                  type="button"
                  data-thread={conversation.id}
                  onClick={() => select(conversation.id)}
                  className={
                    selected === conversation.id
                      ? 'w-full rounded border border-accent bg-accent-wash p-2 text-left text-sm'
                      : 'w-full rounded-card border border-line bg-surface shadow-raise p-2 text-left text-sm'
                  }
                >
                  <span className="block truncate font-medium">{conversation.subject}</span>
                  <span className="text-xs text-subtle">
                    {conversation.kind} · {conversation.status}
                    {conversation.unread !== undefined &&
                      conversation.unread > 0 &&
                      ` · ${String(conversation.unread)} unread`}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}

        <form
          className="mt-4 space-y-3 border-t border-line pt-4"
          onSubmit={(event) => {
            void startForm.handleSubmit((values) =>
              start.mutate(values, {
                onSuccess: (conversation) => {
                  startForm.reset();
                  select(conversation.id);
                },
              }),
            )(event);
          }}
        >
          <Field id="thread-kind" label="Kind">
            <select id="thread-kind" className={inputClass()} {...startForm.register('kind')}>
              {CONVERSATION_KINDS.map((kind) => (
                <option key={kind} value={kind}>
                  {kind}
                </option>
              ))}
            </select>
          </Field>

          <Field
            id="thread-subject"
            label="Subject"
            error={startForm.formState.errors.subject?.message}
          >
            <input
              id="thread-subject"
              className={inputClass(startForm.formState.errors.subject !== undefined)}
              {...startForm.register('subject')}
            />
          </Field>

          {start.error !== null && <ErrorSurface error={start.error} />}

          <Button type="submit" pending={start.isPending}>
            Start
          </Button>
        </form>
      </div>

      <div className="min-w-0 flex-1">
        {selected === undefined ? (
          <div className="hidden lg:block">
            <EmptyState title="No thread selected" description="Choose one to read it." />
          </div>
        ) : (
          <Thread conversationId={selected} onBack={() => select(null)} />
        )}
      </div>
    </div>
  );
}

function Thread({ conversationId, onBack }: { conversationId: string; onBack: () => void }) {
  const conversation = useConversation(conversationId);
  const messages = useMessages(conversationId);
  const post = usePostMessage(conversationId);
  const close = useCloseConversation();
  const remove = useDeleteMessage(conversationId);

  const form = useForm<z.infer<typeof replySchema>>({
    resolver: zodResolver(replySchema),
    defaultValues: { body: '' },
  });

  useMarkReadUpTo(conversationId, messages.data);

  if (conversation.isPending || messages.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (conversation.error !== null) {
    return <ErrorSurface error={conversation.error} onRetry={() => void conversation.refetch()} />;
  }

  if (messages.error !== null) {
    return <ErrorSurface error={messages.error} onRetry={() => void messages.refetch()} />;
  }

  const open = conversation.data.status === 'OPEN';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Button type="button" variant="secondary" onClick={onBack} className="lg:hidden">
          Back
        </Button>
        <h2 className="text-xl font-semibold">{conversation.data.subject}</h2>
        <span className="text-xs text-subtle">{conversation.data.status}</span>

        {open && (
          <Button
            type="button"
            variant="secondary"
            pending={close.isPending}
            onClick={() => close.mutate(conversationId)}
            className="ml-auto"
          >
            Close thread
          </Button>
        )}
      </div>

      {close.error !== null && <ErrorSurface error={close.error} />}

      <ul className="space-y-2">
        {[...messages.data]
          // A pending message carries seq 0, which no delivered message has, so it
          // sorts last without a special case.
          .sort((a, b) => a.seq - b.seq)
          .map((message) => (
            <li
              key={message.id}
              data-message={message.id}
              data-pending={message.seq === 0 ? 'true' : 'false'}
              className={
                message.seq === 0
                  ? 'rounded border border-dashed border-line-strong p-3 text-sm opacity-70'
                  : 'rounded-card border border-line bg-surface p-4 shadow-raise text-sm'
              }
            >
              <div className="flex items-baseline gap-2 text-xs text-subtle">
                <span>{message.author_kind}</span>
                {message.seq === 0 ? <span>sending…</span> : <span>#{message.seq}</span>}

                {/* Offered only for a delivered, undeleted message: deleting a
                    placeholder would ask the server to remove something it has
                    never heard of. */}
                {message.seq > 0 && !message.deleted && open && (
                  <button
                    type="button"
                    data-delete-message={message.id}
                    onClick={() => remove.mutate(message.id)}
                    className="ml-auto rounded px-1 underline decoration-dotted focus-visible:outline-2 focus-visible:outline-offset-2"
                  >
                    Delete
                  </button>
                )}
              </div>

              {message.deleted ? (
                // Kept in place rather than removed: the sequence never develops a
                // hole where a reply used to be.
                <p className="italic text-subtle">This message was deleted.</p>
              ) : (
                <p className="whitespace-pre-wrap">{message.body}</p>
              )}
            </li>
          ))}
      </ul>

      {remove.error !== null && <ErrorSurface error={remove.error} />}
      {post.error !== null && <ErrorSurface error={post.error} />}

      {open ? (
        <form
          className="space-y-3"
          onSubmit={(event) => {
            void form.handleSubmit((values) =>
              post.mutate(values.body, { onSuccess: () => form.reset() }),
            )(event);
          }}
        >
          <Field id="reply" label="Reply" error={form.formState.errors.body?.message}>
            <textarea id="reply" rows={3} className={inputClass()} {...form.register('body')} />
          </Field>

          <Button type="submit" pending={post.isPending}>
            Send
          </Button>
        </form>
      ) : (
        <p className="text-sm text-muted">
          This thread is closed. Start a new one to continue.
        </p>
      )}

      <Participants
        conversationId={conversationId}
        participants={conversation.data.participants}
        canChange={open}
      />
    </div>
  );
}

/**
 * Reads the thread up to the newest message that exists.
 *
 * The watermark belongs to the server and never goes backwards, so this reports
 * a *sequence* rather than a delta — and reports it when the sequence changes,
 * which is what the dependency array says. That is the whole guard against a
 * loop: marking read invalidates the thread, the refetch answers with the same
 * newest message, `newest` does not change, and the effect does not run again.
 *
 * A ref remembering what had already been reported was tried here and removed:
 * nothing could be made to fail without it, and a guard no test can break is a
 * guard nobody can trust. Re-reporting the same watermark would be harmless
 * anyway — the server takes the higher of the two.
 *
 * Placeholders are skipped: `seq` 0 is a message the server has not accepted, so
 * claiming to have read it would move the watermark past nothing.
 */
function useMarkReadUpTo(conversationId: string, messages: readonly { seq: number }[] | undefined) {
  const markRead = useMarkConversationRead(conversationId);

  const newest = messages?.reduce((highest, message) => Math.max(highest, message.seq), 0) ?? 0;
  const mutate = markRead.mutate;

  useEffect(() => {
    if (newest > 0) {
      mutate(newest);
    }
  }, [conversationId, newest, mutate]);
}

/**
 * Who is in the thread.
 *
 * Rendered from `showConversation`, which answers with the participants — so the
 * list is the server's and adding somebody invalidates rather than pushing a row
 * this component invented. A closed thread shows its participants and offers no
 * change: the backend refuses either way, and a control that always failed would
 * be worse than none.
 *
 * **Somebody is added by name, not by pasting an id.** The API takes a user
 * id, as it should — an id is what a person *is* here — but nobody should
 * have to fetch one from the members screen. Where the reader may list the
 * members (`members.read`), the colleagues not yet in the thread are offered
 * by name and the id travels underneath; where they may not, or the list
 * did not come, the id field stays, because a control that needs a read the
 * person is refused would be a dead one. The same list names the
 * participants already here, instead of eight characters of uuid.
 */
function Participants({
  conversationId,
  participants,
  canChange,
}: {
  conversationId: string;
  participants: readonly Participant[];
  canChange: boolean;
}) {
  const add = useAddParticipant(conversationId);
  const drop = useRemoveParticipant(conversationId);
  const { data: session } = useSession();
  const members = useMembers(can(session, 'members.read'));

  const people = new Map<string, Person>(
    (members.data ?? []).map((member) => [
      member.user_id,
      { id: member.user_id, name: member.display_name ?? null, email: member.email ?? null },
    ]),
  );
  const present = new Set(participants.filter((p) => p.left_at === null).map((p) => p.user_id));
  const candidates = [...people.values()].filter((person) => !present.has(person.id));
  const byName = members.data !== undefined;

  const form = useForm<z.infer<typeof participantSchema>>({
    resolver: zodResolver(participantSchema),
    defaultValues: { user_id: '' },
  });

  return (
    <section className="space-y-3 border-t border-line pt-4">
      <h3 className="text-sm font-semibold">Participants</h3>

      <ul className="space-y-1 text-sm">
        {participants.map((participant) => (
          <li
            key={participant.user_id}
            data-participant={participant.user_id}
            data-kind={participant.kind}
            className="flex flex-wrap items-center gap-2"
          >
            {people.has(participant.user_id) ? (
              <span className="font-medium">{personLabel(people.get(participant.user_id) as Person)}</span>
            ) : (
              <code className="text-xs">{participant.user_id.slice(0, 8)}</code>
            )}
            <span className="text-xs text-subtle">
              {participant.kind} · read to #{participant.last_read_seq}
            </span>

            {participant.left_at !== null ? (
              // Left rather than gone: the messages they wrote stay attributed,
              // so removing them from the list would leave those unexplained.
              <span className="text-xs text-subtle">left</span>
            ) : (
              canChange && (
                <button
                  type="button"
                  data-remove-participant={participant.user_id}
                  onClick={() => drop.mutate(participant.user_id)}
                  className="ml-auto rounded px-1 text-xs underline decoration-dotted focus-visible:outline-2 focus-visible:outline-offset-2"
                >
                  Remove
                </button>
              )
            )}
          </li>
        ))}
      </ul>

      {drop.error !== null && <ErrorSurface error={drop.error} />}

      {canChange && (
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            void form.handleSubmit((values) =>
              add.mutate(values.user_id, { onSuccess: () => form.reset() }),
            )(event);
          }}
        >
          {byName ? (
            <Field id="participant-user" label="Add a colleague" error={form.formState.errors.user_id?.message}>
              <PersonSelect
                id="participant-user"
                people={candidates}
                emptyLabel={candidates.length === 0 ? 'Everybody is already here' : 'Choose a colleague…'}
                disabled={candidates.length === 0}
                invalid={form.formState.errors.user_id !== undefined}
                {...form.register('user_id')}
              />
            </Field>
          ) : (
            <Field
              id="participant-user"
              label="Add by user id"
              error={form.formState.errors.user_id?.message}
            >
              <input
                id="participant-user"
                className={inputClass(form.formState.errors.user_id !== undefined)}
                {...form.register('user_id')}
              />
            </Field>
          )}

          <Button type="submit" pending={add.isPending}>
            Add
          </Button>
        </form>
      )}

      {add.error !== null && <ErrorSurface error={add.error} />}
    </section>
  );
}
