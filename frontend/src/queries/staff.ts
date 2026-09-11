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

export type Tenant = Schemas['Tenant'];
export type StaffAccessEntry = Schemas['StaffAccessEntry'];
export type Conversation = Schemas['Conversation'];
export type Message = Schemas['Message'];

export interface StaffIdentity {
  readonly userId: string;
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
