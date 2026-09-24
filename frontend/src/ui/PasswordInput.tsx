import { useState, type InputHTMLAttributes, type Ref } from 'react';

import { inputClass, touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * A password field with the eye at its end.
 *
 * **Showing a password is a security feature, not a hole in one.** A field
 * nobody can read is a field people type twice, paste from somewhere less
 * safe, or abandon for a shorter secret they can get right blind — and on a
 * phone, where every character is a guess, that is most of them. The
 * browsers that ship this by default reached the same conclusion.
 *
 * It starts hidden and returns to hidden on its own terms: nothing else on
 * the screen flips it, and it is a `type="button"`, so pressing it in a
 * form does not submit. The toggle is what changes — `type` alone — so the
 * value, its `autoComplete` and whatever a password manager attached to
 * the field all survive being revealed.
 *
 * `aria-pressed` rather than a label that lies: a screen reader is told
 * this is a toggle and which way it stands, instead of reading "show
 * password" over a field that is already showing it.
 */
export function PasswordInput({
  ref,
  invalid = false,
  className,
  ...rest
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
  ref?: Ref<HTMLInputElement>;
  invalid?: boolean;
}) {
  const [shown, setShown] = useState(false);

  return (
    <div className="relative">
      <input
        ref={ref}
        type={shown ? 'text' : 'password'}
        // Room for the button, so a long password never runs under it.
        className={cn(inputClass(invalid), 'pr-12', className)}
        {...rest}
      />

      <button
        type="button"
        data-testid="toggle-password"
        aria-pressed={shown}
        aria-label={shown ? t("Hide the password") : t("Show the password")}
        title={shown ? t("Hide the password") : t("Show the password")}
        onClick={() => setShown((was) => !was)}
        className={cn(
          touchTargetClass,
          'absolute inset-y-0 right-0 flex items-center justify-center rounded-control px-3 text-muted',
          'hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2',
        )}
      >
        {shown ? <EyeOff /> : <Eye />}
      </button>
    </div>
  );
}

/** Drawn rather than imported: two shapes, and no icon set to carry. */
function Eye() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M1.5 10S4.8 4.5 10 4.5 18.5 10 18.5 10 15.2 15.5 10 15.5 1.5 10 1.5 10Z" />
      <circle cx="10" cy="10" r="2.6" />
    </svg>
  );
}

function EyeOff() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      <path d="M1.5 10S4.8 4.5 10 4.5c1.2 0 2.3.3 3.2.7M18.5 10s-1.4 2.3-3.9 3.8M8.2 8.2a2.6 2.6 0 0 0 3.6 3.6" />
      <path d="M3 17 17 3" />
    </svg>
  );
}
