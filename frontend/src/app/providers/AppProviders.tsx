import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { useState, type ReactNode } from 'react';

import { detectRoot } from '@/app/root';
import { buildRouter, type AppRouter } from '@/app/router';
import { useSessionStore } from '@/state/session';
import { useLocale } from '@/i18n/useLocale';
import { SignInGate } from '@/features/auth/SignInGate';
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

/**
 * The application, providers and router together.
 *
 * The gate sits between the providers and the router: inside, so the sign-in
 * screen can use the same query client and the token it obtains reaches the API
 * client through `ApiProvider`; outside the router, so no screen renders — and no
 * query fires — before there is a session to fire it with. A gate inside the
 * router would have every screen mount, request, and get a 401 first.
 */
export function App({ router }: { router?: AppRouter }) {
  const [resolved] = useState(() => {
    if (router !== undefined) {
      return router;
    }

    // Where this page is: the organisation's root, or the bare host. Decided
    // once, before the router exists, from the address alone.
    const detected = detectRoot(window.location.pathname);
    useSessionStore.getState().enterRoot(detected.root, detected.slug);

    return buildRouter(detected.root);
  });

  // The language (ADR-050): decided once from the address and the browser,
  // applied by keying the whole tree on it — a change is rare, and remounting
  // is cheaper and surer than a thousand subscriptions. Nothing renders
  // before the catalogue is in, so no screen paints English and then flips.
  const { locale, ready } = useLocale();

  if (!ready) {
    return null;
  }

  return (
    <AppProviders key={locale}>
      <SignInGate>
        <RouterProvider router={resolved} />
      </SignInGate>
    </AppProviders>
  );
}
