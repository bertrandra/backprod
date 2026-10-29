import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';

import { ApiProvider } from '@/app/providers/ApiProvider';
import { useSessionStore } from '@/state/session';
import { stubClient, type Stubs } from '@/test-utils';

import { keys } from './keys';
import { useRefundPayment } from './payments';
import { useCancelSubscription } from './subscription';

/**
 * What a mutation marks stale, asserted on the cache itself.
 *
 * A screen test sees only what that screen renders, so a mutation that forgot
 * a key another screen reads passes every one of them — the invoice still
 * reading PAID after a full refund, the people list still bounded by the
 * quota of an offer just left.
 */
function harness(responses: Stubs) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <ApiProvider client={stubClient(responses)}>{children}</ApiProvider>
    </QueryClientProvider>
  );

  return { queryClient, wrapper };
}

function stale(queryClient: QueryClient, key: readonly unknown[]): boolean | undefined {
  return queryClient.getQueryState(key)?.isInvalidated;
}

beforeEach(() => {
  useSessionStore.setState({ token: 't', productCode: 'atlas', status: 'signed-in', expiresAt: null });
});

describe('refunding a payment', () => {
  it('asks again about the invoice it settled, which a full refund moves to CREDITED', async () => {
    const { queryClient, wrapper } = harness({
      'POST /api/v1/billing/payments/{paymentId}/refund': { data: {}, status: 202 },
    });
    queryClient.setQueryData(keys.billing.invoiceList(20, 0), { items: [] });
    queryClient.setQueryData(keys.billing.invoice('inv-1'), { id: 'inv-1', status: 'PAID' });
    queryClient.setQueryData(keys.billing.invoice('inv-2'), { id: 'inv-2', status: 'PAID' });

    const { result } = renderHook(() => useRefundPayment(), { wrapper });

    await act(async () => {
      await result.current.mutateAsync({ paymentId: 'pay-1', reason: 'DUPLICATE', invoiceId: 'inv-1' });
    });

    expect(stale(queryClient, keys.billing.invoiceList(20, 0))).toBe(true);
    expect(stale(queryClient, keys.billing.invoice('inv-1'))).toBe(true);
    // Another invoice's screen has nothing new to say.
    expect(stale(queryClient, keys.billing.invoice('inv-2'))).toBe(false);
  });
});

describe('a change to the subscription', () => {
  it('asks again who it covers, on both the seat and the organisation list', async () => {
    const { queryClient, wrapper } = harness({
      'POST /api/v1/subscription/cancel': { data: { effective_at: '2026-11-01' } },
    });
    queryClient.setQueryData(keys.subscription.people(true), { members: [] });
    queryClient.setQueryData(keys.subscription.people(false), { members: [] });

    const { result } = renderHook(() => useCancelSubscription(), { wrapper });

    await act(async () => {
      await result.current.mutateAsync({ seat: true });
    });

    expect(stale(queryClient, keys.subscription.people(true))).toBe(true);
    expect(stale(queryClient, keys.subscription.people(false))).toBe(true);
  });
});
