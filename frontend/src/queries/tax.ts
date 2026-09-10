import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The tenant's own fiscal data (§25.3), and the one action here that cannot be
 * undone.
 *
 * **Closing a VAT period is one-way**, and a database trigger enforces it:
 * *"figures somebody has declared do not quietly change underneath them"*. So
 * there is no reopen operation in the contract, none here, and the screen offers
 * no path to one — the absence is the design, as it was for a published offer
 * version in U5.
 *
 * **A closed period reports what was declared, not what it would compute now.**
 * The declaration is frozen at closure and reading it back is reading *that*,
 * because a recomputation would answer a different question from the one that
 * was filed.
 *
 * **The calculator is a diagnostic.** It answers what would be applied *and why*
 * — the rule id, the regime, the customer's tax status, the reasons in words. An
 * invoice priced under a regime the customer disputes is a conversation, and
 * those fields are what the conversation needs.
 */

export type TaxProfile = Schemas['TaxProfile'];
export type TaxRate = Schemas['TaxRate'];
export type TaxCalculation = Schemas['TaxCalculation'];
export type VatPeriod = Schemas['VatPeriod'];
export type VatDeclaration = Schemas['VatDeclaration'];
export type VatTransaction = Schemas['VatTransaction'];

/** A period that has been declared. Closure is one-way. */
export function isClosed(period: VatPeriod): boolean {
  return period.status === 'CLOSED';
}

export function useTaxProfile() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.tax.profile,
    queryFn: async (): Promise<TaxProfile> => {
      const { data, error, response } = await client.GET(
        '/api/v1/tax/profile',
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
 * Saving the profile.
 *
 * The VAT number is **normalised and re-checked** by the backend, so the answer
 * may disagree with what was typed — a number that was verified yesterday can
 * come back `UNAVAILABLE` today if the verification service is down. The response
 * is therefore written into the cache and nothing is assumed about the status.
 */
export function useSaveTaxProfile() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      customer_kind: 'B2B' | 'B2C';
      country_code?: string | null;
      taxable_person?: boolean;
      vat_number?: string | null;
    }): Promise<TaxProfile> => {
      const { data, error, response } = await client.PUT('/api/v1/tax/profile', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.profile;
    },
    onSuccess: (profile) => queryClient.setQueryData(keys.tax.profile, profile),
  });
}

/**
 * The rates in force on a date.
 *
 * `on` matters: rates carry validity windows so a correction closes one window
 * and opens another rather than restating an invoice already issued (R7). Asking
 * "what is the rate" without saying *when* is asking the wrong question.
 */
export function useTaxRates(on: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.tax.rates(on ?? ''),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/tax/rates', {
        params: {
          ...ambient.params,
          query: on === null || on === '' ? {} : { on },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * The diagnostic.
 *
 * A mutation rather than a query because it is a question somebody asks, not
 * state that exists — and caching an answer keyed by an amount would mean a
 * changed profile silently returned yesterday's regime.
 */
export function useCalculateTax() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (input: {
      amount_minor_units: number;
      currency?: string;
      supply_type?: string | null;
    }): Promise<TaxCalculation> => {
      const { data, error, response } = await client.POST('/api/v1/tax/calculate', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.calculation;
    },
  });
}

export function useVatPeriods() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.tax.periods,
    queryFn: async (): Promise<readonly VatPeriod[]> => {
      const { data, error, response } = await client.GET(
        '/api/v1/tax/reports',
        ambientParams(sessionSnapshot),
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.periods;
    },
  });
}

/**
 * One period, its totals, and its declaration if it has one.
 *
 * `declaration` is null while the period is open — there is nothing filed yet —
 * and the screen has to be able to say that rather than render an empty
 * declaration.
 */
export function useVatPeriod(periodId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.tax.period(periodId ?? ''),
    enabled: periodId !== null,
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/tax/reports/{periodId}', {
        params: { ...ambient.params, path: { periodId: periodId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useVatTransactions(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.tax.transactions(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/tax/transactions', {
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
 * Closing a period. **One-way.**
 *
 * Not optimistic, and not because of a rule about caches: the declaration's
 * numbers are computed at closure and this client cannot know them. Assuming
 * them would be inventing a filing.
 */
export function useCloseVatPeriod() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (periodId: string): Promise<VatDeclaration> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/tax/reports/{periodId}/close',
        { params: { ...ambient.params, path: { periodId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.declaration;
    },
    onSuccess: async (_declaration, periodId) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.tax.periods }),
        queryClient.invalidateQueries({ queryKey: keys.tax.period(periodId) }),
      ]);
    },
  });
}
