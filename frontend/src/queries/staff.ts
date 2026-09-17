import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * The support desk, and the boundary it crosses.
 *
 * **No ambient headers anywhere in this file.** Every other query module sends
 * `X-Product` — and `X-Tenant` where a person belongs to more than one — because
 * a resource request without a product is meaningless (§12.1). A staff read is
 * not that kind of request: a platform role never grants tenant membership
 * (non-negotiable #22), so there is no ambient tenant to send, and the tenant is
 * named in the path instead. `ambientParams` would throw here for a staff member
 * with no product chosen, which is the right shape of failure for the tenant app
 * and simply wrong for this one.
 *
 * **Every read across the boundary is recorded.** The backend writes a
 * `StaffAccessEntry` naming who looked, at what, and *under which permission* —
 * the answer to "on what grounds?" that a log without it can never give. That
 * record is not optional and not something the screen arranges; the screen's job
 * is to make sure nobody is surprised by it, which is why the access log is a
 * screen of its own and why the reads that produce entries say so.
 */

/**
 * Why a staff member is crossing a tenant boundary (R14).
 *
 * The platform requires this on the two reads that reveal one tenant's own data
 * — opening a customer, opening a support thread — and on neither listing.
 * Non-negotiable #21 wants the reason recorded *with* the access rather than
 * beside it, so it travels as headers on the request itself: there is no second
 * call to forget to make.
 *
 * `purpose` is what makes the log countable; `reference` is what makes any one
 * row mean something. R14 said a free-text box alone *"collects 'support' a
 * thousand times and proves nothing"* and a structured field alone *"is only as
 * good as the system it points at"* — so it is both, and neither is optional.
 */
export const ACCESS_PURPOSES = [
  'SUPPORT_REQUEST',
  'BILLING_INVESTIGATION',
  'INCIDENT',
  'SECURITY_REVIEW',
  'LEGAL_REQUEST',
] as const;

export type AccessPurpose = (typeof ACCESS_PURPOSES)[number];

export interface AccessMotive {
  readonly purpose: AccessPurpose;
  readonly reference: string;
}

/** What a person reads, rather than the enum's spelling. */
export const PURPOSE_LABELS: Record<AccessPurpose, string> = {
  SUPPORT_REQUEST: 'A support request',
  BILLING_INVESTIGATION: 'A billing investigation',
  INCIDENT: 'An incident',
  SECURITY_REVIEW: 'A security review',
  LEGAL_REQUEST: 'A legal request',
};

/** The platform's floor, mirrored so the form can refuse before the request. */
export const MINIMUM_REFERENCE = 8;

export function isMotiveComplete(motive: Partial<AccessMotive> | null): motive is AccessMotive {
  return (
    motive !== null &&
    motive.purpose !== undefined &&
    (motive.reference ?? '').trim().length >= MINIMUM_REFERENCE
  );
}

/** The headers the contract names, built in one place. */
function motiveHeaders(motive: AccessMotive): {
  'X-Access-Purpose': AccessPurpose;
  'X-Access-Reason': string;
} {
  return {
    'X-Access-Purpose': motive.purpose,
    'X-Access-Reason': motive.reference.trim(),
  };
}

/**
 * The invariant `enabled` already guarantees, said out loud.
 *
 * The two reads below are disabled until there is a motive, so their query
 * functions never run without one. TypeScript cannot see that, and the honest
 * options are a cast or a throw — a cast would be a claim, and this is a
 * programmer error that should be loud if the guard is ever removed.
 */
function required(motive: AccessMotive | null): AccessMotive {
  if (motive === null) {
    throw new Error('A staff read reached its query function without a motive.');
  }

  return motive;
}

// The staff shape: a tenant and the products the platform gave it (ADR-047).
// `Schemas['Tenant']` is what a tenant reads about itself and has no
// `products`; every staff read answers with this one.
export type Tenant = Schemas['StaffTenant'];
export type StaffAccessEntry = Schemas['StaffAccessEntry'];
export type Conversation = Schemas['Conversation'];
export type Message = Schemas['Message'];

export interface StaffIdentity {
  readonly userId: string;
  /** Null once erased (§26): the identity outlives the details. */
  readonly email: string | null;
  readonly displayName: string | null;
  readonly roles: readonly string[];
  readonly permissions: readonly string[];
}

/**
 * Who is at the desk.
 *
 * The console's bootstrap read, and deliberately **not** `GET /me`: that answers
 * for a tenant member and carries a `tenant_id`, which a platform staff member
 * does not have. A console that read `/me` would either fail for real staff or
 * silently show one tenant's shell painted amber.
 *
 * There are no capabilities here, and that absence is the design: an entitlement
 * is a thing a tenant's plan grants, and the console is not inside anybody's
 * plan. `access` below fills the shape the shell's gates expect with an empty
 * capability list rather than inventing one.
 */
export function useStaffIdentity() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.identity,
    queryFn: async (): Promise<StaffIdentity> => {
      const { data, error, response } = await client.GET('/api/v1/staff/me', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return {
        userId: data.staff.user_id,
        email: data.staff.email,
        displayName: data.staff.display_name,
        roles: data.staff.roles,
        permissions: data.staff.permissions,
      };
    },
    staleTime: 5 * 60 * 1000,
    retry: (attempt, error) =>
      // 401 and 403 are answers. Somebody who is not staff will never become
      // staff by asking twice.
      !(error instanceof Error && 'status' in error && (error.status === 401 || error.status === 403)) &&
      attempt < 2,
  });
}

