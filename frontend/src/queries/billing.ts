import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Invoices, credit notes, the billing profile — and the rule this milestone is
 * built around.
 *
 * **Nothing here is optimistic. Not one mutation.** U3 established where optimism
 * is safe: a change that creates nothing anyone can act on. Issuing an invoice
 * allocates a **gapless legal number**; a screen that assumed success would have
 * invented a document, and the number it invented would either collide with a
 * real one or leave a hole in a sequence that is not allowed to have one.
 *
 * So every mutation below waits, and the number on screen is always the one the
 * database allocated. `number` is null until issued, and the contract says why:
 * *"a draft has no legal number, and inventing a placeholder is how a gap enters
 * a sequence that must not have one."*
 *
 * **A final invoice is never edited.** It is corrected by a credit note, which
 * is its own document with its own gapless sequence — not a status on the
 * invoice. `direction` is always `CREDIT` so nothing has to infer a sign.
 *
 * **The billing profile is copied onto each document at issue.** Changing it
 * never rewrites an invoice already sent, which is why saving it invalidates
 * nothing about existing invoices: they hold their own snapshot.
 */

export type Invoice = Schemas['Invoice'];
export type CreditNote = Schemas['CreditNote'];
export type BillingProfile = Schemas['BillingProfile'];

export function useInvoices(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.invoiceList(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/billing/invoices', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useInvoice(invoiceId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.invoice(invoiceId ?? ''),
    enabled: invoiceId !== null,
    queryFn: async (): Promise<Invoice> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET(
        '/api/v1/billing/invoices/{invoiceId}',
        { params: { ...ambient.params, path: { invoiceId: invoiceId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * The rendered document.
 *
 * **Cached hard, on purpose** (ADR-035): the PDF is rendered once, at issue, and
 * stored — its ETag is the SHA-256 of the stored bytes and *"it never changes"*.
 * So a refetch could only ever return the same bytes, and `staleTime: Infinity`
 * says exactly that rather than hoping a cache header does.
 *
 * Fetched through the generated client with `parseAs: 'blob'`, which is the
 * client reading a response the contract declares as `application/pdf` — not the
 * frontend reaching for the network. There is no signed link for an invoice the
 * way there is for an asset: the document is private to the tenant and the API
 * authorises every read of it.
 *
 * Disabled until the invoice has a number. Asking earlier answers 409
 * `INVOICE_NOT_RENDERABLE`, and a screen that fired it anyway would be asking
 * for a document it has just been told does not exist.
 */
export function useInvoicePdf(invoiceId: string | null, issued: boolean) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.invoicePdf(invoiceId ?? ''),
    enabled: invoiceId !== null && issued,
    staleTime: Infinity,
    gcTime: Infinity,
    queryFn: async (): Promise<Blob> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET(
        '/api/v1/billing/invoices/{invoiceId}/pdf',
        {
          params: { ...ambient.params, path: { invoiceId: invoiceId ?? '' } },
          parseAs: 'blob',
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/** Everything an invoice change can have altered. */
async function refreshBilling(
  queryClient: ReturnType<typeof useQueryClient>,
  invoiceId: string,
): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: keys.billing.invoiceLists }),
    queryClient.invalidateQueries({ queryKey: keys.billing.invoice(invoiceId) }),
    queryClient.invalidateQueries({ queryKey: keys.billing.creditNoteLists }),
  ]);
}

/**
 * Issuing, which allocates the legal number.
 *
 * The response carries it. Until then the invoice has none, and this file offers
 * no way to pretend otherwise — there is no placeholder, no "pending number",
 * nothing written into the cache ahead of the answer.
 */
export function useIssueInvoice() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Invoice> => {
      const { data, error, response } = await client.POST(
        '/api/v1/billing/invoices',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (invoice) => {
      queryClient.setQueryData(keys.billing.invoice(invoice.id), invoice);
      await refreshBilling(queryClient, invoice.id);
    },
  });
}

export function useCancelInvoice() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (invoiceId: string): Promise<Invoice> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/billing/invoices/{invoiceId}/cancel',
        { params: { ...ambient.params, path: { invoiceId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (invoice) => {
      queryClient.setQueryData(keys.billing.invoice(invoice.id), invoice);
      await refreshBilling(queryClient, invoice.id);
    },
  });
}

/**
 * Marking an invoice paid by hand.
 *
 * For money that arrived outside the payment provider — a transfer, a cheque.
 * It is a statement about the world, not a payment, which is why it lives on the
 * invoice rather than creating one.
 */
export function useMarkInvoicePaid() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (invoiceId: string): Promise<Invoice> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/billing/invoices/{invoiceId}/pay',
        { params: { ...ambient.params, path: { invoiceId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (invoice) => {
      queryClient.setQueryData(keys.billing.invoice(invoice.id), invoice);
      await Promise.all([
        refreshBilling(queryClient, invoice.id),
        // Paying may have activated a subscription: nothing is provisioned
        // before the money arrives, and this is the money arriving.
        queryClient.invalidateQueries({ queryKey: keys.subscription.current }),
      ]);
    },
  });
}

/**
 * Crediting, which produces a **document**.
 *
 * Its own number, from its own sequence. The invoice it corrects keeps its own
 * number and its own totals — a credit note is not a negative edit of an invoice,
 * and the ledger reads both.
 */
export function useIssueCreditNote(invoiceId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (reason: string | undefined): Promise<CreditNote> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/billing/invoices/{invoiceId}/credit',
        {
          params: { ...ambient.params, path: { invoiceId } },
          body: reason === undefined || reason === '' ? {} : { reason },
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: () => refreshBilling(queryClient, invoiceId),
  });
}

export function useCreditNotes(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.creditNoteList(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/billing/credit-notes', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useBillingProfile() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.billing.profile,
    queryFn: async (): Promise<BillingProfile> => {
      const { data, error, response } = await client.GET(
        '/api/v1/billing/profile',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.profile;
    },
  });
}

/**
 * Saving the legal identity.
 *
 * The response *is* the new profile, so it is written into the cache — and
 * nothing about existing invoices is invalidated, deliberately: each document
 * carries its own snapshot of who the parties were at issue, and changing the
 * profile does not and must not rewrite one already sent.
 */
export function useSaveBillingProfile() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (profile: BillingProfile & { legal_name: string }): Promise<BillingProfile> => {
      const { data, error, response } = await client.PUT('/api/v1/billing/profile', {
        ...ambientParams(sessionSnapshot),
        body: profile,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.profile;
    },
    onSuccess: (profile) => queryClient.setQueryData(keys.billing.profile, profile),
  });
}
