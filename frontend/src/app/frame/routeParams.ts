import { useParams } from '@tanstack/react-router';

/**
 * A path parameter, typed.
 *
 * The same problem `useViewState` solves for search params, and the same
 * answer. The route tree is built at runtime (`router.tsx`), so there is no
 * generated route union for the router to infer from and `useParams` answers
 * `any` — which would spread into every screen that takes an id and let
 * `projectId.toUpperCase()` compile on a number.
 *
 * Narrowed once, here. A missing or non-string parameter answers `null` rather
 * than an empty string: a screen must be able to tell "no id in this route"
 * from "an id that happens to be empty", and the queries below it are disabled
 * on `null`.
 */
export function useStringParam(name: string): string | null {
  const params: unknown = useParams({ strict: false });

  if (typeof params !== 'object' || params === null) {
    return null;
  }

  const value = (params as Record<string, unknown>)[name];

  return typeof value === 'string' && value !== '' ? value : null;
}
