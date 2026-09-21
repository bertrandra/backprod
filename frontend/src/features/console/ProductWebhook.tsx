import { useState } from 'react';

import { t } from '@/i18n';
import { tx } from '@/i18n/react';
import {
  useIssueWebhookSecret,
  useRetryWebhookDelivery,
  useWebhookDeliveries,
  type PlatformProduct,
  type WebhookDelivery,
} from '@/queries/staff';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { When } from '@/ui/When';

/**
 * What the platform tells a product beside it (ADR-051 §5): the address
 * events go to, the secret that signs them, and what was sent.
 *
 * The secret is shown **once**, the moment it is issued — the platform keeps
 * it sealed and never says it again. Issuing is also rotating: the one
 * before keeps signing for a day, which is said on the button so the
 * operator knows nothing breaks while they swap the product's copy.
 *
 * The deliveries are the answer to "did the product hear?": each with its
 * last status, a parked one with a Retry. Never the payload — a tenant's
 * subscription state is the product's to read on its own route.
 */
export function ProductWebhook({
  product,
  pending,
  onSetWebhookUrl,
}: {
  product: PlatformProduct;
  pending: boolean;
  onSetWebhookUrl: (url: string | null) => void;
}) {
  const deliveries = useWebhookDeliveries(product.id);
  const issue = useIssueWebhookSecret(product.id);
  const retry = useRetryWebhookDelivery(product.id);

  const [address, setAddress] = useState(product.webhook_url ?? '');
  const [secret, setSecret] = useState<string | null>(null);

  return (
    <section data-testid={`webhook-${product.id}`} className="mt-3 space-y-3 border-t border-line pt-3">
      <h3 className="text-sm font-semibold">{t("Webhook")}</h3>
      <p className="text-xs text-muted">
        {t("Where the platform tells this product what happened — a subscription started or ended, a member joined or left, a person erased — as signed events its server verifies before reading. Ids and states only; the product fetches anything richer through its own routes.")}
      </p>

      <form
        data-testid="webhook-url-form"
        className="flex flex-wrap items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          onSetWebhookUrl(address.trim() === '' ? null : address.trim());
        }}
      >
        <Field
          id={`webhook-url-${product.id}`}
          label={t("Webhook address")}
          hint={t("An https address on the product's server. Empty stops delivering — nothing is queued for a product with no address.")}
        >
          <input
            id={`webhook-url-${product.id}`}
            type="url"
            placeholder="https://plan.example.test/api/v1/plan/platform-events"
            className={inputClass()}
            value={address}
            onChange={(event) => setAddress(event.target.value)}
          />
        </Field>
        <Button type="submit" pending={pending}>
          {t("Save")}
        </Button>
      </form>

      {secret !== null && (
        <div data-testid="issued-secret" role="status" className="space-y-2 rounded-card border border-warning/40 bg-warning-wash p-3 text-sm">
          <p className="font-medium">{t("Copy this secret now — it will not be shown again.")}</p>
          <code className="block select-all break-all rounded bg-surface p-2 text-xs">{secret}</code>
          <p className="text-xs text-muted">
            {tx("Put it in the product server's environment as {name}. The one it replaces keeps signing for a day, so deploy first and nothing is refused in between.", { name: <code>BACKPROD_WEBHOOK_SECRET</code> })}
          </p>
          <Button type="button" variant="secondary" onClick={() => setSecret(null)}>
            {t("I have copied it")}
          </Button>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2 text-xs">
        <span className="text-muted" data-testid="webhook-secret-state">
          {product.webhook_secret_issued_at === null || product.webhook_secret_issued_at === undefined
            ? t("No secret yet: nothing is delivered until one is issued.")
            : tx("Secret issued {when}.", { when: <When at={product.webhook_secret_issued_at} /> })}
        </span>
        <Button
          type="button"
          variant="secondary"
          pending={issue.isPending}
          onClick={() => issue.mutate(undefined, { onSuccess: (answer) => setSecret(answer.secret) })}
        >
          {product.webhook_secret_issued_at ? t("Rotate the secret") : t("Issue a secret")}
        </Button>
      </div>

      {issue.error !== null && <ErrorSurface error={issue.error} />}
      {retry.error !== null && <ErrorSurface error={retry.error} />}

      {deliveries.isPending ? (
        <SkeletonRows rows={2} />
      ) : deliveries.error !== null ? (
        <ErrorSurface error={deliveries.error} onRetry={() => void deliveries.refetch()} />
      ) : deliveries.data.length === 0 ? (
        <p className="text-xs text-subtle">{t("Nothing sent yet.")}</p>
      ) : (
        <ul className="space-y-1 text-xs" data-testid="delivery-list">
          {deliveries.data.map((delivery) => (
            <DeliveryRow
              key={delivery.id}
              delivery={delivery}
              retrying={retry.isPending && retry.variables === delivery.id}
              onRetry={() => retry.mutate(delivery.id)}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

function DeliveryRow({
  delivery,
  retrying,
  onRetry,
}: {
  delivery: WebhookDelivery;
  retrying: boolean;
  onRetry: () => void;
}) {
  const state = delivery.delivered_at !== null ? 'delivered' : delivery.parked_at !== null ? 'parked' : 'due';

  return (
    <li
      data-delivery={delivery.id}
      data-state={state}
      className="flex flex-wrap items-center gap-2 rounded border border-line px-2 py-1"
    >
      <code className="rounded bg-well px-1">{delivery.event_type}</code>
      <When at={delivery.occurred_at} />
      {state === 'delivered' && (
        <span className="text-success">
          {t("delivered")} {delivery.last_status !== null && <code>{delivery.last_status}</code>}
        </span>
      )}
      {state === 'due' && (
        <span className="text-subtle">
          {delivery.attempt === 0 ? t("waiting for the next pass") : tx("attempt {n} failed ({why}); next", { n: delivery.attempt, why: <code>{delivery.last_error}</code> })}{' '}
          {delivery.attempt > 0 && <When at={delivery.next_attempt_at} />}
        </span>
      )}
      {state === 'parked' && (
        <>
          <span className="text-danger">
            {tx("parked after {n} attempts ({why})", { n: delivery.attempt, why: <code>{delivery.last_error}</code> })}
          </span>
          <Button type="button" variant="secondary" pending={retrying} onClick={onRetry}>
            {t("Retry")}
          </Button>
        </>
      )}
    </li>
  );
}
