import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Projects, versions, and the restore that makes the history worth keeping.
 *
 * The invalidation rule from U3 applies unchanged: a response that *is* the new
 * state is written into the cache, and anything the server derives is
 * invalidated. Two things here are derived and so are never patched locally —
 * `updated_at`, which the server stamps, and the version list, which gains a row
 * whenever a snapshot is taken.
 *
 * **The document is transported, never interpreted.** `ProjectDocument` is an
 * open object and this file treats it as opaque: it is read from the API and
 * written back, and no code here looks inside it. §4's rule is that business
 * meaning belongs to the Core; a frontend that started reading document keys
 * would be deciding what a project *is*, one screen at a time.
 */

export type ProjectSummary = Schemas['ProjectSummary'];
export type Project = Schemas['Project'];
export type ProjectDocument = Schemas['ProjectDocument'];
export type ProjectVersionSummary = Schemas['ProjectVersionSummary'];
export type ProjectVersion = Schemas['ProjectVersion'];

export function useProjects(limit = 25, offset = 0) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.projects.list(limit, offset),
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/projects', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useProject(projectId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.projects.one(projectId ?? ''),
    enabled: projectId !== null,
    queryFn: async (): Promise<Project> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/projects/{projectId}', {
        params: { ...ambient.params, path: { projectId: projectId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export interface NewProject {
  name: string;
  description: string | null;
  schema_version: number;
  document: ProjectDocument;
}

export function useCreateProject() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: NewProject): Promise<Project> => {
      const { data, error, response } = await client.POST('/api/v1/projects', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (project) => {
      // Written *and* invalidated: the response is the whole project, so the
      // detail cache is correct immediately, while the list's `total` and
      // ordering are the server's to recompute.
      queryClient.setQueryData(keys.projects.one(project.id), project);
      await queryClient.invalidateQueries({ queryKey: keys.projects.lists });
    },
  });
}

export function useUpdateProject(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: Partial<NewProject>): Promise<Project> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.PATCH('/api/v1/projects/{projectId}', {
        params: { ...ambient.params, path: { projectId } },
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (project) => {
      queryClient.setQueryData(keys.projects.one(project.id), project);
      await queryClient.invalidateQueries({ queryKey: keys.projects.lists });
    },
  });
}

export function useDeleteProject() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (projectId: string): Promise<void> => {
      const ambient = ambientParams(sessionSnapshot);

      const { error, response } = await client.DELETE('/api/v1/projects/{projectId}', {
        params: { ...ambient.params, path: { projectId } },
      });

      if (error !== undefined) {
        throw toApiError(response.status, error);
      }
    },
    // Invalidated rather than removed from the list: deletion is soft, the row
    // comes back marked deleted, and a project that simply vanished would leave
    // nobody anywhere to restore it from.
    onSuccess: async (_result, projectId) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.projects.lists }),
        queryClient.invalidateQueries({ queryKey: keys.projects.one(projectId) }),
      ]);
    },
  });
}

export function useDuplicateProject() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (projectId: string): Promise<Project> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/projects/{projectId}/duplicate',
        { params: { ...ambient.params, path: { projectId } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (copy) => {
      queryClient.setQueryData(keys.projects.one(copy.id), copy);
      await queryClient.invalidateQueries({ queryKey: keys.projects.lists });
    },
  });
}

export function useProjectVersions(projectId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.projects.versions(projectId ?? ''),
    enabled: projectId !== null,
    queryFn: async (): Promise<readonly ProjectVersionSummary[]> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/projects/{projectId}/versions', {
        params: { ...ambient.params, path: { projectId: projectId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data.versions;
    },
  });
}

/**
 * One version, with its document.
 *
 * Fetched only when a version is actually being looked at. The summaries in the
 * list carry no document — a history of fifty snapshots would otherwise mean
 * fifty documents on screen to show fifty dates.
 */
export function useProjectVersion(projectId: string, versionId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.projects.version(projectId, versionId ?? ''),
    enabled: versionId !== null,
    queryFn: async (): Promise<ProjectVersion> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET(
        '/api/v1/projects/{projectId}/versions/{versionId}',
        { params: { ...ambient.params, path: { projectId, versionId: versionId ?? '' } } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
  });
}

export function useCreateProjectVersion(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (label: string | null): Promise<ProjectVersion> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST(
        '/api/v1/projects/{projectId}/versions',
        { params: { ...ambient.params, path: { projectId } }, body: { label } },
      );

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    // The version *number* is allocated by the server, so the list is refetched
    // rather than appended to. Guessing the next number here would be inventing
    // a sequence the database owns.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.projects.versions(projectId) }),
  });
}

/**
 * Restore: the reason the history exists.
 *
 * The response is the project as it now stands, so it is written into the
 * cache — but the version list is invalidated too, because restoring is itself
 * a change and the server may record it as one. Reading that from the response
 * would be assuming an answer the contract does not give.
 */
export function useRestoreProject(projectId: string) {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (versionId: string): Promise<Project> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/projects/{projectId}/restore', {
        params: { ...ambient.params, path: { projectId } },
        body: { version_id: versionId },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (project) => {
      queryClient.setQueryData(keys.projects.one(project.id), project);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.projects.versions(projectId) }),
        queryClient.invalidateQueries({ queryKey: keys.projects.lists }),
      ]);
    },
  });
}
