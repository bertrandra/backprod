import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { Operations, Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Administration: the numbers, the directory, the queue, the trail, and erasure.
 *
 * **No ambient headers**, for the reason `queries/staff.ts` gives at length: an
 * administrator is not inside a tenant, so there is none to send.
 *
 * **Every list here is a page with a counted total.** The contract explains why
 * the total is counted rather than inferred: *"an operator needs to know whether
 * they are looking at forty customers or four thousand, and 'the page came back
 * short' answers that only on the last one."*
 *
 * **Erasure is the only mutation, and it is the one that cannot be undone.** It
 * anonymises what may go and keeps what the law requires, reporting both — see
 * `useEraseUser` below.
 */

export type AdminTenant = Schemas['AdminTenant'];
export type AdminUser = Schemas['AdminUser'];
export type AdminSubscription = Schemas['AdminSubscription'];
export type AdminInvoice = Schemas['AdminInvoice'];
export type AdminJob = Schemas['AdminJob'];
export type AuditEntry = Schemas['AuditEntry'];

/**
 * The financial dashboard, for one product over a window.
 *
 * `product_id` is required and there is no "all products" answer, deliberately:
 * turnover summed across products with different currencies and different
 * catalogues is a number nobody could defend. `month` is separate from `months`
 * because *top offers over a year* and *top offers last month* are different
 * questions, and the contract says so.
 */
export function useMetrics(productId: string | null, months = 12, month: string | null = null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.metrics(productId ?? '', months, month ?? ''),
    enabled: productId !== null,
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/metrics', {
        params: {
          query: {
            product_id: productId ?? '',
            months,
            ...(month === null || month === '' ? {} : { month }),
          },
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
 * Is the runner alive.
 *
 * `stale_after` is what turns a clock into a verdict: *"supplying it asks for a
 * verdict; omitting it asks only for the clock."* This screen always asks for
 * the verdict, because "has the runner run since Tuesday" is the question, and a
 * threshold the operator can see is better than one each reader invents.
 *
 * Polled, because liveness that only updates on a reload is liveness nobody
 * watches — and `never_ran` is read out loud rather than left to be inferred
 * from zeroes, which is exactly what the contract warns about: *"that state is
 * all zeroes and reads exactly like a calm idle queue."*
 */
export const QUEUE_POLL_MS = 15_000;
export const DEFAULT_STALE_AFTER_SECONDS = 300;

export function useQueueHealth(staleAfterSeconds = DEFAULT_STALE_AFTER_SECONDS) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.queue(staleAfterSeconds),
    refetchInterval: QUEUE_POLL_MS,
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/queue', {
        params: { query: { stale_after: staleAfterSeconds } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useAdminJobs(status: string | null, type: string | null, limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.jobs(status ?? '', type ?? '', limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/jobs', {
        params: {
          query: {
            limit,
            offset,
            ...(status === null || status === '' ? {} : { status }),
            ...(type === null || type === '' ? {} : { type }),
          },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useAdminTenants(search: string, limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.directory('tenants', search, limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/tenants', {
        params: { query: { limit, offset, ...(search === '' ? {} : { search }) } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * The user directory — where an erased person still has a row.
 *
 * `erased_at` set means anonymised: *"the identity is gone and the record is
 * kept."* The row surviving is the design, not a leak, and the contract is blunt
 * about what that costs the search: *"an erased person matches neither, having
 * neither."* So searching by name will never find them again, which is what
 * erasure means.
 */
export function useAdminUsers(search: string, limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.directory('users', search, limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/users', {
        params: { query: { limit, offset, ...(search === '' ? {} : { search }) } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useAdminSubscriptions(status: string, limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.directory('subscriptions', status, limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/subscriptions', {
        params: { query: { limit, offset, ...(status === '' ? {} : { status }) } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useAdminInvoices(status: string, limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.directory('invoices', status, limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/invoices', {
        params: { query: { limit, offset, ...(status === '' ? {} : { status }) } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * One customer's subscriptions and invoices, for the console's tenant
 * workspace — the same listings the Directory pages through, narrowed to a
 * tenant and, when the product picker says so, to one of its products.
 *
 * Behind `admin.finance.read` like the Directory, and with no motive: these
 * are the platform's own finance listings, which the Directory already opens
 * without one. What the tenant's *members* are is a different question with a
 * different door (`useStaffTenantMembers`).
 */
export function useAdminTenantSubscriptions(tenantId: string | null, productId: string | null, limit = 50) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.tenantDirectory('subscriptions', tenantId ?? '', productId ?? '', limit, 0),
    enabled: tenantId !== null,
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/subscriptions', {
        params: {
          query: {
            limit,
            offset: 0,
            tenant_id: tenantId ?? '',
            ...(productId === null ? {} : { product_id: productId }),
          },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useAdminTenantInvoices(tenantId: string | null, productId: string | null, limit = 50) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.tenantDirectory('invoices', tenantId ?? '', productId ?? '', limit, 0),
    enabled: tenantId !== null,
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/invoices', {
        params: {
          query: {
            limit,
            offset: 0,
            tenant_id: tenantId ?? '',
            ...(productId === null ? {} : { product_id: productId }),
          },
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
 * The audit trail.
 *
 * `actor` carries both the id and whether it was erased, and the contract says
 * why that matters: *"an act nobody performed stays distinguishable from one
 * whose performer has since been forgotten. Collapsing them into a bare null
 * would turn every erasure into a system action."* The screen renders the
 * distinction rather than flattening it.
 */
export function useAudit(limit = 50, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.admin.audit(limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/admin/audit', {
        params: { query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/** What the law says must be kept, in the words the contract enumerates. */
export const RETENTION_GROUNDS = {
  accounting_record: 'an accounting record, which must be kept for its statutory period',
  fiscal_record: 'a fiscal record, which the tax authority may ask for',
  audit_trail: 'the audit trail, which is what makes every other claim here checkable',
  legal_notice_given: 'a legal notice that was given, whose wording has effect and is kept as sent',
  commercial_traceability: 'commercial traceability — who bought what, and when',
} as const;

export type RetentionGround = keyof typeof RETENTION_GROUNDS;

/**
 * The report, taken from the generated response type rather than restated.
 *
 * A hand-written interface here compiled and was redundant — and worse, it would
 * have gone on compiling if the contract's shape changed underneath it. The
 * grounds above are still spelled out by hand *on purpose*: they are the words
 * an operator reads before deciding, and the enum only gives their names.
 */
export type Erasure = NonNullable<
  Operations['eraseUser']['responses'][200]['content']['application/json']
>['erasure'];

/**
 * Erasing a person, against legal retention.
 *
 * **Not optimistic, and not a preview.** There is no dry run in the contract:
 * this endpoint performs the erasure and reports what it did. So the screen
 * states beforehand what the law will require be kept — from the grounds above,
 * which are the contract's own enumeration — and shows the counts afterwards.
 * An operator who believes it deletes everything will promise that to a
 * customer, and non-negotiable #15 is that they must not be able to.
 *
 * It invalidates the directory: the person's row survives with `erased_at` set
 * and no identity, and seeing that happen is how the operator learns what
 * erasure actually did.
 */
export function useEraseUser() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (userId: string): Promise<Erasure> => {
      const { data, error, response } = await client.POST('/api/v1/admin/erasures', {
        body: { user_id: userId },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.erasure;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.admin.directories }),
        // The act itself is audited, so the trail has one more entry than it did.
        queryClient.invalidateQueries({ queryKey: keys.admin.audits }),
      ]);
    },
  });
}