/**
 * The staff identity in the shape the shell's gates read.
 *
 * `can()` and `visibleNav()` take `{ permissions, capabilities }`, so this
 * adapts rather than duplicating them for the console — the two shells share no
 * navigation, but they do share the question "may this person see this", and
 * that question has one answer in this application.
 */
export function staffAccess(
  identity: StaffIdentity | undefined,
): { permissions: readonly string[]; capabilities: readonly string[] } | undefined {
  return identity === undefined
    ? undefined
    : { permissions: identity.permissions, capabilities: [] };
}

/** The tenants a support agent may look at. Reading this list is itself logged. */
export function useStaffTenants(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenants(limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants', {
        params: { query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * One tenant, named in the path.
 *
 * The one place this platform lets a client name a tenant — ADR-015 forbids it
 * everywhere else, and this is not the exception it looks like: the path names
 * the tenant, the *platform role* authorises the read, and the read is recorded
 * either way.
 */
export function useStaffTenant(tenantId: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    // The motive is part of the key, so a read for one reason is not served
    // from the cache to a read for another. An access this platform records has
    // to actually happen.
    queryKey: keys.staff.tenant(tenantId ?? '', motive?.purpose ?? '', motive?.reference ?? ''),
    // **Disabled until there is a reason.** The read does not go out and then
    // fail — it does not go out. R14's requirement is that the reason is
    // collected *as part of the read*, and a request fired without one would be
    // a 422 the screen then apologised for.
    //
    // This is the *second* guard, and deliberately so. The load-bearing one is
    // the screen: `StaffTenantsScreen` renders the motive gate instead of the
    // detail, so this hook is never mounted without one — removing this line
    // alone changes no test, because `required()` below throws before a request
    // is built. Three guards for one rule is not redundancy here: the screen
    // decides what a person sees, this decides what the cache does, and the
    // throw makes a future mistake loud instead of silent.
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<Tenant> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}', {
        params: { path: { tenantId: tenantId ?? '' }, header: motiveHeaders(required(motive)) },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
  });
}

export type StaffTenantMember = Schemas['StaffTenantMember'];

/**
 * Who belongs to a tenant, read from the console — across the products it
 * holds, or on one of them.
 *
 * Same discipline as `useStaffTenant`: the motive is part of the key and the
 * read is disabled until there is one. Read-only by construction: nothing
 * here writes, because a platform role never edits a membership (#22).
 */
