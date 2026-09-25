import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The subscription, and the two things §13.1 refuses to conflate.
 *
 * **Periodicity is not commitment** (non-negotiable #23). How often somebody is
 * billed and how long they agreed to stay are different facts, and a screen that
 * showed one number would be answering a question nobody asked. So both travel
 * separately here and are rendered separately above.
 *
 * **A cancellation is a decision, not a boolean.** `CancellationDecision` carries
 * the rule that decided, when it takes effect, how many months of commitment are
 * still owed and the reasons in plain words — because what a customer needs is
 * not `cancelled: true` but *when*, and which rule says so. All of it is shown.
 *
 * Nothing in this file is optimistic. Cancelling and changing offer both move
 * money, and every one of them reconciles from the server.
 */

export type Subscription = Schemas['Subscription'];
export type CancellationDecision = Schemas['CancellationDecision'];
export type Entitlement = Schemas['Entitlement'];
export type HeldSubscription = Schemas['HeldSubscription'];

/**
 * What everybody in the organisation holds (2026-09-25).
 *
 * The counterpart of `useSubscription`, which answers for the caller. Since
 * the tenant surface sells seats only (ADR-055), an organisation's
 * subscriptions belong to its people one by one — an administrator does not
 * buy, they administer, and nothing else shows the set together.
 *
 * `enabled` is asked at the call site on `subscription.manage`, because a
 * member holding only the read would get a 403 for a question they never put.
 *
 * `live` and `places_used` are the server's answers, never recomputed here.
 * A screen deriving "live" from `current_period_end` would disagree with the
 * server a second later, and one counting places itself would be describing a
 * quota the server does not enforce.
 */
export function useOrganisationSubscriptions(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.subscription.organisation,
    enabled,
    queryFn: async (): Promise<HeldSubscription[]> => {
      const { data, error, response } = await client.GET(
        '/api/v1/organisation/subscriptions',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.subscriptions;
    },
  });
}

export function useSubscription(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.subscription.current,
    enabled,
    queryFn: async () => {
      const { data, error, response } = await client.GET(
        '/api/v1/subscription',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * The schedule, and what cancelling *now* would do.
 *
 * `if_cancelled_now` is a preview the backend computes, which is the only
 * honest way to answer "what happens if I leave": the rules live in the Core and
 * a frontend working it out from `current_period_end` would produce a second
 * answer that disagrees at every commitment boundary.
 */
export function useSchedule() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.subscription.schedule,
    queryFn: async () => {
      const { data, error, response } = await client.GET(
        '/api/v1/subscription/schedule',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useEntitlements() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.subscription.entitlements,
    queryFn: async (): Promise<readonly Entitlement[]> => {
      const { data, error, response } = await client.GET(
        '/api/v1/entitlements',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.entitlements;
    },
  });
}

/** Everything a change to the subscription may have altered. */
async function refreshSubscription(
  queryClient: ReturnType<typeof useQueryClient>,
): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: keys.subscription.current }),
    queryClient.invalidateQueries({ queryKey: keys.subscription.schedule }),
    // Entitlements come from the subscription's grants, so a changed offer
    // changes what the tenant may do. Asking again is the only way to know.
    queryClient.invalidateQueries({ queryKey: keys.subscription.entitlements }),
    // And it may have raised an invoice.
    queryClient.invalidateQueries({ queryKey: keys.billing.invoiceLists }),
    // And the menu's empty-hiding entries may now have something (2026-09-19).
    queryClient.invalidateQueries({ queryKey: keys.navigation.mine }),
  ]);
}

export function useChangeOffer() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (offerId: string): Promise<Subscription> => {
      const { data, error, response } = await client.POST('/api/v1/subscription/change-offer', {
        ...ambientParams(sessionSnapshot),
        body: { offer_id: offerId },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () => refreshSubscription(queryClient),
  });
}

/**
 * Cancelling.
 *
 * `immediately` is a **request**, not an instruction — the contract says so and
 * the screen must too: "the policy still decides; asking does not make it so".
 * The answer comes back as a decision, and the decision is what gets shown.
 */
export function useCancelSubscription() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { immediately?: boolean; seat?: boolean }) => {
      const { data, error, response } = await client.POST('/api/v1/subscription/cancel', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () => refreshSubscription(queryClient),
  });
}

export type SubscriptionPeople = Schemas['SubscriptionPeople'];
export type SubscriptionMember = Schemas['SubscriptionMember'];

/**
 * The people a subscription covers, and how many it may (2026-09-19).
 * `seat` names the caller's own seat rather than the organisation's.
 */
export function usePeople(seat: boolean, enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.subscription.people(seat),
    enabled,
    queryFn: async (): Promise<SubscriptionPeople> => {
      const ambient = ambientParams(sessionSnapshot);
      const { data, error, response } = await client.GET('/api/v1/subscription/people', {
        params: { ...ambient.params, query: seat ? { seat: '1' } : {} },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      // Tolerant of a stubbed or older server: a missing list is an empty one.
      return { ...data, members: data.members ?? [], quota: data.quota ?? null, owner: data.owner ?? false };
    },
    retry: false,
  });
}

/** The owner adds somebody: a member by id, or anybody by address. Nothing optimistic — an account may be created. */
export function useAddPerson(seat: boolean) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (who: { user_id: string } | { email: string }) => {
      const { data, error, response } = await client.POST('/api/v1/subscription/people', {
        ...ambientParams(sessionSnapshot),
        body: { seat, ...who },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.subscription.people(seat) }),
  });
}

export function useRemovePerson(seat: boolean) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);
      const { error, response } = await client.DELETE('/api/v1/subscription/people/{userId}', {
        params: { ...ambient.params, path: { userId }, query: seat ? { seat: '1' } : {} },
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.subscription.people(seat) }),
  });
}

export function useResumeSubscription() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Subscription> => {
      const { data, error, response } = await client.POST(
        '/api/v1/subscription/resume',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () => refreshSubscription(queryClient),
  });
}
