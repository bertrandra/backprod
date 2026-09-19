import { useLocation, useNavigate } from '@tanstack/react-router';

import { useStaffTenant, useStaffTenants } from '@/queries/staff';
import { useConsoleStore } from '@/state/console';
import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * Region A on the console: which level the screen answers to, and the two
 * pickers that narrow it.
 *
 * The console has two kinds of screen under one menu. Platform screens —
 * products, catalogue, storefront, staff, queue — answer to the platform,
 * and the product switcher is their context. Customer screens answer to one
 * tenant, and until this existed nothing in the bar said which one, or that
 * the switcher was not what they read. So the bar now says the **level**
 * out loud — *Platform*, or *Tenant · Acme Ltd* — and offers what narrows it:
 * a tenant picker that opens that customer's workspace, and, once inside
 * one, a product picker limited to the products *that tenant holds*
 * (ADR-047), with "every product" as the plain default.
 *
 * The tenant is named in the URL, so picking one is a navigation and a link
 * says which customer it opens. The product narrowing is client state
 * (`useConsoleStore`): it is a view preference, not an address.
 */
export function ConsoleContext() {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const tenants = useStaffTenants(100, 0);
  const productCode = useConsoleStore((state) => state.productCode);
  const narrowTo = useConsoleStore((state) => state.narrowTo);

  const tenantId = tenantIdIn(pathname);
  const motive = useConsoleStore((state) => (tenantId === null ? null : (state.motives[tenantId] ?? null)));
  // Named from the read the workspace already made under its motive — the
  // bar never opens a customer on its own account, so an unopened tenant is
  // shown by the list's name, which reveals nothing the list did not.
  const opened = useStaffTenant(tenantId, motive);

  const listed = tenants.data?.tenants.find((tenant) => tenant.id === tenantId) ?? null;
  const tenantName = opened.data?.name ?? listed?.name ?? null;
  const held = opened.data?.products ?? listed?.products ?? [];

  const chooseTenant = (id: string) => {
    if (id === '') {
      void navigate({ to: '/console/tenants' });

      return;
    }

    void navigate({ to: '/console/tenants/$tenantId', params: { tenantId: id } });
  };

  return (
    <>
      <span
        data-testid="console-level"
        data-level={tenantId === null ? 'platform' : t("tenant")}
        className={cn(
          // Hidden on a phone: the picker beside it already reads "Platform —
          // no customer" or the customer's name, and the bar has 390px.
          'hidden shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide sm:inline-block',
          tenantId === null ? 'bg-well text-muted' : 'bg-warning-wash text-warning',
        )}
      >
        {tenantId === null ? t("Platform") : t("Tenant")}
      </span>

      {/* Flexible, basis zero: on a phone the pickers give way rather than
          push the bar sideways; on a desktop they stop at a readable width. */}
      <label className="flex min-w-0 max-w-[12rem] flex-1 basis-[6rem] items-center gap-1 text-xs text-muted">
        <span className="sr-only">{t("Customer")}</span>
        <select
          data-testid="console-tenant"
          aria-label={t("Customer")}
          value={tenantId ?? ''}
          onChange={(event) => chooseTenant(event.target.value)}
          className="w-full min-w-0 truncate rounded-control border border-line bg-surface px-2 py-1 text-xs"
        >
          <option value="">{t("Platform — no customer")}</option>
          {tenants.data?.tenants.map((tenant) => (
            <option key={tenant.id} value={tenant.id}>
              {tenant.name}
            </option>
          ))}
          {tenantId !== null && !tenants.data?.tenants.some((tenant) => tenant.id === tenantId) && (
            <option value={tenantId}>{tenantName ?? tenantId.slice(0, 8)}</option>
          )}
        </select>
      </label>

      {tenantId !== null && (
        <label className="flex min-w-0 max-w-[11rem] flex-1 basis-[5rem] items-center gap-1 text-xs text-muted">
          <span className="sr-only">{t("Product")}</span>
          <select
            data-testid="console-product"
            aria-label={t("Product")}
            value={productCode !== null && held.some((product) => product.code === productCode) ? productCode : ''}
            onChange={(event) => narrowTo(event.target.value === '' ? null : event.target.value)}
            className="w-full min-w-0 truncate rounded-control border border-line bg-surface px-2 py-1 text-xs"
          >
            <option value="">{t("Every product it holds")}</option>
            {held.map((product) => (
              <option key={product.id} value={product.code}>
                {product.name}
              </option>
            ))}
          </select>
        </label>
      )}
    </>
  );
}

/** The tenant a console address names, or null on a platform-level screen. */
export function tenantIdIn(pathname: string): string | null {
  const match = /^\/console\/tenants\/([^/?#]+)/.exec(pathname);

  return match?.[1] ?? null;
}
