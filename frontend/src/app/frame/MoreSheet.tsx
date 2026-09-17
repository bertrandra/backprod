import { withRoot } from '@/app/root';
import { useSignOut } from '@/queries/auth';
import { useSessionStore } from '@/state/session';
import { Button } from '@/ui/Field';

import type { NavSection } from './navigation';

/**
 * The phone's menu: every section, in a drawer from the left.
 *
 * The bar holds five; everything is here, which is the whole point of §4.2's
 * rule that no region loses capability on a phone. It used to rise from the
 * bottom behind a "More" button; since 2026-09-17 it opens from the three
 * lines at the top left, and slides in from the same side, because a drawer
 * that arrives from where it was asked for is one nobody has to look for.
 */
export function MoreSheet({
  open,
  onClose,
  sections,
}: {
  open: boolean;
  onClose: () => void;
  sections: readonly NavSection[];
}) {
  const signOut = useSignOut();
  const root = useSessionStore((state) => state.root);

  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-40 flex items-stretch bg-black/40 md:hidden" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Menu"
        data-testid="menu-sheet"
        className="h-full w-[min(20rem,85vw)] overflow-y-auto border-r border-line bg-raised p-4 pt-[calc(1rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-float"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <p className="text-sm font-semibold">Menu</p>
          <button
            type="button"
            aria-label="Close menu"
            onClick={onClose}
            className="min-h-[44px] min-w-[44px] rounded-control text-lg focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            ×
          </button>
        </div>

        {sections.map((section) => (
          <div key={section.id} className="mb-4">
            <p className="pb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">
              {section.label}
            </p>
            <ul>
              {section.entries.map((entry) => (
                <li key={entry.id}>
                  <a
                    href={withRoot(root, entry.to)}
                    data-nav-more={entry.id}
                    className="block min-h-[44px] py-2 text-sm text-ink"
                  >
                    {entry.label}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}

        {/* Last, and separated: it is not a section of the application, it is
            the way out of it. On a phone this drawer is the only place the
            control fits — the bottom bar holds five destinations and none of
            them is an action. */}
        <div className="border-t border-line pt-3">
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
