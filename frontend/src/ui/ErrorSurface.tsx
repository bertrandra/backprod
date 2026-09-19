import { ApiError } from '@/queries/session';
import { panel } from '@/ui/tone';
import { t } from '@/i18n';

/**
 * A failure, rendered as something a person can act on.
 *
 * The §10.4 envelope carries four things and each has a job here:
 *
 *   - `code` is stable and machine-readable, so **this** is what decides the
 *     wording. Branching on `message` would break the moment the backend
 *     rephrases it, which the contract says may happen without notice.
 *   - `message` is the fallback text when a code has no specific wording yet.
 *     Shown rather than hidden, because the API's sentence is usually better
 *     than a generic one.
 *   - `details` names the field at fault or the limit exceeded. Rendered when
 *     it says something a person can use.
 *   - `request_id` is quoted, because the server log is keyed by it and a
 *     support thread without it costs a round trip.
 *
 * §31 keeps internals out of responses, so there is nothing here to leak — but
 * this component also never renders a stack or a raw body, so a future adapter
 * that is careless cannot leak through it either.
 */

/** Wording this application chooses, by code, where the API's is not enough. */
const WORDING: Record<string, { title: string; hint?: string }> = {
  UNAUTHENTICATED: {
    title: 'You are signed out',
    hint: 'Sign in again to continue. Nothing was lost.',
  },
  PERMISSION_DENIED: {
    title: 'You do not have access to this',
    hint: 'An administrator of your organisation can grant it.',
  },
  ENTITLEMENT_REQUIRED: {
    title: 'Your plan does not include this',
    hint: 'This one is answered by an upgrade rather than by an administrator.',
  },
  NO_TENANT_ACCESS: {
    title: 'You do not have access to this organisation',
  },
  VALIDATION_FAILED: {
    title: 'Something in the request was not valid',
  },
  TOO_MANY_REQUESTS: {
    title: 'Too many requests',
    hint: 'Wait a moment and try again.',
  },
  NETWORK_UNREACHABLE: {
    title: 'The request did not reach the server',
    hint: 'Your connection dropped, or the application is offline. Nothing was sent, so nothing was half-done — try again when it is back.',
  },
  UNEXPECTED_RESPONSE: {
    title: 'The server answered unexpectedly',
    hint: 'This is usually a proxy or a gateway rather than the application.',
  },
  // Money (2026-09-18). The server's `message` says what happened and its
  // `details.provider_message` says what the provider said; the title says
  // whether to try again, which is the one thing the person needs to know.
  PAYMENT_PROVIDER_REFUSED: {
    title: 'The payment could not be started',
    hint: 'Nothing was charged. Try again in a moment; if it keeps happening, the reason below is what to tell support.',
  },
  PAYMENT_ATTEMPT_COLLIDED: {
    title: 'This attempt clashed with an earlier one',
    hint: 'Nothing was charged. Trying again starts a fresh attempt.',
  },
  SUBSCRIPTION_ALREADY_ACTIVE: {
    title: 'The organisation already has a live subscription to this product',
    hint: 'Change it from the subscription screen rather than buying a second one.',
  },
  SEAT_ALREADY_ACTIVE: {
    title: 'You already hold a live seat on this product',
    hint: 'Change it from the subscription screen rather than buying a second one.',
  },
};

function detailLines(details: Readonly<Record<string, unknown>>): readonly string[] {
  return Object.entries(details)
    .filter(([, value]) => typeof value === 'string' || typeof value === 'number')
    .map(([key, value]) => `${key.replaceAll('_', ' ')}: ${String(value)}`);
}

export function ErrorSurface({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const api = error instanceof ApiError ? error : null;

  const code = api?.code ?? 'UNKNOWN';
  const chosen = WORDING[code];
  // The wording is data (a table by code) and translated where it is said.
  const title = t(chosen?.title ?? 'Something went wrong');
  const message = t(api?.message ?? 'The request could not be completed.');
  const details = api === null ? [] : detailLines(api.details);

  return (
    <div
      role="alert"
      className={panel('danger')}
    >
      <p className="font-medium text-danger">{title}</p>
      <p className="mt-1 text-danger">{message}</p>

      {chosen?.hint !== undefined && (
        <p className="mt-1 text-danger">{t(chosen.hint)}</p>
      )}

      {details.length > 0 && (
        <ul className="mt-2 list-inside list-disc text-danger">
          {details.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      )}

      <div className="mt-3 flex items-center gap-3">
        {onRetry !== undefined && (
          <button
            type="button"
            onClick={onRetry}
            className="rounded-control border border-danger/40 px-2.5 py-1 font-medium text-danger transition-colors hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger"
          >
            {t("Try again")}</button>
        )}

        {api !== null && api.requestId !== '' && (
          // Selectable, because the point of it is being pasted into a report.
          <code className="select-all text-xs text-danger">
            {t("request")}{' '}{api.requestId}
          </code>
        )}
      </div>
    </div>
  );
}
