import { BUILD } from '@/build';
import { currentLocale, t } from '@/i18n';

/**
 * Version and build, quietly, at the end of a menu (2026-09-27).
 *
 * Not a menu item — there is nothing to do with it — so it is text a screen
 * reader reads in passing and focus skips. The date is the build's, in the
 * reader's language; the full timestamp is in the tooltip.
 */
export function BuildStamp() {
  const built = new Date(BUILD.builtAt);
  const day = Number.isNaN(built.getTime()) ? BUILD.builtAt : built.toLocaleDateString(currentLocale());

  return (
    <p
      data-testid="build-stamp"
      title={BUILD.builtAt}
      className="mt-1 border-t border-line px-3 pb-1 pt-2 text-xs text-muted"
    >
      {t('Version {version} · build {commit} · {day}', { version: BUILD.version, commit: BUILD.commit, day })}
    </p>
  );
}
