import type { ReactNode } from 'react';

import { useSession } from '@/queries/session';

import { can, isEntitled } from './access';

/**
 * Renders children when the person holds the permission.
 *
 * `fallback` is deliberate rather than defaulted to nothing: the two refusals
 * this platform distinguishes are answered by different people, so a caller
 * that wants to explain rather than hide can. Silence is the right default
 * only for navigation.
 */
export function Can({
  permission,
  children,
  fallback = null,
}: {
  permission: string;
  children: ReactNode;
  fallback?: ReactNode;
}) {
  const { data } = useSession();

  return can(data, permission) ? <>{children}</> : <>{fallback}</>;
}

/** The same, for a capability the tenant's plan includes. */
export function Entitled({
  capability,
  children,
  fallback = null,
}: {
  capability: string;
  children: ReactNode;
  fallback?: ReactNode;
}) {
  const { data } = useSession();

  return isEntitled(data, capability) ? <>{children}</> : <>{fallback}</>;
}
