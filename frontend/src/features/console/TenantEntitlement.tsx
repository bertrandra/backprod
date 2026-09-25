import { useState } from 'react';

import {
  useGrantTenantEntitlement,
  useStaffCatalogue,
  useStaffIdentity,
  useTenantEntitlement,
  useWithdrawTenantEntitlement,
  type PlatformProduct,
} from '@/queries/staff';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

/**
 * What the platform gave a tenant on one product it holds, without a sale
 * (docs/tenant-roots.md §2.8) — one panel per held product.
 *
 * Assigning the product (the checklist above this) says the organisation
 * may *see* it; this says what it may *do* with it: which features, with
 * what limits, until when. A pilot, a partner, the operator's own default
 * tenant. Buying stays the ordinary road; this is the other one, and it
 * says so.
 *
 * The form is the whole grant — PUT — so what is ticked is what is granted,
 * and saving twice is the same grant. A plan is a starting point: choosing
 * one fills the boxes from its latest published version, and the boxes are
 * then the truth. Nothing here is optimistic: an entitlement is what a
 * customer may use, and a panel that assumed it had been given would show
 * a capability the API still refuses.
 */
export function TenantEntitlements({ tenantId, held }: { tenantId: string; held: readonly PlatformProduct[] }) {
  if (held.length === 0) {
    return null;
  }

  return (
    <section data-testid="tenant-entitlements" className="space-y-3 border-t border-line pt-3">
      <h3 className="text-sm font-medium">{t("Entitlements given by the platform")}</h3>
      <p className="text-sm text-muted">
        {t("What this tenant may use on each product without having bought it. A subscription on the same product still counts; where both grant a feature, the more generous wins.")}</p>
      {held.map((product) => (
        <ProductEntitlement key={product.id} tenantId={tenantId} product={product} />
      ))}
    </section>
  );
}

type Draft = {
  readonly plan: string;
  readonly ticked: Readonly<Record<string, boolean>>;
  readonly limits: Readonly<Record<string, string>>;
  readonly covers: boolean;
  readonly until: string;
};

