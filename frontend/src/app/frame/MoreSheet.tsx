import { withRoot } from '@/app/root';
import { useSessionStore } from '@/state/session';
import { cn } from '@/utils/cn';

import type { NavSection } from './navigation';
import { t } from '@/i18n';

/**
 * The phone's menu: every section, in a drawer from the left.
 *
 * The bar holds five; everything is here, which is the whole point of §4.2's
 * rule that no region loses capability on a phone. It used to rise from the
 * bottom behind a "More" button; since 2026-09-17 it opens from the three
 * lines at the top left, and slides in from the same side, because a drawer
 * that arrives from where it was asked for is one nobody has to look for.
 *
 * No sign-out here (2026-09-18): the account menu in the bar carries it at
 * every width, and a drawer that also did was the one place where the phone
 * differed from the desktop for no reason.
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
  const root = useSessionStore((state) => state.root);

  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-40 flex items-stretch bg-black/40 md:hidden" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={t("Menu")}
        data-testid="menu-sheet"
        className="h-full w-[min(20rem,85vw)] overflow-y-auto border-r border-line bg-raised p-4 pt-[calc(1rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-float"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <p className="text-sm font-semibold">{t("Menu")}</p>
          <button
            type="button"
            aria-label={t("Close menu")}
            onClick={onClose}
            className="min-h-[44px] min-w-[44px] rounded-control text-lg focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            ×
          </button>
        </div>

        {sections.map((section, index) => (
          <div key={section.id} className={cn('mb-4', index > 0 && 'border-t border-line pt-3')}>
            <p className="pb-1 text-[11px] font-bold uppercase tracking-[0.12em] text-ink">
              {t(section.label)}
            </p>
            <ul>
              {section.entries.map((entry) => (
                <li key={entry.id}>
                  <a
                    href={withRoot(root, entry.to)}
                    data-nav-more={entry.id}
                    className="block min-h-[44px] py-2 text-sm text-ink"
                  >
                    {t(entry.label)}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}

        {/* The product's story (2026-09-24). On a phone it is here rather
            than in region A: the bar already holds the organisation, the
            product switcher, search and the account at 375px, and a sixth
            thing in it overlapped the search button. §4.2's rule is that no
            region loses a *capability* on a phone, not that every region
            holds the same controls — this drawer is where region A's
            secondary entries go. */}
        <div className="border-t border-line pt-3">
          <a
            href={withRoot(root, '/')}
            data-testid="product-story-more"
            className="block min-h-[44px] py-2 text-sm text-ink"
          >
            {t("About this product")}</a>
        </div>
      </div>
    </div>
  );
}