export function useStaffTenantMembers(
  tenantId: string | null,
  productCode: string | null,
  motive: AccessMotive | null,
) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantMembers(
      tenantId ?? '',
      productCode ?? '',
      motive?.purpose ?? '',
      motive?.reference ?? '',
    ),
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<StaffTenantMember[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}/members', {
        params: {
          path: { tenantId: tenantId ?? '' },
          header: motiveHeaders(required(motive)),
          query: productCode === null ? {} : { product: productCode },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.members;
    },
  });
}

export type StaffTenantPayment = Schemas['Payment'];
export type StaffTenantOrder = Schemas['Order'];
export type StaffTenantQuote = Schemas['Quote'];
export type StaffTenantProject = Schemas['ProjectSummary'];
export type StaffTenantJob = Schemas['Job'];
export type StaffTenantTaxProfile = Schemas['TaxProfile'];

/**
 * The rest of what a customer has, read from the console — the same shapes
 * the customer's own screens read, one read per tab, each disabled until
 * there is a motive and keyed on it (R14), each narrowed by the product the
 * bar picked. Read-only by construction: no mutation lives beside any of
 * these.
 *
 * Five near-identical functions rather than one factory, on purpose: the
 * screens gate (`gate:screens`) proves every claimed operation is called
 * by finding its path as a literal in a `client.GET`, and a path built from
 * a template is a call the gate cannot see — the gate would pass a factory
 * that called nothing.
 */
export function useStaffTenantPayments(tenantId: string | null, productCode: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantRead(tenantId ?? '', 'payments', productCode ?? '', motive?.purpose ?? '', motive?.reference ?? ''),
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<StaffTenantPayment[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}/payments', {
        params: {
          path: { tenantId: tenantId ?? '' },
          header: motiveHeaders(required(motive)),
          query: productCode === null ? {} : { product: productCode },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.payments;
    },
  });
}

export function useStaffTenantOrders(tenantId: string | null, productCode: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantRead(tenantId ?? '', 'orders', productCode ?? '', motive?.purpose ?? '', motive?.reference ?? ''),
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<StaffTenantOrder[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}/orders', {
        params: {
          path: { tenantId: tenantId ?? '' },
          header: motiveHeaders(required(motive)),
          query: productCode === null ? {} : { product: productCode },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.orders;
    },
  });
}

export function useStaffTenantQuotes(tenantId: string | null, productCode: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantRead(tenantId ?? '', 'quotes', productCode ?? '', motive?.purpose ?? '', motive?.reference ?? ''),
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<StaffTenantQuote[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}/quotes', {
        params: {
          path: { tenantId: tenantId ?? '' },
          header: motiveHeaders(required(motive)),
          query: productCode === null ? {} : { product: productCode },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.quotes;
    },
  });
}

export function useStaffTenantProjects(tenantId: string | null, productCode: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantRead(tenantId ?? '', 'projects', productCode ?? '', motive?.purpose ?? '', motive?.reference ?? ''),
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<StaffTenantProject[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}/projects', {
        params: {
          path: { tenantId: tenantId ?? '' },
          header: motiveHeaders(required(motive)),
          query: productCode === null ? {} : { product: productCode },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.projects;
    },
  });
}

export function useStaffTenantJobs(tenantId: string | null, productCode: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantRead(tenantId ?? '', 'jobs', productCode ?? '', motive?.purpose ?? '', motive?.reference ?? ''),
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<StaffTenantJob[]> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}/jobs', {
        params: {
          path: { tenantId: tenantId ?? '' },
          header: motiveHeaders(required(motive)),
          query: productCode === null ? {} : { product: productCode },
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.jobs;
    },
  });
}

