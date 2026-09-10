import { createContext, useContext, useMemo, type ReactNode } from 'react';

import { createApiClient, type ApiClient } from '@/api/client';
import { sessionSnapshot } from '@/state/session';

/**
 * One client for the application's lifetime.
 *
 * Built here rather than at module scope so a test can provide its own, and
 * memoised so it is not rebuilt on every render — a new client would drop the
 * middleware's identity and defeat any per-client state a future adapter keeps.
 *
 * It reads the token and product through `sessionSnapshot`, which reaches into
 * the store rather than closing over a value: both change while the application
 * runs, and a client that had captured them would keep addressing the product
 * the person just switched away from.
 */
const ApiClientContext = createContext<ApiClient | null>(null);

export function ApiProvider({ client, children }: { client?: ApiClient; children: ReactNode }) {
  const value = useMemo(
    () => client ?? createApiClient({ context: sessionSnapshot }),
    [client],
  );

  return <ApiClientContext.Provider value={value}>{children}</ApiClientContext.Provider>;
}

export function useApiClient(): ApiClient {
  const client = useContext(ApiClientContext);

  if (client === null) {
    throw new Error('useApiClient was called outside ApiProvider.');
  }

  return client;
}
