import { Link } from '@tanstack/react-router';
import { useEffect, useId, useRef, useState } from 'react';

import { useSignOut } from '@/queries/auth';
import { useSession } from '@/queries/session';
import { useStaffIdentity } from '@/queries/staff';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * Region A's last item: who is signed in, and the way out.
 *
 * A circle with the person's initial. Hovering it says their name; opening
 * it shows the name and address in full and offers *Your profile* — name,
 * default product, language (2026-09-20) — and *Sign out*. The profile is
 * a tenant screen for somebody `/me` knows; platform staff with no
 * membership have one of their own on the console (2026-09-22), with the
 * name and the language and no default product, because until then the
 * platform administrator was the one person with no way to either. Until
 * this menu existed the circle was decoration and signing out lived only
 * in the phone's More sheet and the console's menu, so on a desktop tenant
 * screen there was no way out at all.
 *
 * **Who to name.** A tenant member is `/me`; platform staff have no tenant
 * and `/me` refuses them, so their name comes from `/staff/me` — which
 * carries it since this menu needed it. Whichever answered is who is shown;
 * an erased person (§26) is shown by their initial `?` and signs out like
 * anybody else.
 *
 * A real menu, not a tooltip with a button in it: `aria-haspopup="menu"`,
 * `role="menu"`, Escape and a click outside close it, focus returns to the
 * circle. Native `title` carries the hover name, which is what a screen
 * reader also announces through `aria-label`.
 */
export function AccountMenu() {
  const { data: session } = useSession();
  const { data: staff } = useStaffIdentity();
  const signOut = useSignOut();

  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const menuId = useId();

  const displayName = session?.displayName ?? staff?.displayName ?? null;
  const email = session?.email ?? staff?.email ?? null;
  const name = displayName ?? email ?? 'Account';
  const initial = (displayName ?? email ?? '?').slice(0, 1).toUpperCase();

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

  return (
    <div ref={rootRef} className="relative shrink-0">
      <button
        ref={buttonRef}
        type="button"
        data-testid="account-menu"
        title={name}
        aria-label={t("Account: {name}", { name: name })}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        onClick={() => setOpen((was) => !was)}
        className={cn(
          // The hit area is the platform's 44px (ui-spec §4.2); the circle
          // inside it stays small so the bar keeps its height.
          touchTargetClass,
          'grid place-items-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2',
        )}
      >
        <span aria-hidden="true" className="grid size-8 place-items-center rounded-full bg-well text-xs font-medium">
          {initial}
        </span>
      </button>

      {open && (
        <div
          id={menuId}
          role="menu"
          aria-label={t("Account")}
          data-testid="account-menu-panel"
          className="absolute right-0 top-full z-20 mt-1 w-64 rounded-card border border-line bg-surface p-1 shadow-raise"
        >
          <div className="px-3 py-2">
            <p className="truncate text-sm font-medium" data-testid="account-name">
              {name}
            </p>
            {email !== null && email !== displayName && (
              <p className="truncate text-xs text-muted" data-testid="account-email">
                {email}
              </p>
            )}
          </div>

          {/* The tenant profile for a member; the console's for staff whom
              `/me` refuses. Whichever answered first is not the question —
              a member who is also staff has the fuller screen. */}
          {(session !== undefined || staff !== undefined) && (
            <Link
              to={session !== undefined ? '/profile' : '/console/profile'}
              search={(previous: Record<string, unknown>) => previous}
              role="menuitem"
              data-testid="account-profile"
              onClick={() => setOpen(false)}
              className={cn(
                touchTargetClass,
                'block w-full rounded-control px-3 py-2 text-left text-sm hover:bg-well focus-visible:outline-2 focus-visible:outline-offset-2',
              )}
            >
              {t("Your profile")}
            </Link>
          )}

          <button
            type="button"
            role="menuitem"
            disabled={signOut.isPending}
            onClick={() => signOut.mutate()}
            className={cn(
              touchTargetClass,
              'w-full rounded-control px-3 py-2 text-left text-sm hover:bg-well focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-60',
            )}
          >
            {signOut.isPending ? t("Signing out…") : t("Sign out")}
          </button>
        </div>
      )}
    </div>
  );
}
