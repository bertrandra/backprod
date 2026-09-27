import { useEffect, useId, useRef, useState } from 'react';

import { t } from '@/i18n';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

/**
 * The home page's way in, for somebody who has no session: a person icon at
 * the top of the screen, opening a small menu — *Sign in*, and *Ask to join*
 * the organisation whose page this is (2026-09-27).
 *
 * **Where the signed-in shell keeps its account circle**, so the same corner
 * answers "who am I here" before and after signing in. It used to be a line
 * of text in the footer, below the whole story and every price — findable by
 * somebody who scrolled to the end, and by nobody who came back to sign in.
 *
 * **Still small, deliberately.** The page's first act is choosing something
 * to buy, and an account is what that produces; the menu is an icon, not a
 * form, so it does not compete with the offers.
 *
 * The same menu mechanics as `AccountMenu`: `aria-haspopup="menu"`,
 * `role="menu"`, Escape and a click outside close it and focus returns to the
 * button; the hit area is the platform's 44px (ui-spec §4.2).
 */
export function VisitorMenu({
  onSignIn,
  onSignUp,
  organisation,
}: {
  onSignIn: () => void;
  /** Absent when there is nobody to ask — a bare host with no default. */
  onSignUp?: (() => void) | undefined;
  /** The organisation a new joiner asks, named on the item. */
  organisation?: string | undefined;
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const menuId = useId();

  useEffect(() => {
    if (!open) {
      return;
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };

    const onPointerDown = (event: PointerEvent) => {
      if (rootRef.current !== null && !rootRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('pointerdown', onPointerDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('pointerdown', onPointerDown);
    };
  }, [open]);

  const item = cn(
    touchTargetClass,
    'block w-full rounded-control px-3 py-2 text-left text-sm hover:bg-well focus-visible:outline-2 focus-visible:outline-offset-2',
  );

  return (
    <div ref={rootRef} className="relative shrink-0">
      <button
        ref={buttonRef}
        type="button"
        data-testid="visitor-menu"
        title={t('Sign in or join')}
        aria-label={t('Sign in or join')}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        onClick={() => setOpen((was) => !was)}
        className={cn(touchTargetClass, 'grid place-items-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2')}
      >
        <span aria-hidden="true" className="grid size-8 place-items-center rounded-full bg-well">
          {/* A person: a head and shoulders, drawn in the current ink so it
              follows the theme. */}
          <svg viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21c0-4 3.6-7 8-7s8 3 8 7" />
          </svg>
        </span>
      </button>

      {open && (
        <div
          id={menuId}
          role="menu"
          aria-label={t('Sign in or join')}
          data-testid="visitor-menu-panel"
          className="absolute right-0 top-full z-20 mt-1 w-64 rounded-card border border-line bg-surface p-1 shadow-raise"
        >
          <button
            type="button"
            role="menuitem"
            data-testid="sign-in-link"
            onClick={() => {
              setOpen(false);
              onSignIn();
            }}
            className={item}
          >
            {t('Sign in')}
          </button>
          {onSignUp !== undefined && organisation !== undefined && (
            <button
              type="button"
              role="menuitem"
              data-testid="sign-up-link"
              onClick={() => {
                setOpen(false);
                onSignUp();
              }}
              className={item}
            >
              <span className="block">{t('New here?')}</span>
              <span className="block text-muted">{t('Ask to join {organisation}', { organisation })}</span>
            </button>
          )}
        </div>
      )}
    </div>
  );
}
