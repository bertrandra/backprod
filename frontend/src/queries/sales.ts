import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Orders — the first screens where a click cannot be taken back.
 *
 * **Nothing here is optimistic.** U3 established where optimism is safe: a
 * change that creates nothing anyone can act on. Every mutation in this file
 * creates something someone can act on — an order, a cancellation — and one of
 * them allocates money. So the server answers first, always, and the screen
 * shows what it said.
 *
 * **Quotes left on 2026-09-25**, with the organisation's own subscription that
 * a quote priced: the tenant surface sells seats, so there is nobody for a
 * quote to be addressed to here. The rows are still read from the console.
 * What the quote taught is kept where it still applies: `offer_version_id` is
 * pinned on an order, so a catalogue change cannot reprice something already
 * placed — which is the reason the freeze in `authoring.ts` matters.
 */

export type Order = Schemas['Order'];

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
