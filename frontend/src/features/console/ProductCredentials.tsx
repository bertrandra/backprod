import { useState } from 'react';

import { t } from '@/i18n';
import { tx } from '@/i18n/react';
import {
  PRODUCT_SCOPES,
  useIssueProductCredential,
  useProductCredentials,
  useRevokeProductCredential,
  type ProductCredential,
  type ProductScope,
} from '@/queries/staff';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { When } from '@/ui/When';

/**
 * A product's keys (ADR-051 §4): what its server holds to call the
 * platform with no person present.
 *
 * The bearer is shown **once**, the moment it is issued, in a box made to be
 * copied — the platform keeps its hash and can never say it again, so a
 * key lost is a key revoked and reissued, never recovered. The list says
 * what each key may do, when it expires and when it was last seen; a
 * revoked key stays listed, because the access log points at it.
 */
export function ProductCredentials({ productId }: { productId: string }) {
  const credentials = useProductCredentials(productId);
  const issue = useIssueProductCredential(productId);
  const revoke = useRevokeProductCredential(productId);

  const [label, setLabel] = useState('');
  const [scopes, setScopes] = useState<readonly ProductScope[]>(['product.entitlements.read']);
  const [issued, setIssued] = useState<{ bearer: string; label: string } | null>(null);
  // Thirty days from when the panel opened: "expires soon" is a warning
  // colour, not a clock, so once per mount is exact enough.
  const [soon] = useState(() => new Date(Date.now() + 30 * 24 * 3_600_000).toISOString());

  if (credentials.isPending) {
    return <SkeletonRows rows={2} />;
  }

  if (credentials.error !== null) {
    return <ErrorSurface error={credentials.error} onRetry={() => void credentials.refetch()} />;
  }

  const live = credentials.data.filter((credential) => credential.revoked_at === null);

  return (
    <section data-testid={`credentials-${productId}`} className="mt-3 space-y-3 border-t border-line pt-3">
      <h3 className="text-sm font-semibold">{t("Keys")}</h3>
      <p className="text-xs text-muted">
        {t("What the product's own server sends as a bearer to read a tenant's entitlements and members, or to report usage — with no person signed in. The product is derived from the key; a key issued here cannot speak for another product.")}
      </p>

      {issued !== null && (
        <div data-testid="issued-key" role="status" className="space-y-2 rounded-card border border-warning/40 bg-warning-wash p-3 text-sm">
          <p className="font-medium">{t("Copy this key now — it will not be shown again.")}</p>
          <code className="block select-all break-all rounded bg-surface p-2 text-xs">{issued.bearer}</code>
          <p className="text-xs text-muted">
            {tx("{label}: put it in the product server's environment as BACKPROD_PRODUCT_KEY. The platform keeps only its hash.", { label: <strong>{issued.label}</strong> })}
          </p>
          <Button type="button" variant="secondary" onClick={() => setIssued(null)}>
            {t("I have copied it")}
          </Button>
        </div>
      )}

      {credentials.data.length === 0 ? (
        <p className="text-xs text-subtle">{t("No key yet. Issue one below for the product's server.")}</p>
      ) : (
        <ul className="space-y-1 text-xs" data-testid="credential-list">
          {credentials.data.map((credential) => (
            <li
              key={credential.id}
              data-credential={credential.key_id}
              data-revoked={credential.revoked_at === null ? 'false' : 'true'}
              className="flex flex-wrap items-center gap-2 rounded border border-line px-2 py-1"
            >
              <span className="font-medium">{credential.label}</span>
              <code className="rounded bg-well px-1">{credential.key_id}</code>
              <span className="text-muted">{credential.scopes.join(', ')}</span>
              {credential.revoked_at !== null ? (
                <span className="text-danger">
                  {t("revoked")} <When at={credential.revoked_at} />
                </span>
              ) : (
                <span className={credential.expires_at !== null && credential.expires_at < soon ? 'text-warning' : 'text-subtle'}>
                  {credential.expires_at === null ? t("no expiry") : t("expires")} {credential.expires_at !== null && <When at={credential.expires_at} />}
                </span>
              )}
              <span className="text-subtle">
                {credential.last_used_at === null ? t("never used") : t("last used")} {credential.last_used_at !== null && <When at={credential.last_used_at} />}
              </span>
              {credential.revoked_at === null && (
                <Button
                  type="button"
                  variant="danger"
                  pending={revoke.isPending && revoke.variables === credential.id}
                  onClick={() => revoke.mutate(credential.id)}
                >
                  {t("Revoke")}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {revoke.error !== null && <ErrorSurface error={revoke.error} />}
      {issue.error !== null && <ErrorSurface error={issue.error} />}

      <form
        data-testid="issue-key-form"
        className="flex flex-wrap items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault();

          if (label.trim() === '' || scopes.length === 0) {
            return;
          }

          issue.mutate(
            { label: label.trim(), scopes: [...scopes] },
            {
              onSuccess: (answer) => {
                setIssued({ bearer: answer.bearer, label: answer.credential.label });
                setLabel('');
              },
            },
          );
        }}
      >
        <Field id={`key-label-${productId}`} label={t("Key label")} hint={t("Which deployment holds it: “plan production”, “plan staging”.")}>
          <input
            id={`key-label-${productId}`}
            className={inputClass()}
            value={label}
            onChange={(event) => setLabel(event.target.value)}
          />
        </Field>
        <fieldset className="space-y-1 text-xs">
          <legend className="font-medium">{t("Scopes")}</legend>
          {PRODUCT_SCOPES.map((scope) => (
            <label key={scope} className="flex items-center gap-2">
              <input
                type="checkbox"
                data-scope={scope}
                checked={scopes.includes(scope)}
                onChange={(event) =>
                  setScopes((current) => (event.target.checked ? [...current, scope] : current.filter((s) => s !== scope)))
                }
              />
              <code>{scope}</code>
            </label>
          ))}
        </fieldset>
        <Button type="submit" pending={issue.isPending} disabled={label.trim() === '' || scopes.length === 0}>
          {t("Issue a key")}
        </Button>
        {live.length > 0 && (
          <span className="text-xs text-subtle">
            {t("Rotation: issue the new key first, deploy it, then revoke the old one — the product is never without a valid key.")}
          </span>
        )}
      </form>
    </section>
  );
}

export type { ProductCredential };
