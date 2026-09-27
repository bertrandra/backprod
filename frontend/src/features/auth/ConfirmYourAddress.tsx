import { Link, Navigate } from '@tanstack/react-router';
import { useEffect, useRef, useState } from 'react';

import { t } from '@/i18n';
import { useResendEmailVerification, useVerifyEmail } from '@/queries/auth';
import type { AddressStanding } from '@/queries/catalogue';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { notice, panel } from '@/ui/tone';
import { When } from '@/ui/When';

/**
 * Proving an address, from the shell (ADR-061).
 *
 * A self-service sign-up is in at once — registering never waits — and has a
 * deadline to follow the link mailed to it. These are the three places the
 * shell says so: a banner while there is time, a screen once the deadline
 * has passed and the tenant surface answers `EMAIL_UNCONFIRMED`, and the
 * waiting screen of somebody a `DOMAIN` organisation admits once the address
 * is proved. Every one of them offers a new link, because every one of them
 * is answered by a click in a mailbox and by nobody else.
 *
 * Whether the address is proved, and the deadline, are the server's answer
 * (`GET /products` → `address`); nothing here decides either from a clock.
 */

/** A new link, and what became of the request — said truthfully. */
export function ResendConfirmation() {
  const resend = useResendEmailVerification();
  const [answer, setAnswer] = useState<boolean | null>(null);

  return (
    <div className="flex flex-wrap items-center gap-3">
      <Button
        type="button"
        variant="secondary"
        pending={resend.isPending}
        data-testid="resend-confirmation"
        onClick={() => {
          resend.mutate(undefined, { onSuccess: (sent) => setAnswer(sent) });
        }}
      >
        {t('Send a new link')}
      </Button>
      {answer === true && (
        <span role="status" className="text-sm text-muted">
          {t('A new link is on its way. It replaces the last one.')}
        </span>
      )}
      {answer === false && (
        <span role="status" className="text-sm text-muted">
          {t('No new link was sent: your address may already be confirmed, or a link went out less than a minute ago.')}
        </span>
      )}
      {resend.error !== null && <ErrorSurface error={resend.error} />}
    </div>
  );
}

/**
 * While there is still time: what to do, and by when. Nothing is refused yet,
 * so this sits above the screen rather than replacing it.
 */
export function AddressBanner({ address }: { address: AddressStanding }) {
  if (address.confirmed || address.confirm_by === null) {
    return null;
  }

  return (
    <div data-testid="address-banner" className={`mb-5 flex flex-col gap-3 ${panel('warning')}`}>
      <p>
        {t('Confirm your email address by following the link we sent you. Everything keeps working until the deadline:')}{' '}
        <When at={address.confirm_by} testId="address-deadline" />
      </p>
      <ResendConfirmation />
    </div>
  );
}

/**
 * Past the deadline: the tenant surface waits for the click. Nothing was
 * cancelled, and the link restores everything — which is what this says, so
 * nobody goes looking for a colleague, an administrator or a card.
 */
export function AddressOverdue() {
  return (
    <div data-testid="address-overdue" className="flex flex-col gap-4">
      <EmptyState
        title={t('Confirm your email address to continue')}
        description={t('The deadline to confirm the address you signed up with has passed. Nothing was cancelled: follow the link we sent you, or ask for a new one, and everything is back as it was.')}
      />
      <div className={notice('info')}>
        <ResendConfirmation />
      </div>
    </div>
  );
}

/**
 * Waiting on their own mailbox: the organisation admits this address's
 * domain, once the address is proved. Not "an administrator has been asked"
 * — nobody has been, and nobody needs to be.
 */
export function WaitingOnConfirmation({ names }: { names: string }) {
  return (
    <div data-testid="waiting-for-confirmation" className="flex flex-col gap-4">
      <EmptyState
        title={t('Confirm your email address to join {names}', { names })}
        description={t('{names} admits people from its own email domain once they have shown the address is theirs. Follow the link we sent you; you are in as soon as you do.', { names })}
      />
      <ResendConfirmation />
    </div>
  );
}

/** The address without the one-use token in it, so a reload cannot spend it twice. */
function withoutToken(previous: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(previous).filter(([key]) => key !== 'verify'));
}

/**
 * The confirmation link, followed by somebody already signed in — which is
 * the usual case: they signed up, kept working, and opened the mail in the
 * same browser (ADR-061).
 *
 * `/sign-in` only ever renders signed in, and used to forward straight to the
 * landing with the token still in the address and nobody spending it — so
 * the most common way of confirming an address confirmed nothing. The token
 * is spent here first, once (React runs effects twice in development, and a
 * token is single-use), and the person carries on where they were going.
 */
export function ConfirmWhileSignedIn({ token }: { token: string }) {
  const verification = useVerifyEmail();
  const attempted = useRef(false);

  useEffect(() => {
    if (!attempted.current) {
      attempted.current = true;
      verification.mutate(token);
    }
  }, [token, verification]);

  if (verification.isSuccess) {
    return <Navigate to="/" replace search={withoutToken} />;
  }

  if (verification.isError) {
    return (
      <main data-testid="confirmation-failed" className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-4 p-4">
        <EmptyState
          title={t('That confirmation link is no longer valid')}
          description={t('It may have been used already, or replaced by a newer one. Ask for a new link, or carry on: if your address is already confirmed, there is nothing left to do.')}
        />
        <ResendConfirmation />
        <Link to="/" search={withoutToken} className="text-sm underline">
          {t('Continue')}
        </Link>
      </main>
    );
  }

  return (
    <main className="mx-auto flex min-h-dvh max-w-sm items-center justify-center p-4">
      <p role="status" aria-busy="true" className="text-sm text-muted">
        {t('Confirming your address…')}
      </p>
    </main>
  );
}
