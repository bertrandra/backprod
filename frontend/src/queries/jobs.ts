import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { ambientParams, type Schemas } from '@/api/client';
import { useApiClient } from '@/app/providers/ApiProvider';
import { sessionSnapshot } from '@/state/session';

import { keys } from './keys';
import { toApiError } from './session';

/**
 * Long-running work, and the one place this application admits that time passes.
 *
 * The backend is honest about it: jobs are cron-polled, `run_after` means work
 * waits, and a late run is late rather than wrong (ui-spec.md §4.1). So region E
 * exists to say what has not finished, and this file is what feeds it.
 *
 * **Polling is conditional on there being something to poll for.** A fixed
 * interval would ask forever on a screen where nothing is running, and a screen
 * that stopped asking would leave a finished export undiscovered until a reload
 * — which is precisely the exit criterion U4 has to meet. So the interval is a
 * function of the data: while a job is queued or running, ask again; when none
 * is, stop.
 */

export type Job = Schemas['Job'];
export type JobStatus = Job['status'];

/** The two states in which work has not finished. */
export const UNFINISHED: readonly JobStatus[] = ['QUEUED', 'RUNNING'];

export function isUnfinished(job: Job): boolean {
  return (UNFINISHED as readonly string[]).includes(job.status);
}

/**
 * How often to ask while something is still running.
 *
 * Two seconds is a compromise between a status strip that looks frozen and a
 * request every heartbeat. The queue is cron-polled anyway, so a faster poll
 * would mostly discover that nothing has changed yet.
 */
export const POLL_MS = 2_000;

export function useJobs(limit = 25, offset = 0, enabled = true) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.jobs.list(limit, offset),
    // The caller decides, because the caller knows whether this session may read
    // jobs at all. Region E lives in both shells and platform staff hold no
    // tenant permissions, so an ungated poll would refuse every two seconds.
    enabled,
    queryFn: async () => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/jobs', {
        params: { ...ambient.params, query: { limit, offset } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    refetchInterval: (query) => {
      const jobs = query.state.data?.jobs ?? [];

      return jobs.some(isUnfinished) ? POLL_MS : false;
    },
  });
}

/**
 * One job, polled until it stops moving.
 *
 * This is what turns "an export was requested" into "an export is ready"
 * without a reload. It stops of its own accord: a SUCCEEDED, FAILED or
 * CANCELLED job is finished, and asking again would be asking about the past.
 */
export function useJob(jobId: string | null) {
  const client = useApiClient();

  return useQuery({
    queryKey: keys.jobs.one(jobId ?? ''),
    enabled: jobId !== null,
    queryFn: async (): Promise<Job> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.GET('/api/v1/jobs/{jobId}', {
        params: { ...ambient.params, path: { jobId: jobId ?? '' } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    refetchInterval: (query) => {
      const job = query.state.data;

      return job !== undefined && isUnfinished(job) ? POLL_MS : false;
    },
  });
}

export function useRequestJob() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: {
      type: string;
      payload?: Record<string, unknown>;
      idempotency_key?: string | null;
    }): Promise<Job> => {
      const { data, error, response } = await client.POST('/api/v1/jobs', {
        ...ambientParams(sessionSnapshot),
        body: input,
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (job) => {
      queryClient.setQueryData(keys.jobs.one(job.id), job);
      await queryClient.invalidateQueries({ queryKey: keys.jobs.lists });
    },
  });
}

/**
 * Cancelling.
 *
 * The response is the job in its new state, so it is written — but the list is
 * invalidated, because whether a cancel *took* is the server's answer: a job
 * that started running between the click and the request may come back RUNNING,
 * and a screen that had already crossed it out would be lying.
 */
export function useCancelJob() {
  const client = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (jobId: string): Promise<Job> => {
      const ambient = ambientParams(sessionSnapshot);

      const { data, error, response } = await client.POST('/api/v1/jobs/{jobId}/cancel', {
        params: { ...ambient.params, path: { jobId } },
      });

      if (error !== undefined || data === undefined) {
        throw toApiError(response.status, error);
      }

      return data;
    },
    onSuccess: async (job) => {
      queryClient.setQueryData(keys.jobs.one(job.id), job);
      await queryClient.invalidateQueries({ queryKey: keys.jobs.lists });
    },
  });
}
