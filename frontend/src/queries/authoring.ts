import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Authoring an offer, and the freeze that makes a published one trustworthy.
 *
 * **A published version is frozen** (ADR-033). Not "editable by an
 * administrator", not "editable until somebody buys it" — frozen. A quote pins
 * the version that priced it precisely so a catalogue change cannot silently
 * reprice something already sent, and that guarantee is worth nothing if the
 * version itself can move underneath it.
 *
 * So this file offers no way to edit a version at all. Not a disabled mutation,
 * not one that checks a status first: there is no `updateOfferVersion` in the
 * contract, and the only way to change published terms is to add a new version
 * and publish that. The screen's job is to make the absence legible rather than
 * to look broken.
 *
 * Two more things the contract decides and this does not:
 *
 *   - **`code` is permanent.** `PATCH /offers/{id}` renames — it takes a `name`
 *     and nothing else. An identifier that can change is not an identifier, and
 *     documents already name this offer by its code;
 *   - **the version number is the database's.** It is `max + 1`, allocated on
 *     insert. Nothing here guesses it, so nothing here can allocate two.
 */

export type AuthoredOffer = Schemas['AuthoredOffer'];
export type AuthoredOfferVersion = Schemas['AuthoredOfferVersion'];
export type OfferDraft = Schemas['OfferDraft'];

/** The statuses a version can be in, and the one that is still editable. */
export type VersionStatus = AuthoredOfferVersion['status'];

/**
 * Whether this version's terms can still be changed — by replacing it, never by
 * editing it.
 *
 * Only a `DRAFT` has terms nobody has been sold. `ACTIVE`, `EXPIRED` and
 * `ARCHIVED` are all history: something may have been priced against them, and
 * history that can be rewritten is not history.
 */
export function isDraft(version: AuthoredOfferVersion): boolean {
  return version.status === 'DRAFT';
}

/**
 * An offer with every version it has, drafts included.
 *
 * A different question from `useOffer`, which answers what is *on sale* — so a
 * different key. Sharing one would mean the sale view could be served from the
 * authoring cache and show a draft price to a buyer.
 */
export function useAuthoredOffer(offerId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.catalogue.authored(offerId ?? ''),
    enabled: offerId !== null,
    queryFn: async (): Promise<AuthoredOffer> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/offers/{offerId}/versions', {
        params: { ...ambient.params, path: { offerId: offerId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
  });
}

/** Everything a catalogue read might now be wrong about. */
async function refreshCatalogue(
  queryClient: ReturnType<typeof useQueryClient>,
  offerId: string | null,
): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: keys.catalogue.offers }),
    ...(offerId === null
      ? []
      : [
          queryClient.invalidateQueries({ queryKey: keys.catalogue.offer(offerId) }),
          queryClient.invalidateQueries({ queryKey: keys.catalogue.authored(offerId) }),
        ]),
  ]);
}

export function useCreateOffer() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      input: OfferDraft & { code: string; name: string; plan_id: string },
    ): Promise<AuthoredOffer> => {
      const { data, error, response } = await client.POST('/api/v1/offers', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
    onSuccess: async (offer) => {
      queryClient.setQueryData(keys.catalogue.authored(offer.id), offer);
      await refreshCatalogue(queryClient, null);
    },
  });
}

/**
 * Renaming — the only edit an offer itself allows.
 *
 * The name is presentation; the code is identity. The contract takes only the
 * first, which is why this mutation has one field and no others.
 */
export function useRenameOffer(offerId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (name: string): Promise<AuthoredOffer> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.PATCH('/api/v1/offers/{offerId}', {
        params: { ...ambient.params, path: { offerId } },
        body: { name },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
    onSuccess: async (offer) => {
      queryClient.setQueryData(keys.catalogue.authored(offer.id), offer);
      await refreshCatalogue(queryClient, offerId);
    },
  });
}

/**
 * A new version, always born `DRAFT`.
 *
 * `OfferDraft` carries no status field, and that is deliberate in the contract:
 * publishing is a separate and explicit act, so there is no way to write a
 * version that arrives already on sale.
 */
export function useAddOfferVersion(offerId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (draft: OfferDraft): Promise<AuthoredOffer> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/offers/{offerId}/versions', {
        params: { ...ambient.params, path: { offerId } },
        body: draft,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
    onSuccess: async (offer) => {
      queryClient.setQueryData(keys.catalogue.authored(offer.id), offer);
      await refreshCatalogue(queryClient, offerId);
    },
  });
}

/**
 * Publishing, which is where the freeze begins.
 *
 * Takes the version *number* rather than an id: the caller names which draft it
 * means, and the server decides whether that draft may still be published. The
 * response is the whole offer, so the cache is written from it — and the sale
 * views are invalidated, because what is on sale has just changed for everyone.
 */
export function usePublishOfferVersion(offerId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (version: number): Promise<AuthoredOffer> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/offers/{offerId}/publish', {
        params: { ...ambient.params, path: { offerId } },
        body: { version },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.offer;
    },
    onSuccess: async (offer) => {
      queryClient.setQueryData(keys.catalogue.authored(offer.id), offer);
      await refreshCatalogue(queryClient, offerId);
    },
  });
}