function ProductEntitlement({ tenantId, product }: { tenantId: string; product: PlatformProduct }) {
  const me = useStaffIdentity();
  const mayManage = me.data?.permissions.includes('staff.tenants.manage') ?? false;
  const granted = useTenantEntitlement(tenantId, product.id);
  const catalogue = useStaffCatalogue(mayManage ? product.code : null);
  const grant = useGrantTenantEntitlement(tenantId);
  const withdraw = useWithdrawTenantEntitlement(tenantId);
  const [draft, setDraft] = useState<Draft | null>(null);

  const error = grant.error ?? withdraw.error ?? granted.error;
  const current = granted.data ?? null;

  const open = () => {
    const ticked: Record<string, boolean> = {};
    const limits: Record<string, string> = {};

    for (const feature of current?.features ?? []) {
      ticked[feature.code] = true;
      limits[feature.code] = feature.limit === null ? '' : String(feature.limit);
    }

    setDraft({
      plan: '',
      ticked,
      limits,
      covers: current?.covers_people ?? false,
      until: current?.valid_until?.slice(0, 10) ?? '',
    });
  };

  return (
    <div
      data-testid={`entitlement-${product.code}`}
      data-granted={current === null ? 'false' : 'true'}
      className="space-y-2 rounded-card border border-line bg-surface p-3"
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm font-medium">
          {product.name} <code className="text-xs text-subtle">{product.code}</code>
        </p>
        {mayManage && draft === null && (
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={open}>
              {current === null ? t("Grant") : t("Change")}
            </Button>
            {current !== null && (
              <Button
                type="button"
                variant="danger"
                pending={withdraw.isPending}
                onClick={() => withdraw.mutate(product.id)}
              >
                {t("Withdraw")}</Button>
            )}
          </div>
        )}
      </div>

      {granted.isPending ? (
        <SkeletonRows rows={1} />
      ) : current === null ? (
        <p className="text-xs text-subtle">{t("Nothing given; what this tenant may use here comes from what it bought.")}</p>
      ) : (
        <div className="text-sm">
          <ul className="flex flex-wrap gap-x-3 gap-y-1" data-testid={`granted-${product.code}`}>
            {current.features.map((feature) => (
              <li key={feature.code}>
                {feature.name}
                {feature.kind === 'QUOTA' && (
                  <span className="text-muted"> · {feature.limit === null ? t("unlimited") : feature.limit}</span>
                )}
              </li>
            ))}
          </ul>
          <p className="mt-1 text-xs text-muted">
            <span data-testid={`grant-kind-${product.code}`}>
              {current.covers_people ? t("a trial · people may use it") : t("features only · no workspace")}
            </span>
            {t(" · ")}
            {current.valid_until === null ? t("No end date") : t("Until {value}", { value: current.valid_until.slice(0, 10) })}
            {t(" · given ")}
            {current.granted_at.slice(0, 10)}
            {current.granted_by !== null && (
              <>
                {t(" by ")}
                <code className="select-all">{current.granted_by}</code>
              </>
            )}
          </p>
        </div>
      )}

      {error !== null && <ErrorSurface error={error} />}

      {draft !== null && (
        <form
          className="space-y-3 border-t border-line pt-3"
          data-testid={`grant-form-${product.code}`}
          onSubmit={(event) => {
            event.preventDefault();

            const features = Object.entries(draft.ticked)
              .filter(([, on]) => on)
              .map(([code]) => {
                const typed = draft.limits[code]?.trim() ?? '';

                return { code, limit: typed === '' ? null : Number(typed) };
              });

            grant.mutate(
              {
                productId: product.id,
                plan: draft.plan === '' ? null : draft.plan,
                features,
                covers_people: draft.covers,
                valid_until: draft.until === '' ? null : new Date(`${draft.until}T23:59:59Z`).toISOString(),
              },
              { onSuccess: () => setDraft(null) },
            );
          }}
        >
          {catalogue.isPending ? (
            <SkeletonRows rows={2} />
          ) : catalogue.error !== null ? (
            <ErrorSurface error={catalogue.error} />
          ) : (
            <>
              <Field
                id={`grant-plan-${product.code}`}
                label={t("Start from a plan")}
                hint={t("Fills the features below from the plan's latest published offer; what is ticked afterwards is what is granted.")}
              >
                <select
                  id={`grant-plan-${product.code}`}
                  className={inputClass(false)}
                  value={draft.plan}
                  onChange={(event) => setDraft({ ...draft, plan: event.target.value })}
                >
                  <option value="">{t("No plan — pick features")}</option>
                  {catalogue.data.plans.map((plan) => (
                    <option key={plan.id} value={plan.code}>
                      {plan.name}
                    </option>
                  ))}
                </select>
              </Field>

              <fieldset className="space-y-1">
                <legend className="text-sm font-medium">{t("Features")}</legend>
                {catalogue.data.features.map((feature) => (
                  <div key={feature.id} className="flex flex-wrap items-center gap-2 text-sm">
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        data-feature={feature.code}
                        checked={draft.ticked[feature.code] ?? false}
                        onChange={(event) =>
                          setDraft({ ...draft, ticked: { ...draft.ticked, [feature.code]: event.target.checked } })
                        }
                      />
                      {feature.name}
                    </label>
                    {feature.kind === 'QUOTA' && (draft.ticked[feature.code] ?? false) && (
                      <input
                        type="number"
                        min={0}
                        aria-label={`${feature.name} limit`}
                        placeholder="unlimited"
                        className={`${inputClass(false)} w-28`}
                        value={draft.limits[feature.code] ?? ''}
                        onChange={(event) =>
                          setDraft({ ...draft, limits: { ...draft.limits, [feature.code]: event.target.value } })
                        }
                      />
                    )}
                  </div>
                ))}
              </fieldset>

              {/* The one choice on this form that changes what people can
                  *do*, rather than what the organisation holds. Its own
                  block, with the consequence spelled out, because a grant
                  that opens a product to everybody in a company should not
                  be a checkbox somebody ticks past. */}
              <label className="flex items-start gap-2 rounded-card border border-line bg-well p-3 text-sm">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  data-testid={`grant-covers-${product.code}`}
                  checked={draft.covers}
                  onChange={(event) => setDraft({ ...draft, covers: event.target.checked })}
                />
                <span>
                  <span className="font-medium">{t("Let this organisation's people use the product")}</span>
                  <span className="block text-xs text-muted">
                    {t("A trial. Every member reaches the workspace while the grant lasts. Leave this off to add a feature without opening the product — restoring something a customer is missing.")}</span>
                </span>
              </label>

              <Field id={`grant-until-${product.code}`} label={t("Until")} hint={t("Leave empty for no end date.")}>
                <input
                  id={`grant-until-${product.code}`}
                  type="date"
                  className={inputClass(false)}
                  value={draft.until}
                  onChange={(event) => setDraft({ ...draft, until: event.target.value })}
                />
              </Field>

              <div className="flex gap-2">
                <Button type="submit" pending={grant.isPending}>
                  {t("Save grant")}</Button>
                <Button type="button" variant="secondary" onClick={() => setDraft(null)}>
                  {t("Cancel")}</Button>
              </div>
            </>
          )}
        </form>
      )}
    </div>
  );
}