/** The customer's one fiscal identity — not per product, so no product argument. */
export function useStaffTenantTaxProfile(tenantId: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantRead(tenantId ?? '', 'tax-profile', '', motive?.purpose ?? '', motive?.reference ?? ''),
    enabled: tenantId !== null && motive !== null,
    queryFn: async (): Promise<StaffTenantTaxProfile> => {
      const { data, error, response } = await client.GET('/api/v1/staff/tenants/{tenantId}/tax-profile', {
        params: { path: { tenantId: tenantId ?? '' }, header: motiveHeaders(required(motive)) },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.profile;
    },
  });
}

/**
 * One customer's support threads — the listing the threads screen pages
 * through, narrowed by the tenant filter the contract declares. INTERNAL
 * threads are never here: staff must not appear in one (§12.3).
 */
export function useStaffTenantConversations(tenantId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.tenantConversations(tenantId ?? ''),
    enabled: tenantId !== null,
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/conversations', {
        params: { query: { limit: 50, offset: 0, tenant_id: tenantId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.conversations;
    },
  });
}

/**
 * What staff looked at.
 *
 * The record that makes the rest of this shell acceptable. It is a first-class
 * screen rather than a debugging endpoint, because an audit nobody can read is
 * an audit nobody is accountable to.
 */
export function useAccessLog(limit = 50, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.accessLog(limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/access-log', {
        params: { query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useSupportConversations(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.conversations(limit, offset),
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/conversations', {
        params: { query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * One thread, with its messages and the tenant it belongs to.
 *
 * The tenant id comes back on the *detail* and not on the list, which is the
 * contract being careful: knowing which company is asking is already a crossing
 * of the boundary, and it happens when a thread is opened rather than when a
 * queue is skimmed.
 */
export function useSupportConversation(conversationId: string | null, motive: AccessMotive | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.conversation(
      conversationId ?? '',
      motive?.purpose ?? '',
      motive?.reference ?? '',
    ),
    enabled: conversationId !== null && motive !== null,
    queryFn: async () => {
      const { data, error, response } = await client.GET(
        '/api/v1/staff/conversations/{conversationId}',
        {
          params: {
            path: { conversationId: conversationId ?? '' },
            header: motiveHeaders(required(motive)),
          },
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * Answering.
 *
 * **Not optimistic, unlike the tenant's own reply in U3.** There the rule was
 * that optimism is safe where nothing binding is created, and a member's message
 * in their own thread qualifies. This does not: a support answer is written by
 * somebody acting with platform authority into a company's thread, it is logged
 * as such, and showing it as sent before the server took it would be showing an
 * official reply that may not exist.
 *
 * A closed thread answers 409, which is the reason the screen keeps `close` and
 * `reply` visibly apart rather than offering both to the last click.
 */
export function usePostSupportMessage(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (body: string): Promise<Message> => {
      const { data, error, response } = await client.POST(
        '/api/v1/staff/conversations/{conversationId}/messages',
        { params: { path: { conversationId } }, body: { body } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.conversationReads(conversationId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.conversationLists }),
      ]);
    },
  });
}

/** Closing a thread. Reopening is the tenant's to do by writing again. */
export function useCloseSupportConversation(conversationId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Conversation> => {
      const { data, error, response } = await client.POST(
        '/api/v1/staff/conversations/{conversationId}/close',
        { params: { path: { conversationId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.conversationReads(conversationId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.conversationLists }),
      ]);
    },
  });
}

/**
 * The roster, and the two writes that change it.
 *
 * **Every one of these returns the whole roster**, writes included, and the
 * mutations seed the cache with what came back rather than invalidating and
 * fetching again. Revoking is the case that makes it worth doing: the response
 * is the only authority on what the roster now looks like, and a refetch would
 * ask a second time and could answer differently if somebody else was granting
 * at that moment.
 */
export type StaffMember = Schemas['StaffMember'];
export type PlatformRole = Schemas['PlatformRole'];
export type PlatformProduct = Schemas['PlatformProduct'];
export type StaffRoster = Schemas['StaffRoster'];

export function useStaffRoster() {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.roster,
    queryFn: async (): Promise<StaffRoster> => {
      const { data, error, response } = await client.GET('/api/v1/staff/members');

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useGrantPlatformRole() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (grant: { userId: string; role: string }): Promise<StaffRoster> => {
      const { data, error, response } = await client.POST('/api/v1/staff/members', {
        body: { user_id: grant.userId, role: grant.role },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: (roster) => {
      queryClient.setQueryData(keys.staff.roster, roster);
    },
  });
}

export function useRevokePlatformRole() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (grant: { userId: string; role: string }): Promise<StaffRoster> => {
      const { data, error, response } = await client.DELETE(
        '/api/v1/staff/members/{userId}/roles/{role}',
        { params: { path: { userId: grant.userId, role: grant.role } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: (roster) => {
      queryClient.setQueryData(keys.staff.roster, roster);
    },
  });
}

/**
 * Lending the platform's catalogue to a tenant, or taking it back.
 *
 * A write on a tenant rather than a read of one, so it carries no motive: R14
 * asks why somebody is looking at a customer's data, and this looks at none.
 * The invalidation is wide on purpose — `catalog.manage` is resolved from this
 * flag, so the person whose tenant just changed has a different set of
 * permissions than the one their browser is holding.
 */
export function useSetTenantOfferAuthoring(tenantId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (mayAuthor: boolean) => {
      const { data, error, response } = await client.PUT(
        '/api/v1/staff/tenants/{tenantId}/offer-authoring',
        { params: { path: { tenantId } }, body: { may_author_offers: mayAuthor } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantReads(tenantId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantLists }),
      ]);
    },
  });
}

/**
 * Give a tenant a product, or take one back (ADR-047).
 *
 * Two mutations with one shape, mirroring the offer-authoring toggle above:
 * a write on the tenant, no motive header, the tenant as it now stands in
 * the answer. The invalidation reaches the tenant's reads and the list, where
 * the product chips live. Nothing optimistic — the server may refuse (a
 * retired product, a subscription still owed service) and a checkbox that
 * had already ticked itself would then have to un-tick with an apology.
 */
export function useAssignTenantProduct(tenantId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (productId: string) => {
      const { data, error, response } = await client.PUT(
        '/api/v1/staff/tenants/{tenantId}/products/{productId}',
        { params: { path: { tenantId, productId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantReads(tenantId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantLists }),
      ]);
    },
  });
}

export function useUnassignTenantProduct(tenantId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (productId: string) => {
      const { data, error, response } = await client.DELETE(
        '/api/v1/staff/tenants/{tenantId}/products/{productId}',
        { params: { path: { tenantId, productId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantReads(tenantId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantLists }),
      ]);
    },
  });
}

/**
 * What the public storefront advertises, for one product.
 *
 * The unfiltered view — every offer, hidden ones included — because deciding
 * what a stranger sees means seeing what is currently hidden. The product is a
 * query parameter rather than `X-Product`: a staff route resolves no product
 * of its own, since a platform role grants no membership.
 */
export function useStorefrontOffers(productCode: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.storefront.listing(productCode ?? ''),
    enabled: productCode !== null && productCode !== '',
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/storefront/offers', {
        params: { query: { product: productCode ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useSetOfferPublicListing(productCode: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (decision: { offerId: string; listed: boolean }) => {
      const { data, error, response } = await client.PUT(
        '/api/v1/staff/storefront/offers/{offerId}',
        {
          params: { path: { offerId: decision.offerId }, query: { product: productCode } },
          body: { publicly_listed: decision.listed },
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: keys.storefront.listing(productCode) });
    },
  });
}

/**
 * Every product on the platform — the question `useProducts` cannot answer.
 *
 * That one resolves through membership, and a platform role grants none
 * (non-negotiable #22), so an administrator asking it what this deployment
 * hosts is answered about their own tenant or not at all. This asks the other
 * question, behind `staff.products.manage`, and includes retired products:
 * they still carry tenants, subscriptions and invoices.
 */
export function usePlatformProducts(enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.products,
    enabled,
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/products', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.products;
    },
    // The switcher asks this on every console screen, and a support engineer
    // is answered 403 every time: that is the answer, not a dropped packet.
    retry: (attempt, error) =>
      !(error instanceof Error && 'status' in error && (error.status === 401 || error.status === 403)) &&
      attempt < 2,
  });
}

export function useCreateProduct() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (product: { code: string; name: string }) => {
      const { data, error, response } = await client.POST('/api/v1/staff/products', {
        body: product,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.product;
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: keys.staff.products });
    },
  });
}

