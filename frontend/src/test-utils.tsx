import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, type RenderResult } from '@testing-library/react';
import type { ReactNode } from 'react';

import type { ApiClient } from '@/api/client';
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
export function stubClient(
  responses: Partial<Record<string, { data?: unknown; error?: unknown; status?: number }>>,
): ApiClient {
  // Not `async`: there is nothing to await, and a promise is what the caller
  // needs. An unnecessary `async` is what the lint rule is about.
  const answer = (method: string) => (path: string) => {
    const found = responses[`${method} ${path}`] ?? responses[path];

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

    return Promise.resolve({
      data: found.data,
      error: found.error,
      response: new Response(null, {
        status: found.status ?? (found.error === undefined ? 200 : 400),
      }),
    });
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
