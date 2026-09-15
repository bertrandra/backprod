import { Link } from '@tanstack/react-router';

import { useSignOut } from '@/queries/auth';
import { Button, touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

import type { NavEntry, NavSection } from './navigation';

/**
 * The console's own navigation (ADR-047).
 *
 * A platform administrator had no way between the admin screens that was
 * theirs: the left rail lists them among everything else on a desktop, and
 * on a phone the five-slot bottom bar held the first five and buried the rest
 * under "More". This is a menu of exactly the platform's screens — every one
 * the person's platform role allows, grouped as the tree groups them — with
 * one way back to the application.
 *
 * **Region C's header, not a seventh region.** `docs/ui-spec.md` §4.1 says the
 * view header never holds global concerns, and this is not one: it is the
 * navigation of the screen family the view belongs to, shown only while a
 * `/console/*` screen is in the view. Region B stays the primary navigation
 * for the whole application; this is the console's table of contents.
 *
 * **`<details>`, on purpose.** A dropdown per section, and each one is a
 * native disclosure: keyboard-operable, announced, closable with Escape by the
 * browser, and needing no script to be any of those things. A menu built from
 * divs and `onClick` would have to reimplement all four and would get one
 * wrong.
 */
export function ConsoleMenuBar({
  sections,
  tenantApp,
  currentPath,
}: {
  sections: readonly NavSection[];
  /** Where "the application" is for this person, or nowhere. */
  tenantApp: NavEntry | undefined;
  currentPath: string;
}) {
  return (
    <nav aria-label="Console" data-testid="console-menu" className="hidden flex-wrap items-center gap-1 md:flex">
      {sections.map((section) => (
        <details key={section.id} className="group relative" data-console-section={section.id}>
          <summary
            className={cn(
              touchTargetClass,
              'flex cursor-pointer list-none items-center gap-1 rounded-control px-3 py-1.5 text-sm font-medium text-ink hover:bg-well focus-visible:outline-2 focus-visible:outline-offset-2 [&::-webkit-details-marker]:hidden',
              section.entries.some((entry) => entry.to === currentPath) && 'bg-well',
            )}
          >
            {section.label}
            <span aria-hidden="true" className="text-xs text-subtle group-open:rotate-180">
              ▾
            </span>
          </summary>

          <ul className="absolute left-0 z-20 mt-1 min-w-48 rounded-card border border-line bg-raised p-1 shadow-float">
            {section.entries.map((entry) => (
              <li key={entry.id}>
                <Link
                  to={entry.to}
                  data-console-nav={entry.id}
                  aria-current={entry.to === currentPath ? 'page' : undefined}
                  className={cn(
                    'block rounded-control px-3 py-2 text-sm text-ink hover:bg-well focus-visible:outline-2 focus-visible:outline-offset-2',
                    entry.to === currentPath && 'font-semibold',
                  )}
                >
                  {entry.label}
                </Link>
              </li>
            ))}
          </ul>
        </details>
      ))}

      <TenantAppLink tenantApp={tenantApp} className="ml-auto" />
    </nav>
  );
}

/**
 * The same menu on a phone: full screen, one tap per screen.
 *
 * Region F, like the More sheet it replaces on console paths, and carrying the
 * same sign-out block for the same reason — on a phone this is the only place
 * the control fits.
 */
export function ConsoleMenuSheet({
  open,
  onClose,
  sections,
  tenantApp,
  currentPath,
}: {
  open: boolean;
  onClose: () => void;
  sections: readonly NavSection[];
  tenantApp: NavEntry | undefined;
  currentPath: string;
}) {
  const signOut = useSignOut();

  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-40 bg-black/40 md:hidden" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Console menu"
        data-testid="console-menu-sheet"
        className="flex h-full w-full flex-col overflow-y-auto bg-raised p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="mb-3 flex items-center justify-between">
          <p className="text-base font-semibold">Console</p>
          <button
            type="button"
            onClick={onClose}
            className={cn(touchTargetClass, 'rounded-control px-3 py-1 text-sm text-muted')}
          >
            Close
          </button>
        </div>

        {sections.map((section) => (
          <div key={section.id} className="mb-4" data-console-section={section.id}>
            <p className="pb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">
              {section.label}
            </p>
            <ul>
              {section.entries.map((entry) => (
                <li key={entry.id}>
                  <Link
                    to={entry.to}
                    data-console-nav={entry.id}
                    aria-current={entry.to === currentPath ? 'page' : undefined}
                    onClick={onClose}
                    className={cn(
                      'block min-h-[44px] py-2 text-sm text-ink',
                      entry.to === currentPath && 'font-semibold',
                    )}
                  >
                    {entry.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        ))}

        <div className="mt-auto space-y-3 border-t border-line pt-3">
          <TenantAppLink tenantApp={tenantApp} onClick={onClose} />

          <Button
            type="button"
            variant="secondary"
            pending={signOut.isPending}
            onClick={() => signOut.mutate()}
          >
            Sign out
          </Button>
        </div>
      </div>
    </div>
  );
}

/**
 * The way back. A person who is also a member of a tenant returns to the
 * first screen they may open there; one who is not has only the front door,
 * which for a signed-in platform administrator is the same shell asking for a
 * product — honest, and the only address there is.
 */
function TenantAppLink({
  tenantApp,
  className,
  onClick,
}: {
  tenantApp: NavEntry | undefined;
  className?: string;
  onClick?: () => void;
}) {
  return (
    <Link
      to={tenantApp?.to ?? '/'}
      data-testid="tenant-app-link"
      onClick={onClick}
      className={cn(
        touchTargetClass,
        'inline-flex items-center gap-1 rounded-control border border-line px-3 py-1.5 text-sm text-muted hover:bg-well focus-visible:outline-2 focus-visible:outline-offset-2',
        className,
      )}
    >
      <span aria-hidden="true">←</span> Tenant app
    </Link>
  );
}
