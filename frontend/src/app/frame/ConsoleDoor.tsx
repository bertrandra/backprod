import { Link } from '@tanstack/react-router';

import { useStaffIdentity } from '@/queries/staff';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

/**
 * The way into the console, for the people who have one.
 *
 * Until this existed there was none. `useStaffIdentity` was called in exactly
 * one place in the whole frontend — `ConsoleShell` — so the tenant application
 * never asked whether the person using it was also platform staff, and could
 * not offer a link it did not know was warranted. The console was reachable
 * only by typing `/console/...` into the address bar, which the operator of a
 * fresh installation has no way to guess. It cost the same person the same
 * afternoon twice.
 *
 * **This does not merge the two navigations, and it must not.** Non-negotiable
 * #22 keeps the trees separate so that no tenant permission can ever reveal a
 * console route; `navigation.test.ts` asserts they share no id. This link is
 * not in `TENANT_NAV` and is not built from a tenant permission. It appears
 * only when `GET /staff/me` — the console's own identity, resolved from
 * `platform_staff` and nothing else — answers with a role.
 *
 * So the rule holds in the direction it was written for: a tenant role still
 * reveals nothing. What changes is that a *platform* role stops being invisible
 * to the person holding it.
 *
 * The query is shared with the console by its key, refuses to retry a 401 or a
 * 403, and is cached for minutes — so for the overwhelming majority of users,
 * who are not staff, this costs one refused request per session and renders
 * nothing.
 */
export function ConsoleDoor() {
  const { data } = useStaffIdentity();

  // `undefined` covers both "still loading" and "refused", and both mean the
  // same thing here: show nothing. A link that flickered in for non-staff
  // would be worse than no link at all.
  if (data === undefined || data.roles.length === 0) {
    return null;
  }

  return (
    <Link
      to="/console"
      data-testid="console-door"
      className={cn(
        touchTargetClass,
        'rounded border border-amber-500 bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-900 focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-amber-200',
      )}
    >
      Platform console
    </Link>
  );
}