/**
 * Renames a product, retires it, or brings it back.
 *
 * The two fields are sent independently — the API is a PATCH for exactly that
 * reason — so renaming never says anything about whether a product is active,
 * and a form that forgot a checkbox cannot switch one off.
 */
export function useUpdateProduct() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (change: { productId: string; name?: string; active?: boolean }) => {
      const { data, error, response } = await client.PATCH('/api/v1/staff/products/{productId}', {
        params: { path: { productId: change.productId } },
        body: {
          ...(change.name !== undefined && { name: change.name }),
          ...(change.active !== undefined && { active: change.active }),
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.product;
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: keys.staff.products });
    },
  });
}

export type DemoWorld = Schemas['DemoWorld'];

/**
 * Empties every business table and seeds the demonstration world afresh.
 *
 * Nothing is invalidated and nothing is written into the cache: every row the
 * cache describes is gone, the caller's own account among them, and the next
 * request with this token is refused. The screen shows the answer — who to
 * sign in as — and then signs out, which is when the cache is cleared. A
 * refetch in between would 401 and sign out under the person reading it.
 */
export function useResetDemoWorld() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (): Promise<DemoWorld> => {
      const { data, error, response } = await client.POST('/api/v1/staff/demo/reset', {});

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.world;
    },
  });
}

