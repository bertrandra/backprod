import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Payments, refunds, and the retry that is a new attempt rather than a
 * resurrection.
 *
 * **A failed payment stays failed.** `retryPayment` creates a *new* attempt
 * against the same invoice and answers with a new `client_secret`; it does not
 * revive the old one. The screen has to say so, because a person who thinks
 * their previous attempt is being resumed will not understand why the card form
 * asks again — and because the old attempt is the record of what happened, which
 * a resurrection would erase.
 *
 * **`client_secret` is returned here and nowhere else**, the contract says, and
 * it is short-lived. So it is never written into the query cache, never stored,
 * never logged, and never put in a URL — the same rule as the checkout session,
 * for the same reason.
 *
 * **`final` says whether the status can still move.** A webhook arriving after
 * that is ignored rather than applied twice, and the UI uses it to decide
 * whether a refund is offerable at all rather than guessing from the status
 * name.
 */

export type Payment = Schemas['Payment'];
export type Refund = Schemas['Refund'];

/** The status a retry exists for. */
export function isRetryable(payment: Payment): boolean {
  return payment.status === 'FAILED';
}

/** Only settled money can be given back. */
export function isRefundable(payment: Payment): boolean {
  return payment.status === 'SUCCEEDED' && payment.settled;
}

export function usePayments(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.paymentList(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/billing/payments', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function usePayment(paymentId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.payment(paymentId ?? ''),
    enabled: paymentId !== null,
    queryFn: async (): Promise<Payment> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET(
        '/api/v1/billing/payments/{paymentId}',
        { params: { ...ambient.params, path: { paymentId: paymentId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * Starting a payment against an invoice.
 *
 * Answers with the payment **and** a `client_secret`. As with checkout, the
 * secret is handed to the caller and never cached: a mutation result lives as
 * long as the component that asked, which is the whole intended lifetime.
 */
/**
 * A payment as `startPayment` and `retryPayment` answer it: the payment, the
 * one-time `client_secret`, and what a page needs to use one (ADR-048). The
 * shape exists only in the render that received it; the lists re-read the
 * payment without either.
 */
export type StartedPayment = Payment & {
  client_secret?: string | null;
  payment_provider?: Schemas['PaymentProviderClient'];
};

export function useStartPayment(invoiceId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<StartedPayment> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/billing/invoices/{invoiceId}/payments',
        { params: { ...ambient.params, path: { invoiceId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.billing.paymentLists }),
        queryClient.invalidateQueries({ queryKey: keys.billing.invoice(invoiceId) }),
      ]);
    },
  });
}

/**
 * Retrying: a new attempt.
 *
 * The old payment keeps its FAILED status and its failure reason — it is the
 * record of what happened. What comes back is a fresh secret for a fresh
 * attempt, so both lists are invalidated and nothing is written over the
 * attempt that failed.
 */
export function useRetryPayment() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (paymentId: string): Promise<StartedPayment> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/payments/{paymentId}/retry', {
        params: { ...ambient.params, path: { paymentId } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (_result, paymentId) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.billing.paymentLists }),
        queryClient.invalidateQueries({ queryKey: keys.billing.payment(paymentId) }),
        queryClient.invalidateQueries({ queryKey: keys.billing.invoiceLists }),
      ]);
    },
  });
}

/**
 * Refunding.
 *
 * `reason` is required and stored as a category — the contract upper-cases it —
 * so the screen offers the categories rather than a free-text box that would
 * produce forty spellings of "duplicate". Omitting the amount refunds everything
 * still refundable, which is the common case and is said in words rather than
 * left to be discovered.
 *
 * Answers 202: the money leaves asynchronously, so the refund is accepted rather
 * than done, and the payment is invalidated instead of being marked refunded
 * here.
 */
export function useRefundPayment() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      paymentId: string;
      reason: string;
      amount_minor_units?: number;
    }): Promise<Refund> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/billing/payments/{paymentId}/refund',
        {
          params: { ...ambient.params, path: { paymentId: input.paymentId } },
          body:
            input.amount_minor_units === undefined
              ? { reason: input.reason }
              : { reason: input.reason, amount_minor_units: input.amount_minor_units },
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (_refund, input) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.billing.paymentLists }),
        queryClient.invalidateQueries({ queryKey: keys.billing.payment(input.paymentId) }),
        // A refund may produce a credit note.
        queryClient.invalidateQueries({ queryKey: keys.billing.creditNoteLists }),
      ]);
    },
  });
}
