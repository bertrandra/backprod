import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { useState, type ReactNode } from 'react';

import { buildRouter, type AppRouter } from '@/app/router';
import { ApiError } from '@/queries/session';

import { ApiProvider } from './ApiProvider';

/**
 * Defaults chosen once, so no screen has to argue about them.
 *
 * The retry rule is the one that matters: 401, 403, 404 and 409 are *answers*.
 * Retrying cannot change them and only delays telling the person what happened.
 * A 429 is not retried either — the API is asking for less traffic, and
 * answering with more is the wrong reading of it.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        retry: (attempt, error) => {
          if (error instanceof ApiError) {
            const final = [400, 401, 403, 404, 409, 422, 429].includes(error.status);

            return !final && attempt < 2;
          }

          return attempt < 2;
        },
        refetchOnWindowFocus: false,
      },
      mutations: {
        // Never automatically. A mutation that retried itself could issue two
        // invoices for one intention, and the caller is the only thing that
        // knows whether repeating is safe.
        retry: false,
      },
    },
  });
}

export function AppProviders({
  children,
  queryClient,
}: {
  children: ReactNode;
  queryClient?: QueryClient;
}) {
  const [client] = useState(() => queryClient ?? createQueryClient());

  return (
    <QueryClientProvider client={client}>
      <ApiProvider>{children}</ApiProvider>
    </QueryClientProvider>
  );
}

/** The application, providers and router together. */
export function App({ router }: { router?: AppRouter }) {
  const [resolved] = useState(() => router ?? buildRouter());

  return (
    <AppProviders>
      <RouterProvider router={resolved} />
    </AppProviders>
  );
}
