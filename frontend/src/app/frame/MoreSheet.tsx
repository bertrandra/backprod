import { useSessionStore } from '@/state/session';
import { Button } from '@/ui/Field';

import type { NavSection } from './navigation';

/**
 * What the phone's bottom bar could not fit.
 *
 * The bar holds five; everything else is here rather than unreachable, which is
 * the whole point of §4.2's rule that no region loses capability on a phone.
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
  const signOut = useSessionStore((state) => state.signOut);

  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-40 flex items-end bg-black/40 md:hidden" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="All sections"
        data-testid="more-sheet"
        className="max-h-[70dvh] w-full overflow-y-auto rounded-t-2xl bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] dark:bg-neutral-900"
        onClick={(event) => event.stopPropagation()}
      >
        {sections.map((section) => (
          <div key={section.id} className="mb-4">
            <p className="pb-1 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
              {section.label}
            </p>
            <ul>
              {section.entries.map((entry) => (
                <li key={entry.id}>
                  <a
                    href={entry.to}
                    data-nav-more={entry.id}
                    className="block min-h-[44px] py-2 text-sm text-neutral-800 dark:text-neutral-200"
                  >
                    {entry.label}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}

        {/* Last, and separated: it is not a section of the application, it is
            the way out of it. On a phone this sheet is the only place the
            control fits — the bottom bar holds five destinations and none of
            them is an action. */}
        <div className="border-t border-neutral-200 pt-3 dark:border-neutral-800">
          <Button type="button" variant="secondary" onClick={signOut}>
            Sign out
          </Button>
        </div>
      </div>
    </div>
  );
}
