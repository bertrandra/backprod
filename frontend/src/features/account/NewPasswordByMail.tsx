import { useForgotPassword } from '@/queries/auth';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * Asking for a new password from the profile (2026-09-23).
 *
 * The same operation the sign-in screen offers to somebody locked out —
 * `POST /auth/password/forgot`, a single-use link for thirty minutes, sent
 * as a SECURITY notice nobody can switch off. There is no second mechanism
 * for a person who *is* signed in, and there should not be: the link goes
 * to the address, which is the only thing that proves the account is still
 * theirs, and following it revokes every session of the account (ADR-038).
 * A form that took the current password and a new one here would decide the
 * same question from the browser that is already open — the one place where
 * a stolen session could change the password and keep the owner out.
 *
 * So the button says what happens rather than "change password", which
 * would promise a form that does not come. And it says the consequence
 * before it is clicked: choosing a new password signs this device out too.
 *
 * The server answers `202` whether or not the address has an account,
 * because that answer is the enumeration the sign-in form refuses to give.
 * Here the address is the signed-in person's own, so the screen may say
 * plainly that the link is on its way — it is not disclosing anything the
 * reader does not already know about themselves.
 */
export function NewPasswordByMail({ email }: { email: string | null }) {
  const ask = useForgotPassword();

  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="new-password">
      <h2 className="text-sm font-semibold">{t("Password")}</h2>

      {email === null ? (
        // Nothing to send to, so nothing is offered. An account with no
        // address cannot be recovered by mail, and a button that produced
        // an error would teach nothing.
        <p className="text-sm text-muted">{t("This account has no address, so no link can be sent.")}</p>
      ) : (
        <>
          <p className="max-w-prose text-sm text-muted">
            {tx("A link to choose a new password goes to {email}. It lasts thirty minutes and can be used once. Choosing a new password ends every session of this account — including this one, on this device.", {
              email: <strong>{email}</strong>,
            })}
          </p>

          {ask.error !== null && <ErrorSurface error={ask.error} />}

          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="button"
              variant="secondary"
              data-testid="ask-for-password-link"
              pending={ask.isPending}
              onClick={() => ask.mutate(email)}
            >
              {t("Send me a link")}</Button>

            {ask.isSuccess && (
              <span role="status" className="text-sm text-muted" data-testid="password-link-sent">
                {t("On its way. Check the address above.")}
              </span>
            )}
          </div>
        </>
      )}
    </section>
  );
}
