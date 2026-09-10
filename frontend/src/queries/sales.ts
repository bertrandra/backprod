import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Quotes and orders — the first screens where a click cannot be taken back.
 *
 * **Nothing here is optimistic.** U3 established where optimism is safe: a
 * change that creates nothing anyone can act on. Every mutation in this file
 * creates something someone can act on — an order, a rejection, a cancellation —
 * and two of them allocate money. So the server answers first, always, and the
 * screen shows what it said.
 *
 * Three facts the contract states that the screens have to respect:
 *
 *   - **`open` is derived from the clock**, so an expired quote reads as closed
 *     without anything having swept it. A screen must not compute expiry from
 *     `valid_until` itself and reach a different answer a second later;
 *   - **`offer_version_id` is pinned** on a quote, so a catalogue change cannot
 *     reprice something already sent. That is the reason the freeze in
 *     `authoring.ts` matters;
 *   - **accepting a quote answers with an order**, not with a quote. Acceptance
 *     is a state change on one document and the creation of another, and the
 *     screen has to follow the person to the thing that now exists.
 */

export type Quote = Schemas['Quote'];
export type Order = Schemas['Order'];

export function useQuotes(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.sales.quoteList(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/sales/quotes', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useQuote(quoteId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.sales.quote(quoteId ?? ''),
    enabled: quoteId !== null,
    queryFn: async (): Promise<Quote> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/sales/quotes/{quoteId}', {
        params: { ...ambient.params, path: { quoteId: quoteId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useCreateQuote() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { offer_id: string; validity_days?: number }): Promise<Quote> => {
      const { data, error, response } = await client.POST('/api/v1/sales/quotes', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (quote) => {
      queryClient.setQueryData(keys.sales.quote(quote.id), quote);
      await queryClient.invalidateQueries({ queryKey: keys.sales.quoteLists });
    },
  });
}

/**
 * Accepting, which produces an **order**.
 *
 * Both lists are invalidated: a quote left the open set and an order joined the
 * list. The order is written into the cache from the response so the screen can
 * go straight to it — the person accepted in order to get somewhere, and making
 * them wait for a refetch to find out where would be a worse answer than the one
 * the server already gave.
 */
export function useAcceptQuote() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (quoteId: string): Promise<Order> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/sales/quotes/{quoteId}/accept',
        { params: { ...ambient.params, path: { quoteId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (order, quoteId) => {
      queryClient.setQueryData(keys.sales.order(order.id), order);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.sales.quote(quoteId) }),
        queryClient.invalidateQueries({ queryKey: keys.sales.quoteLists }),
        queryClient.invalidateQueries({ queryKey: keys.sales.orderLists }),
      ]);
    },
  });
}

/**
 * Rejecting: the first confirmed irreversible action in the application.
 *
 * There is no un-reject. The response is the quote in its new state, so it is
 * written — and the list is invalidated, because `open` is derived and the
 * server decides what the set of open quotes now is.
 */
export function useRejectQuote() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (quoteId: string): Promise<Quote> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/sales/quotes/{quoteId}/reject',
        { params: { ...ambient.params, path: { quoteId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (quote) => {
      queryClient.setQueryData(keys.sales.quote(quote.id), quote);
      await queryClient.invalidateQueries({ queryKey: keys.sales.quoteLists });
    },
  });
}

export function useOrders(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.sales.orderList(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/sales/orders', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useOrder(orderId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.sales.order(orderId ?? ''),
    enabled: orderId !== null,
    queryFn: async (): Promise<Order> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/sales/orders/{orderId}', {
        params: { ...ambient.params, path: { orderId: orderId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function usePlaceOrder() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (offerId: string): Promise<Order> => {
      const { data, error, response } = await client.POST('/api/v1/sales/orders', {
        ...ambientParams(sessionSnapshot),
        body: { offer_id: offerId },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (order) => {
      queryClient.setQueryData(keys.sales.order(order.id), order);
      await queryClient.invalidateQueries({ queryKey: keys.sales.orderLists });
    },
  });
}

/**
 * Fulfilling — which raises the invoice, and does **not** start the subscription.
 *
 * The payment gate is readable straight off the order: an `invoice_id` from
 * fulfilment onwards, a `subscription_id` only once that invoice is paid
 * (non-negotiable #20). So this invalidates rather than assuming either: what
 * fulfilment produced is the server's answer, and a screen that wrote
 * `subscription_id` itself would be provisioning before the money arrived.
 */
export function useFulfilOrder() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (orderId: string): Promise<Order> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/sales/orders/{orderId}/fulfil',
        { params: { ...ambient.params, path: { orderId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (order) => {
      queryClient.setQueryData(keys.sales.order(order.id), order);
      await queryClient.invalidateQueries({ queryKey: keys.sales.orderLists });
    },
  });
}

export function useCancelOrder() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (orderId: string): Promise<Order> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/sales/orders/{orderId}/cancel',
        { params: { ...ambient.params, path: { orderId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (order) => {
      queryClient.setQueryData(keys.sales.order(order.id), order);
      await queryClient.invalidateQueries({ queryKey: keys.sales.orderLists });
    },
  });
}