/**
 * The platform's own catalogue — the plans and features an offer is built out
 * of, for one product.
 *
 * Both in one read, because an offer needs both and a form that fetched them
 * separately would render half of itself. The offers come from
 * `useStorefrontOffers`, which already answers the unfiltered authoring view.
 */
export type StaffPlan = Schemas['Plan'];
export type StaffFeature = Schemas['Feature'];

/**
 * One line of what a version grants: a feature and, for a quota, how much of it.
 *
 * `limit: null` means two different things and the API keeps them apart by the
 * feature's kind — unlimited for a quota, and the only valid value for a switch.
 * The screen never has to choose, because it reads the kind off the feature.
 */
export interface OfferGrantInput {
  readonly feature_id: string;
  readonly limit: number | null;
}

export function useStaffCatalogue(productCode: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.catalogue(productCode ?? ''),
    enabled: productCode !== null && productCode !== '',
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/catalogue', {
        params: { query: { product: productCode ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * Every write on the catalogue invalidates both reads.
 *
 * A new plan changes what an offer can be attached to, and a published version
 * changes what the storefront list shows — so the two queries are refreshed
 * together rather than each mutation reasoning about which it touched.
 */
function useCatalogueWrite<TVariables, TData>(
  productCode: string,
  mutationFn: (variables: TVariables) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.catalogue(productCode) }),
        queryClient.invalidateQueries({ queryKey: keys.storefront.listing(productCode) }),
        // A plan, an offer or a publication moves the setup chain, and the
        // chain's only value is being current.
        queryClient.invalidateQueries({ queryKey: keys.staff.readiness(productCode) }),
      ]);
    },
  });
}

export function useCreatePlan(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(productCode, async (plan: { code: string; name: string; rank: number }) => {
    const { data, error, response } = await client.POST('/api/v1/staff/catalogue/plans', {
      params: { query: { product: productCode } },
      body: plan,
    });

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.plan;
  });
}

