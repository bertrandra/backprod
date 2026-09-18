import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Checkout, and the two things about it that shape every screen above.
 *
 * **A checkout session is an order** (ADR-034). There is no `checkout_sessions`
 * table, `id` *is* the order's id, and `status` is derived on read rather than
 * stored. So this file does not model a session lifecycle — there is only one
 * lifecycle, the order's, and a second one here would be a second answer that
 * could disagree with the one the money is attached to.
 *
 * That is also what makes a dropped connection survivable, which is U5's exit
 * criterion: whatever happened to the browser, the order exists, it is in
 * `/orders`, and its id is in the URL. Nothing needs to be recovered because
 * nothing was only in the tab.
 *
 * **`client_secret` is returned once and is never stored.** It comes back from
 * `openCheckoutSession` and from nowhere else — not from the read, not from a
 * refetch. So it is deliberately kept out of the query cache: a mutation result
 * lives as long as the component that asked, and that is the whole intended
 * lifetime. It is never written to `localStorage`, never put in the URL, and
 * never logged. A reload cannot recover it, and the honest consequence is that
 * paying again means opening a new attempt rather than resuming the old one.
 */

export type CheckoutSession = Schemas['CheckoutSession'];

/**
 * A session as `openCheckoutSession` answers it: the session, the one-time
 * `client_secret`, and what a page needs to use one (ADR-048). This shape
 * exists only in the render that received it.
 */
export type OpenedCheckoutSession = CheckoutSession & {
  client_secret?: string | null;
  payment_provider?: Schemas['PaymentProviderClient'];
};

/** The status the API derives. `PAYMENT_FAILED` is the one a retry exists for. */
export type CheckoutStatus = CheckoutSession['status'];

/**
 * A session whose money has not arrived yet.
 *
 * Activation is payment-gated and the payment arrives through a webhook, so the
 * page cannot know when it lands except by asking. `PAYMENT_FAILED` is *not*
 * unsettled: the last attempt is dead and nothing further is coming without
 * somebody acting, so polling it would be waiting for news that will not come.
 */
export function isAwaitingPayment(status: CheckoutStatus): boolean {
  return status === 'AWAITING_PAYMENT';
}

/** Matches the job poll: fast enough not to look frozen, slow enough to be polite. */
export const POLL_MS = 2_000;

/**
 * Opening a session.
 *
 * The response carries the `client_secret`, and this is the only place it will
 * ever exist. It is returned to the caller and **not** written into any cache:
 * `setQueryData` here would put a payment credential somewhere a devtools panel
 * displays it and a later refetch would silently drop it anyway, which is the
 * worst of both.
 *
 * The order lists are invalidated, because an order now exists.
 *
 * `seat` says who the purchase is for (§13.1, 2026-09-18): the caller's own
 * seat when true, the organisation when false. A flag and never an id — the
 * only two subscribers are the organisation and the person asking, both of
 * which the server already knows.
 */
export type OpenCheckoutInput = { offerId: string; seat: boolean };

export function useOpenCheckoutSession() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ offerId, seat }: OpenCheckoutInput): Promise<OpenedCheckoutSession> => {
      const { data, error, response } = await client.POST('/api/v1/checkout/sessions', {
        ...ambientParams(sessionSnapshot),
        body: { offer_id: offerId, seat },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.session;
    },
    onSuccess: async (session) => {
      // The *session* is cached — everything except the secret is a plain read
      // that a reload can repeat. The secret is dropped by the type the read
      // returns, which is how it stays out.
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.sales.orderLists }),
        queryClient.invalidateQueries({ queryKey: keys.checkout.session(session.id) }),
      ]);
    },
  });
}

/**
 * Reading a session back.
 *
 * This is what a reload gets, and what a link to `/checkout/{id}` opens: the
 * order's state, with no secret. It polls while the money is outstanding and
 * stops as soon as the answer is final — including on `PAYMENT_FAILED`, which is
 * final until somebody retries.
 */
export function useCheckoutSession(sessionId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.checkout.session(sessionId ?? ''),
    enabled: sessionId !== null,
    queryFn: async (): Promise<CheckoutSession> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/checkout/sessions/{sessionId}', {
        params: { ...ambient.params, path: { sessionId: sessionId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.session;
    },
    refetchInterval: (query) => {
      const session = query.state.data;

      return session !== undefined && isAwaitingPayment(session.status) ? POLL_MS : false;
    },
  });
}
