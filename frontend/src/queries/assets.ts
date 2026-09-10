import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, binaryBody, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Files on a project, and the export that produces one.
 *
 * **The client never fetches the bytes.** A signed link is created and the
 * browser is sent to it, which is the point of `createAssetLink`: the download
 * is between the browser and storage, so a large file never passes through this
 * application's memory and the link can be handed to somebody who is not signed
 * in at all. A helpful `fetch(url).then(blob)` here would undo both, and ESLint
 * forbids the `fetch` anyway (§8.1).
 *
 * The uploaded file is not trusted about what it is. The API sniffs the bytes
 * and answers with the type it found, so `content_type` on the way back can
 * differ from what the browser claimed — which is why the response is written
 * into the cache rather than a row assembled here from the `File`.
 */

export type Asset = Schemas['Asset'];
export type AssetLink = { readonly url: string; readonly expires_at: string };

export function useAssets(projectId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.projects.assets(projectId ?? ''),
    enabled: projectId !== null,
    queryFn: async (): Promise<readonly Asset[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/projects/{projectId}/assets', {
        params: { ...ambient.params, path: { projectId: projectId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.assets;
    },
  });
}

export function useAsset(assetId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.assets.one(assetId ?? ''),
    enabled: assetId !== null,
    queryFn: async (): Promise<Asset> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/assets/{assetId}', {
        params: { ...ambient.params, path: { assetId: assetId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useUploadAsset(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (file: File): Promise<Asset> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/projects/{projectId}/assets', {
        params: {
          ...ambient.params,
          path: { projectId },
          // The body is the raw bytes, so the name travels in a header — which
          // the contract requires, and the compiler insisted on. It is
          // sanitised server-side before it reaches a filesystem or a
          // Content-Disposition, so this passes it through unchanged rather
          // than inventing a second cleaning rule that could disagree.
          header: { ...ambient.params.header, 'X-Filename': file.name },
        },
        ...binaryBody(file),
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (asset) => {
      queryClient.setQueryData(keys.assets.one(asset.id), asset);
      await queryClient.invalidateQueries({ queryKey: keys.projects.assets(projectId) });
    },
  });
}

export function useDeleteAsset(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (assetId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.DELETE('/api/v1/assets/{assetId}', {
        params: { ...ambient.params, path: { assetId } },
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.projects.assets(projectId) }),
  });
}

/**
 * A signed link to the bytes.
 *
 * Deliberately **not** cached as a query. It expires, and a cached expiry is a
 * link that looks valid and is not — so it is asked for at the moment somebody
 * wants it, used, and forgotten. `ttl_seconds` is omitted so the platform's own
 * default and maximum apply: a client choosing its own lifetime would be the
 * client deciding how long a URL to private data stays live.
 */
export function useCreateAssetLink() {
  const client = useApiClient();

  return useMutation({
    mutationFn: async (assetId: string): Promise<AssetLink> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/assets/{assetId}/link', {
        params: { ...ambient.params, path: { assetId } },
        body: {},
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

/**
 * Requesting an export.
 *
 * Answers 202 with a job, not a file: the work happens off the request path
 * (non-negotiable #9 and §4's rule about holding an HTTP worker open). What
 * comes back is something to watch in region E, and when it succeeds its
 * `result.asset_id` names the file to link to.
 */
export function useRequestExport(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Schemas['Job']> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/projects/{projectId}/exports', {
        params: { ...ambient.params, path: { projectId } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (job) => {
      queryClient.setQueryData(keys.jobs.one(job.id), job);
      // Region E reads the job list, so it learns about this export the moment
      // the request returns rather than on its next poll.
      await queryClient.invalidateQueries({ queryKey: keys.jobs.lists });
    },
  });
}

/**
 * Where a finished export put its file.
 *
 * The handler's result is an open object, so this reads one key defensively
 * rather than trusting a shape the contract describes as `additionalProperties`.
 * A job that succeeded without naming an asset is possible in the type system,
 * and the honest answer to that is `null` — not a crash on a screen whose only
 * job is to offer a download.
 */
export function exportedAssetId(job: Schemas['Job']): string | null {
  const assetId = job.result?.asset_id;

  return typeof assetId === 'string' ? assetId : null;
}
