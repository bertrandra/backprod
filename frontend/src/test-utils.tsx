import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  createMemoryHistory,
  createRootRoute,
  createRoute,
  createRouter,
  RouterProvider,
  type AnyRouter,
} from '@tanstack/react-router';
import { render, type RenderResult } from '@testing-library/react';
import type { ReactNode } from 'react';

import type { ApiClient } from '@/api/client';
import { parseViewState, type ViewState } from '@/app/frame/viewState';
import { ApiProvider } from '@/app/providers/ApiProvider';
import { useSessionStore } from '@/state/session';

/**
 * Renders a screen with the providers it needs and a client that answers from a
 * table rather than the network.
 *
 * Retries are off and there is no cache between tests: a retry would make a
 * refusal take three attempts to assert, and a shared cache would let one test's
 * data satisfy another's query.
 */
export interface Stub {
  data?: unknown;
  error?: unknown;
  status?: number;
  /**
   * Holds the answer back.
   *
   * For the tests that have to assert what is on screen *while* a request is
   * still in flight — an optimistic update that has been rolled back but not yet
   * reconciled, say. Without it the refetch lands first and hides whether the
   * rollback happened at all.
   */
  delayMs?: number;
}

/**
 * A stub is either a fixed answer or a function called per request.
 *
 * The function form exists for the tests that have to distinguish *invalidated*
 * from *recomputed locally*: if the server answers differently the second time,
 * a screen showing the new value refetched, and a screen showing arithmetic on
 * the old one did not.
 */
export type Stubs = Partial<Record<string, Stub | (() => Stub)>>;

export function stubClient(responses: Stubs): ApiClient {
  // Not `async`: there is nothing to await, and a promise is what the caller
  // needs. An unnecessary `async` is what the lint rule is about.
  const answer = (method: string) => (path: string) => {
    const entry = responses[`${method} ${path}`] ?? responses[path];
    const found = typeof entry === 'function' ? entry() : entry;

    if (found === undefined) {
      // An unstubbed call is a failure the test can read, not a silent empty
      // response that makes a screen look merely empty.
      return Promise.resolve({
        error: {
          error: {
            code: 'NOT_STUBBED',
            message: `${method} ${path} was not stubbed`,
            details: {},
            request_id: 'test',
          },
        },
        response: new Response(null, { status: 404 }),
      });
    }

    const answered = {
      data: found.data,
      error: found.error,
      response: new Response(null, {
        status: found.status ?? (found.error === undefined ? 200 : 400),
      }),
    };

    return found.delayMs === undefined
      ? Promise.resolve(answered)
      : new Promise((resolve) => setTimeout(() => resolve(answered), found.delayMs));
  };

  return {
    GET: answer('GET'),
    POST: answer('POST'),
    PATCH: answer('PATCH'),
    PUT: answer('PUT'),
    DELETE: answer('DELETE'),
    use: () => undefined,
  } as unknown as ApiClient;
}

export function renderWith(ui: ReactNode, client: ApiClient): RenderResult {
  // Every request carries a product, and the client refuses to build one without
  // it — so a test that forgot this would fail on the product rather than on
  // whatever it meant to assert.
  useSessionStore.getState().chooseProduct('atlas');

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <ApiProvider client={client}>{ui}</ApiProvider>
    </QueryClientProvider>,
  );
}

export const SESSION = {
  user_id: 'u-1',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: 'p-1',
  tenant_id: 't-1',
  roles: ['TENANT_ADMIN'],
  permissions: ['skin.manage', 'members.manage', 'members.read', 'tenant.manage', 'tenant.read'],
  capabilities: ['white_label'],
};

/**
 * The same, for a screen that reads the URL or navigates.
 *
 * `ConversationsScreen` keeps the open thread in `?selected=` (ui-spec.md §4.3),
 * so it needs a router to be a screen at all. A real one is given to it — with
 * memory history and the same search validation the application uses — rather
 * than mocking `useSearch`: a mocked router would pass while `?selected=` was
 * spelled differently at the two ends, which is exactly the defect the URL-state
 * rule exists to prevent.
 */
export function renderAtRoute(
  ui: ReactNode,
  client: ApiClient,
  options: { path: string; initial?: string },
): RenderResult & { readonly location: () => string } {
  useSessionStore.getState().chooseProduct('atlas');

  const rootRoute = createRootRoute();
  const screenRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: options.path,
    validateSearch: (search: Record<string, unknown>): ViewState => parseViewState(search),
    component: () => <>{ui}</>,
  });

  // Typed as `AnyRouter`: the application registers *its* route tree with the
  // router module, and this one is deliberately not that tree.
  const router: AnyRouter = createRouter({
    routeTree: rootRoute.addChildren([screenRoute]),
    history: createMemoryHistory({ initialEntries: [options.initial ?? options.path] }),
  });

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } },
  });

  const result = render(
    <QueryClientProvider client={queryClient}>
      <ApiProvider client={client}>
        <RouterProvider router={router} />
      </ApiProvider>
    </QueryClientProvider>,
  );

  // Memory history, so `window.location` never moves: a test that wants to assert
  // deep-linkable state has to ask the router where it is.
  return { ...result, location: () => router.state.location.href };
}