export function useUpdatePlan(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(
    productCode,
    async (change: { planId: string; name?: string; rank?: number }) => {
      const { data, error, response } = await client.PATCH(
        '/api/v1/staff/catalogue/plans/{planId}',
        {
          params: { path: { planId: change.planId }, query: { product: productCode } },
          body: {
            ...(change.name !== undefined && { name: change.name }),
            ...(change.rank !== undefined && { rank: change.rank }),
          },
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.plan;
    },
  );
}

export function useCreateFeature(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(
    productCode,
    async (feature: { code: string; name: string; kind: 'BOOLEAN' | 'QUOTA'; unit: string | null }) => {
      const { data, error, response } = await client.POST('/api/v1/staff/catalogue/features', {
        params: { query: { product: productCode } },
        body: feature,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.feature;
    },
  );
}

export function useCreateStaffOffer(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(
    productCode,
    async (offer: {
      code: string;
      name: string;
      plan_id: string;
      billing_period: 'MONTHLY' | 'YEARLY' | 'CUSTOM';
      price_minor_units: number;
      currency: string;
      // What the version grants, which the API has always accepted and no
      // screen sent. An offer with none is sellable — access to the product is
      // itself worth something — so this stays optional.
      grants?: OfferGrantInput[];
    }) => {
      const { data, error, response } = await client.POST('/api/v1/staff/catalogue/offers', {
        params: { query: { product: productCode } },
        body: offer,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
  );
}

export function useCreateStaffOfferVersion(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(
    productCode,
    async (version: {
      offerId: string;
      billing_period: 'MONTHLY' | 'YEARLY' | 'CUSTOM';
      price_minor_units: number;
      currency: string;
      grants?: OfferGrantInput[];
    }) => {
      const { offerId, ...terms } = version;

      const { data, error, response } = await client.POST(
        '/api/v1/staff/catalogue/offers/{offerId}/versions',
        {
          params: { path: { offerId }, query: { product: productCode } },
          body: terms,
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
  );
}

/**
 * The act that puts a price on sale.
 *
 * From here on every quote, order and subscription written against this offer
 * prices from that version, and ADR-033 freezes it the moment it happens — so
 * the screen asks before calling this, and nothing else on the console does.
 */
export function usePublishStaffOfferVersion(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(
    productCode,
    async (publication: { offerId: string; version: number }) => {
      const { data, error, response } = await client.POST(
        '/api/v1/staff/catalogue/offers/{offerId}/publish',
        {
          params: { path: { offerId: publication.offerId }, query: { product: productCode } },
          body: { version: publication.version },
        },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
  );
}

export function useRenameStaffOffer(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(productCode, async (change: { offerId: string; name: string }) => {
    const { data, error, response } = await client.PATCH(
      '/api/v1/staff/catalogue/offers/{offerId}',
      {
        params: { path: { offerId: change.offerId }, query: { product: productCode } },
        body: { name: change.name },
      },
    );

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.offer;
  });
}

export function useRenameFeature(productCode: string) {
  const client = useApiClient();

  return useCatalogueWrite(productCode, async (change: { featureId: string; name: string }) => {
    const { data, error, response } = await client.PATCH(
      '/api/v1/staff/catalogue/features/{featureId}',
      {
        params: { path: { featureId: change.featureId }, query: { product: productCode } },
        body: { name: change.name },
      },
    );

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.feature;
  });
}

/**
 * What a product needs configured before it can take money.
 *
 * ADR-042 gave the console a way to create a product and ADR-043 a way to price
 * it, and a checkout against one created that way still refused with
 * `BILLING_NOT_CONFIGURED` — an invoice must name its issuer, and the issuer
 * lives in `product_configuration`, a table only the demo seeder ever wrote.
 *
 * `can_invoice` comes from the backend rather than being computed here, and
 * deliberately: it is the same rule the invoice path applies when a checkout
 * runs, and a second answer computed in a screen would eventually say ready
 * where the checkout refuses.
 */
export type BillingSupplier = Schemas['BillingSupplier'];
export type TaxSettings = Schemas['TaxSettings'];

export function useProductConfiguration(productCode: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.configuration(productCode ?? ''),
    enabled: productCode !== null && productCode !== '',
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/configuration', {
        params: { query: { product: productCode ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * Both writes invalidate the same read, which carries `can_invoice`.
 *
 * Never an optimistic update. What an invoice will name is not a field to
 * assume: an incomplete identity is refused by the backend, and a screen that
 * had already painted it as saved would tell somebody their product could
 * invoice when it cannot.
 */
function useConfigurationWrite<TVariables, TData>(
  productCode: string,
  mutationFn: (variables: TVariables) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.configuration(productCode) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.readiness(productCode) }),
      ]);
    },
  });
}

/**
 * Sets who this product's invoices say is issuing them.
 *
 * The whole identity every time, because the endpoint is a PUT: a supplier that
 * stops being liable for VAT has to be able to remove its VAT number, and
 * "omitted means leave it" would make removing anything impossible.
 */
export function useSetBillingIdentity(productCode: string) {
  const client = useApiClient();

  return useConfigurationWrite(productCode, async (supplier: BillingSupplier) => {
    const { data, error, response } = await client.PUT(
      '/api/v1/staff/configuration/billing-identity',
      {
        params: { query: { product: productCode } },
        body: supplier,
      },
    );

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.billing_supplier;
  });
}

/** Sets the supplier's own fiscal position (§25.3), which is never derived. */
export function useSetTaxSettings(productCode: string) {
  const client = useApiClient();

  return useConfigurationWrite(productCode, async (tax: TaxSettings) => {
    const { data, error, response } = await client.PUT('/api/v1/staff/configuration/tax', {
      params: { query: { product: productCode } },
      body: tax,
    });

    if (error !== undefined || data === undefined) {
      throw toApiError(response.status, error);
    }

    return data.tax;
  });
}

/**
 * The chain a product has to complete before a stranger can buy from it.
 *
 * Read from the backend rather than assembled here from four separate calls.
 * Two reasons, and the second is the important one: a screen that counted plans
 * and offers itself would be a second implementation of "can this sell", and
 * the first to drift would be the one the operator trusts. And `published` asks
 * the *clock* whether a version is sellable now — a question a frontend cannot
 * answer from a status field, because a version can be ACTIVE and outside its
 * window.
 */
export type SetupStep = Schemas['SetupStep'];
export type SetupStepKey = SetupStep['key'];

export function useProductReadiness(productCode: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.staff.readiness(productCode ?? ''),
    enabled: productCode !== null && productCode !== '',
    queryFn: async () => {
      const { data, error, response } = await client.GET('/api/v1/staff/readiness', {
        params: { query: { product: productCode ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    // Short, because this is the one read whose whole value is being current:
    // somebody fixes a step on another screen and comes back to see it move.
    staleTime: 0,
  });
}

/**
 * Making and re-addressing organisations (2026-09-17) — the platform's act,
 * since sign-up stopped making them. Both answer with the organisation as it
 * now stands, so the lists are invalidated rather than patched.
 */
export type NewTenant = {
  readonly name: string;
  readonly slug: string;
  readonly products: readonly string[];
  readonly admin_user_id: string | null;
};

export function useCreateTenant() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: NewTenant) => {
      const { data, error, response } = await client.POST('/api/v1/staff/tenants', {
        body: {
          name: input.name,
          slug: input.slug,
          products: [...input.products],
          ...(input.admin_user_id === null ? {} : { admin_user_id: input.admin_user_id }),
        },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: keys.staff.tenantLists });
    },
  });
}

export type TenantPatch = {
  readonly name?: string;
  readonly slug?: string;
  readonly is_default?: boolean;
};

export function useUpdateTenant(tenantId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (patch: TenantPatch) => {
      const { data, error, response } = await client.PATCH('/api/v1/staff/tenants/{tenantId}', {
        params: { path: { tenantId } },
        body: patch,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.tenant;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantReads(tenantId) }),
        queryClient.invalidateQueries({ queryKey: keys.staff.tenantLists }),
      ]);
    },
  });
}
