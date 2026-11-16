import { useState } from 'react';

import { can, isEntitled } from '@/app/access/access';
import { useSession } from '@/queries/session';
import {
  useCreateProjectShare,
  useProjectShares,
  useRevokeProjectShare,
  type ProjectShare,
} from '@/queries/shares';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { currentLocale, t } from '@/i18n';

/** The feature an offer grants to sell share links (ADR-073). */
const SHARING_CAPABILITY = 'project_sharing';

/**
 * `workspace.shares` — read-only links to a project, for somebody with no
 * account (ADR-073).
 *
 * **The list is for whoever reaches the project**, the organisation's
 * administrator included, and it asks for no feature: somebody whose offer
 * stopped selling sharing still sees what they handed out, and can take it back.
 * Making a link needs the write permission *and* the feature, and the two
 * refusals are answered by different people, so the form says which is missing
 * instead of vanishing.
 *
 * **The URL is shown once, here, and then forgotten.** The server keeps only the
 * token's hash; this panel holds the URL in component state until the person
 * leaves the screen, and never in the query cache.
 *
 * The page a stranger reads is the product's own, at its address: this shell has
 * no viewer for a product's document, so the link leads there.
 */
export function SharesPanel({ projectId }: { projectId: string }) {
  const { data: access } = useSession();
  const shares = useProjectShares(projectId);
  const create = useCreateProjectShare(projectId);
  const revoke = useRevokeProjectShare(projectId);

  const [displayName, setDisplayName] = useState('');
  const [recipient, setRecipient] = useState('');
  const [days, setDays] = useState(30);
  const [created, setCreated] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const mayWrite = can(access, 'projects.write');
  const sold = isEntitled(access, SHARING_CAPABILITY);

  return (
    <section className="space-y-3" data-testid="shares-panel">
      <h2 className="text-xl font-semibold">{t("Share links")}</h2>

      <p className="text-sm text-muted">
        {t("A share link shows this project, read-only and as last saved, to somebody with no account. It stops working when it expires or when you revoke it.")}
      </p>

      {mayWrite && sold && (
        <form
          className="max-w-md space-y-3"
          onSubmit={(event) => {
            event.preventDefault();
            setCopied(false);
            create.mutate(
              {
                display_name: displayName.trim() === '' ? null : displayName.trim(),
                recipient_label: recipient.trim() === '' ? null : recipient.trim(),
                expires_in_days: days,
              },
              {
                onSuccess: (share) => {
                  setCreated(share.url);
                  setRecipient('');
                },
              },
            );
          }}
        >
          <Field id="share-display-name" label={t("Shown as the sender")} hint={t("Optional — the name the reader sees.")}>
            <input
              id="share-display-name"
              className={inputClass()}
              maxLength={80}
              value={displayName}
              onChange={(event) => setDisplayName(event.target.value)}
            />
          </Field>

          <Field id="share-recipient" label={t("For")} hint={t("Optional — only you see this, so you can revoke one person's link.")}>
            <input
              id="share-recipient"
              className={inputClass()}
              maxLength={120}
              value={recipient}
              onChange={(event) => setRecipient(event.target.value)}
            />
          </Field>

          <Field id="share-days" label={t("Days before it expires")} hint={t("From 1 to 365.")}>
            <input
              id="share-days"
              type="number"
              min={1}
              max={365}
              className={inputClass()}
              value={days}
              onChange={(event) => setDays(Number(event.target.value))}
            />
          </Field>

          {create.error !== null && <ErrorSurface error={create.error} />}

          <Button type="submit" pending={create.isPending}>
            {t("Create a link")}</Button>
        </form>
      )}

      {mayWrite && !sold && (
        <p className="text-sm text-muted" data-testid="shares-not-sold">
          {t("Your plan does not include share links.")}</p>
      )}

      {created !== null && (
        <div className="space-y-2 rounded-card border border-line bg-surface p-4" data-testid="share-created">
          <p className="text-sm font-medium">
            {t("Copy this link now — it will not be shown again.")}</p>
          <input
            readOnly
            aria-label={t("Share link")}
            className={inputClass()}
            value={created}
            onFocus={(event) => event.target.select()}
          />
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              void navigator.clipboard?.writeText(created).then(() => setCopied(true));
            }}
          >
            {copied ? t("Copied") : t("Copy")}</Button>
        </div>
      )}

      {revoke.error !== null && <ErrorSurface error={revoke.error} />}

      {shares.isPending ? (
        <SkeletonRows rows={2} />
      ) : shares.error !== null ? (
        <ErrorSurface error={shares.error} onRetry={() => void shares.refetch()} />
      ) : shares.data.length === 0 ? (
        <EmptyState title={t("No share links")} description={t("Nobody outside the organisation can see this project.")} />
      ) : (
        <ul className="space-y-2">
          {shares.data.map((share) => (
            <ShareRow
              key={share.id}
              share={share}
              mayRevoke={mayWrite}
              pending={revoke.isPending}
              onRevoke={() => revoke.mutate(share.id)}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

/**
 * One link. Its status is the server's word — derived on read against the
 * server's clock — so nothing here compares `expires_at` with the browser's.
 */
function ShareRow({
  share,
  mayRevoke,
  pending,
  onRevoke,
}: {
  share: ProjectShare;
  mayRevoke: boolean;
  pending: boolean;
  onRevoke: () => void;
}) {
  const locale = currentLocale();
  const status =
    share.status === 'ACTIVE' ? t("Active") : share.status === 'EXPIRED' ? t("Expired") : t("Revoked");

  return (
    <li
      data-share={share.id}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm md:flex md:items-center md:gap-3"
    >
      <div className="min-w-0 md:flex-1">
        <p className="truncate font-medium">
          {share.recipient_label ?? t("Link without a label")}
          {' · '}
          <span data-testid="share-status">{status}</span>
        </p>
        <p className="text-xs text-muted">
          {t("Expires {date}", { date: new Date(share.expires_at).toLocaleDateString(locale) })}
          {' · '}
          {share.open_count === 1
            ? t("Opened once")
            : t("Opened {count} times", { count: share.open_count })}
          {share.display_name !== null && <> · {t("Shown as {name}", { name: share.display_name })}</>}
        </p>
      </div>

      {mayRevoke && share.status === 'ACTIVE' && (
        <div className="mt-2 md:mt-0">
          <Button type="button" variant="secondary" pending={pending} onClick={onRevoke}>
            {t("Revoke")}</Button>
        </div>
      )}
    </li>
  );
}
