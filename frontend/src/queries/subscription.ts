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

/** One page of the organisation's register, with the total behind it. */
export type HeldSubscriptionPage = {
  readonly subscriptions: readonly HeldSubscription[];
  readonly total: number;
};

/**
 * What everybody in the organisation holds (2026-09-25).
 *
 * The counterpart of `useSubscription`, which answers for the caller. Since
 * the tenant surface sells seats only (ADR-055), an organisation's
 * subscriptions belong to its people one by one — an administrator does not
 * buy, they administer, and nothing else shows the set together.
 *
 * `enabled` is asked at the call site on **`tenant.manage`**, which is the
 * administrator's and nobody else's. This said `subscription.manage` for a
 * day, which is the word that reads right and is not: a USER holds it too,
 * because managing the people on a seat is what a seat holder does with their
 * own. Gating on it showed every member the whole register, and the docblock
 * went on recommending it after the screen was fixed (2026-09-26).
 *
 * **Paged**, because every cancelled seat stays for ever and this list grows
 * with the organisation. The living come first from the server, so a page is
 * never re-sorted here: sorting one page locally would put page two's live
 * seats below page one's dead ones.
 *
 * `live` and `places_used` are the server's answers, never recomputed here.
 * A screen deriving "live" from `current_period_end` would disagree with the
 * server a second later, and one counting places itself would be describing a
 * quota the server does not enforce.
 */
export function useOrganisationSubscriptions(enabled = true, limit = 50, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.subscription.organisationPage(limit, offset),
    enabled,
    queryFn: async (): Promise<HeldSubscriptionPage> => {
      const { data, error, response } = await client.GET('/api/v1/organisation/subscriptions', {
        ...ambientParams(sessionSnapshot),
        params: {
          ...ambientParams(sessionSnapshot).params,
          query: { limit, offset },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return { subscriptions: data.subscriptions, total: data.total };
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

/**
 * A change to who a subscription covers, and the one other thing that shows
 * it (2026-09-26): the administrator's register, whose `places_used` is the
 * same number counted server-side.
 */
async function refreshPeople(
  queryClient: ReturnType<typeof useQueryClient>,
  seat: boolean,
): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: keys.subscription.people(seat) }),
    queryClient.invalidateQueries({ queryKey: keys.subscription.organisation }),
  ]);
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
    // And the administrator's register, which shows this subscription among
    // everybody else's (2026-09-26). Nothing invalidated it for a day, so an
    // administrator who cancelled their own seat and opened the register saw
    // it still live for as long as the default staleness lasted.
    queryClient.invalidateQueries({ queryKey: keys.subscription.organisation }),
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
    onSuccess: () => refreshPeople(queryClient, seat),
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
    onSuccess: () => refreshPeople(queryClient, seat),
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
