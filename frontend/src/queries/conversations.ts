import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

export type Conversation = Schemas['Conversation'];
export type Message = Schemas['Message'];
/** `showConversation` answers with the thread *and* who is in it. */
export type Participant = Schemas['Participant'];

export const CONVERSATION_KINDS = ['INTERNAL', 'SUPPORT'] as const;
export type ConversationKind = (typeof CONVERSATION_KINDS)[number];

export function useConversations(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.conversations.list(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/conversations', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useConversation(conversationId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.conversations.one(conversationId ?? ''),
    enabled: conversationId !== null,
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/conversations/{conversationId}', {
        params: { ...ambient.params, path: { conversationId: conversationId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * A thread's messages.
 *
 * `since_seq` exists for incremental fetching, and this deliberately does **not**
 * use it yet: paging a growing thread by sequence needs a merge strategy, and a
 * half-built one that occasionally drops a message is worse than fetching the
 * page. What it does use is `seq` — every message has a consecutive per-thread
 * number, and a deleted message keeps its place, so ordering never depends on a
 * timestamp two messages might share.
 */
export function useMessages(conversationId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.conversations.messages(conversationId ?? ''),
    enabled: conversationId !== null,
    queryFn: async (): Promise<readonly Message[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET(
        '/api/v1/conversations/{conversationId}/messages',
        { params: { ...ambient.params, path: { conversationId: conversationId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.messages;
    },
  });
}

export function useStartConversation() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      kind: ConversationKind;
      subject: string;
    }): Promise<Conversation> => {
      const { data, error, response } = await client.POST('/api/v1/conversations', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.conversations.lists }),
  });
}

/**
 * Posting a message — the one optimistic update in this milestone.
 *
 * It is safe here for the reason it is *not* safe in U6: a message has no legal
 * number, no gapless sequence this client allocates, and no money attached. If
 * the post fails the placeholder is rolled back and the failure is visible, and
 * nothing was created that anyone can act on in the meantime.
 *
 * The placeholder carries `seq: 0`, which no real message has — the contract's
 * minimum is 1. So a pending message cannot be mistaken for a delivered one by
 * any code that reads `seq`, including this file's own sort.
 */
export function usePostMessage(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();
  const key = keys.conversations.messages(conversationId);

  return useMutation({
    mutationFn: async (body: string): Promise<Message> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/conversations/{conversationId}/messages',
        { params: { ...ambient.params, path: { conversationId } }, body: { body } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },

    onMutate: async (body: string) => {
      await queryClient.cancelQueries({ queryKey: key });

      const previous = queryClient.getQueryData<readonly Message[]>(key);

      queryClient.setQueryData<readonly Message[]>(key, (messages) => [
        ...(messages ?? []),
        {
          id: `pending-${String(Date.now())}`,
          seq: 0,
          author_user_id: null,
          author_kind: 'MEMBER',
          body,
          deleted: false,
          created_at: new Date().toISOString(),
          edited_at: null,
        },
      ]);

      return { previous };
    },

    onError: (_error, _body, context) => {
      // Put the thread back. A failed message that stayed on screen would look
      // sent, and the person would not say it again.
      if (context?.previous !== undefined) {
        queryClient.setQueryData(key, context.previous);
      }
    },

    // Refetched either way: the server assigns the id and the sequence, and the
    // placeholder was never the real message.
    //
    // Deliberately not awaited. Returning the promise would keep the mutation
    // *pending* until the refetch answered — so on a slow network a failed post
    // would show a button still saying "Working…" and no reason, while its text
    // had already been taken back. The refetch reconciles; it is not part of the
    // post's outcome.
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: key });
    },
  });
}

export function useMarkConversationRead(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (seq: number): Promise<number> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/conversations/{conversationId}/read',
        { params: { ...ambient.params, path: { conversationId } }, body: { seq } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.last_read_seq;
    },
    // The watermark lives on the participant row and the per-thread unread count
    // is derived from it, so both the list and the thread come from the server
    // again. Neither count is adjusted here — the same rule as the notification
    // badge, and for the same reason: another tab may have read further.
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.conversations.lists }),
        queryClient.invalidateQueries({ queryKey: keys.conversations.one(conversationId) }),
      ]);
    },
  });
}

export function useCloseConversation() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.POST(
        '/api/v1/conversations/{conversationId}/close',
        { params: { ...ambient.params, path: { conversationId } } },
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: async (_result, conversationId) => {
      await queryClient.invalidateQueries({ queryKey: keys.conversations.lists });
      await queryClient.invalidateQueries({ queryKey: keys.conversations.one(conversationId) });
    },
  });
}

export function useDeleteMessage(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (messageId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.DELETE(
        '/api/v1/conversations/{conversationId}/messages/{messageId}',
        { params: { ...ambient.params, path: { conversationId, messageId } } },
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    // Invalidated rather than removed locally: a deleted message keeps its place
    // in the sequence and comes back marked deleted, so the thread never
    // develops a hole where a reply used to be.
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: keys.conversations.messages(conversationId) }),
  });
}

export function useAddParticipant(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.POST(
        '/api/v1/conversations/{conversationId}/participants',
        { params: { ...ambient.params, path: { conversationId } }, body: { user_id: userId } },
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: keys.conversations.one(conversationId) }),
  });
}

export function useRemoveParticipant(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.DELETE(
        '/api/v1/conversations/{conversationId}/participants/{userId}',
        { params: { ...ambient.params, path: { conversationId, userId } } },
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: keys.conversations.one(conversationId) }),
  });
}
