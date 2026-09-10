import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Notifications, and the settings that decide where they go.
 *
 * **The invalidation rule this milestone establishes**, reused by every screen
 * after it:
 *
 *   - a mutation whose response *is* the new state writes it into the cache;
 *   - a mutation that changes state the server derives **invalidates** and lets
 *     the server answer;
 *   - a count is never adjusted locally.
 *
 * The last one is the point of putting notifications before billing. An unread
 * badge decremented in JavaScript is right until the same person reads something
 * in another tab, and then it is wrong in a way nothing corrects. So reading a
 * notification invalidates the count and the server says what it now is —
 * one extra request to be correct rather than fast and lying.
 */

export type Notification = Schemas['Notification'];
export type Consent = Schemas['Consent'];

/** The categories and channels the contract enumerates. */
export const CATEGORIES = ['BILLING', 'ACCOUNT', 'SECURITY', 'SUPPORT', 'MARKETING'] as const;
export const CHANNELS = ['SCREEN', 'EMAIL', 'SMS', 'WHATSAPP'] as const;
export const CONSENT_CHANNELS = ['EMAIL', 'SMS', 'WHATSAPP'] as const;

export type Category = (typeof CATEGORIES)[number];
export type Channel = (typeof CHANNELS)[number];

/**
 * A security notification cannot be switched off (non-negotiable #24).
 *
 * The backend refuses it; this is what stops the screen offering a control that
 * would always fail. Shown as fixed rather than hidden — someone looking for the
 * switch should learn it does not exist, not conclude the page is broken.
 */
export const UNDISABLEABLE: readonly Category[] = ['SECURITY'];

export function isUndisableable(category: string): boolean {
  return (UNDISABLEABLE as readonly string[]).includes(category);
}

export function useNotifications(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.notifications.list(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/notifications', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * The badge in region A.
 *
 * Its own query, not a field read off the list: the badge is visible on every
 * screen and the list is not, so tying the two would make the badge disappear
 * whenever the inbox was unmounted.
 */
export function useUnreadCount(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.notifications.unread,
    // The caller decides, because the caller knows whether this session may read
    // an inbox at all. Hiding is courtesy; this stops the request rather than the
    // display, which is what keeps a refused read out of the log.
    enabled,
    queryFn: async (): Promise<number> => {
      const { data, error, response } = await client.GET(
        '/api/v1/notifications/unread-count',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.unread;
    },
    // Long enough not to be chatty, short enough that a notification arriving
    // while someone reads another screen shows up without a reload.
    refetchInterval: 60_000,
  });
}

export function useDeliveries(notificationId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.notifications.deliveries(notificationId ?? ''),
    enabled: notificationId !== null,
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET(
        '/api/v1/notifications/{notificationId}/deliveries',
        { params: { ...ambient.params, path: { notificationId: notificationId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/** Everything the count and the list depend on, invalidated together. */
async function refreshInbox(queryClient: ReturnType<typeof useQueryClient>): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: keys.notifications.unread }),
    queryClient.invalidateQueries({ queryKey: keys.notifications.lists }),
  ]);
}

export function useMarkRead() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (notificationId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.POST(
        '/api/v1/notifications/{notificationId}/read',
        { params: { ...ambient.params, path: { notificationId } } },
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => refreshInbox(queryClient),
  });
}

export function useMarkAllRead() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<void> => {
      const { error, response } = await client.POST(
        '/api/v1/notifications/read-all',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => refreshInbox(queryClient),
  });
}

export function usePreferences() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.notifications.preferences,
    queryFn: async (): Promise<Record<string, boolean>> => {
      const { data, error, response } = await client.GET(
        '/api/v1/notifications/preferences',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.preferences;
    },
  });
}

export function useSavePreference() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      category: Category;
      channel: Channel;
      enabled: boolean;
    }): Promise<Record<string, boolean>> => {
      const { data, error, response } = await client.PUT('/api/v1/notifications/preferences', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.preferences;
    },
    // The response is the whole map, so it is written rather than refetched.
    onSuccess: (preferences) =>
      queryClient.setQueryData(keys.notifications.preferences, preferences),
  });
}

export function useConsents() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.notifications.consents,
    queryFn: async (): Promise<readonly Consent[]> => {
      const { data, error, response } = await client.GET(
        '/api/v1/notifications/consents',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.consents;
    },
  });
}

export function useGrantConsent() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      channel: (typeof CONSENT_CHANNELS)[number];
      purpose: string;
      source: string;
    }): Promise<void> => {
      const { error, response } = await client.POST('/api/v1/notifications/consents', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.notifications.consents }),
  });
}

export function useRevokeConsent() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (consentId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.DELETE(
        '/api/v1/notifications/consents/{consentId}',
        { params: { ...ambient.params, path: { consentId } } },
      );

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    // Invalidated, never removed from the list: revocation is a date and the row
    // survives, because erasing it would destroy the proof that permission once
    // existed. The list must show the revoked row, so it comes from the server.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.notifications.consents }),
  });
}
