import { useEffect, useRef } from 'react';
import { t } from '@/i18n';

/**
 * Region F, as a shell.
 *
 * U1 gives it the behaviour that is hard to retrofit — a keyboard shortcut,
 * focus moved in and restored on close, Escape, and a labelled dialog — and no
 * commands. Commands arrive with the screens that have something to command.
 *
 * Focus restoration is the part worth doing now: a dialog that drops focus on
 * close leaves a keyboard user at the top of the document, and every screen
 * after this one would inherit that.
 */
export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const inputRef = useRef<HTMLInputElement>(null);
  const returnTo = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    returnTo.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    inputRef.current?.focus();

    return () => {
      returnTo.current?.focus();
    };
  }, [open]);

  useEffect(() => {
    if (!open) {
      return;
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open, onClose]);

  if (!open) {
    return null;
  }

  return (
    <div
      className="fixed inset-0 z-40 flex items-start justify-center bg-black/40 p-4 pt-[10vh]"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={t("Command palette")}
        data-testid="command-palette"
        // Full width on a phone, where a centred narrow dialog wastes the
        // screen it is competing for.
        className="w-full max-w-lg rounded-card border border-line bg-raised p-3 shadow-float"
        onClick={(event) => event.stopPropagation()}
      >
        <input
          ref={inputRef}
          type="search"
          aria-label={t("Search commands")}
          placeholder={t("Search — commands arrive with the screens that have them")}
          className="w-full rounded-control border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2"
        />
        <p className="mt-2 px-1 text-xs text-subtle">{t("Nothing to run yet (U1).")}</p>
      </div>
    </div>
  );
}

/** ⌘K / Ctrl-K, registered once by the shell. */
export function usePaletteShortcut(onOpen: () => void) {
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        onOpen();
      }
    };

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onOpen]);
}
