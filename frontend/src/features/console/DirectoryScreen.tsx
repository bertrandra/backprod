import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { useViewState } from '@/app/frame/viewState';
import {
  useAdminInvoices,
  useAdminSubscriptions,
  useAdminTenants,
  useAdminUsers,
} from '@/queries/admin';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';
import { currentLocale, t } from '@/i18n';

/**
 * `console.admin.directory` — tenants, users, subscriptions, invoices.
 *
 * Four listings under one screen, and **the tab is in the URL** (ui-spec.md
 * §4.3) so "look at this customer's invoices" is a link rather than a set of
 * instructions.
 *
 * **An erased person still has a row here, and that is the design.** `erased_at`
 * set means anonymised: the identity is gone, the record is kept. A directory
 * that dropped the row would break every foreign key pointing at it and would
 * turn "this person asked to be forgotten" into "this person never existed" —
 * which is a different and false claim. The row is rendered as erased rather
 * than as a user with missing fields.
 *
 * **Search will never find them again**, and the screen says so: the contract is
 * blunt that *"an erased person matches neither, having neither"* a name nor an
 * email. Somebody searching for a name they remember and finding nothing should
 * know why.
 */
const TABS = ['tenants', 'users', 'subscriptions', 'invoices'] as const;
type Tab = (typeof TABS)[number];

function isTab(value: string | undefined): value is Tab {
  return TABS.includes((value ?? '') as Tab);
}

export function DirectoryScreen() {
  const { tab } = useViewState();
  const navigate = useNavigate();
  const [filter, setFilter] = useState('');

  const current: Tab = isTab(tab) ? tab : 'tenants';

  const choose = (next: Tab) => {
    setFilter('');
    void navigate({ to: '/console/directory', search: { tab: next } });
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("Directory")}
        description={t("Every tenant, person, subscription and invoice on the platform. Counts are counted, not inferred from a short page.")}
      />

      <nav className="flex flex-wrap gap-1 border-b border-line">
        {TABS.map((name) => (
          <button
            key={name}
            type="button"
            data-tab={name}
            aria-current={current === name ? 'page' : undefined}
            onClick={() => choose(name)}
            className={`min-h-[44px] rounded-t px-3 py-2 text-sm capitalize focus-visible:outline-2 focus-visible:outline-offset-2 ${
              current === name
                ? 'border-b-2 border-accent font-medium text-accent-strong'
                : 'text-muted'
            }`}
          >
            {name}
          </button>
        ))}
      </nav>

      <div className="max-w-sm">
        <Field
          id="filter"
          label={current === 'tenants' || current === 'users' ? t("Search") : t("Status")}
          hint={
            current === 'users'
              ? t("By email or display name. An erased person has neither, so no search will find them.")
              : current === 'tenants'
                ? t("By name or slug. Your own % and _ are literal here, not wildcards.")
                : t("Exactly as the API spells it.")
          }
        >
          <input
            id="filter"
            className={inputClass()}
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
          />
        </Field>
      </div>

      {current === 'tenants' && <Tenants search={filter} />}
      {current === 'users' && <Users search={filter} />}
      {current === 'subscriptions' && <Subscriptions status={filter} />}
      {current === 'invoices' && <Invoices status={filter} />}
    </div>
  );
}

function Count({ shown, total }: { shown: number; total: number }) {
  return (
    <p data-testid="directory-count" className="text-xs text-subtle">
      {t("Showing")}{' '}{shown} {t("of")}{' '}{total}.
    </p>
  );
}

function Tenants({ search }: { search: string }) {
  const list = useAdminTenants(search);

  if (list.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (list.error !== null) {
    return <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />;
  }

  const rows = list.data.tenants;

  if (rows.length === 0) {
    return <EmptyState title={t("No tenants")} description={t("Nothing matches that search.")} />;
  }

  return (
    <div className="space-y-3">
      <Count shown={rows.length} total={list.data.total} />
      <ul className="space-y-2">
        {rows.map((tenant) => (
          <li
            key={tenant.id ?? ''}
            data-directory-row={tenant.id ?? ''}
            className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
          >
            <div className="flex flex-wrap items-baseline gap-2">
              <span className="font-medium">{tenant.name}</span>
              <span className="text-xs text-subtle">{tenant.slug}</span>
            </div>
            <p className="mt-1 text-xs text-muted">
              {tenant.members ?? 0} {t("members ·")}{' '}{tenant.active_subscriptions ?? 0} {t("active subscriptions ·")}{' '}{tenant.unpaid_invoices ?? 0} {t("unpaid invoices")}</p>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Users({ search }: { search: string }) {
  const list = useAdminUsers(search);

  if (list.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (list.error !== null) {
    return <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />;
  }

  const rows = list.data.users;

  if (rows.length === 0) {
    return (
      <EmptyState
        title={t("No people")}
        description={t("Nothing matches. An erased person matches no search, having neither a name nor an email.")}
      />
    );
  }

  return (
    <div className="space-y-3">
      <Count shown={rows.length} total={list.data.total} />
      <ul className="space-y-2">
        {rows.map((user) => {
          const erased = user.erased_at !== null && user.erased_at !== undefined;

          return (
            <li
              key={user.id ?? ''}
              data-directory-row={user.id ?? ''}
              data-erased={String(erased)}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
            >
              <div className="flex flex-wrap items-baseline gap-2">
                {/* The row survives; the identity does not. Rendered as erased
                    rather than as a person with missing fields. */}
                {erased ? (
                  <span data-testid="erased-identity" className="italic text-subtle">
                    {t("erased — this person asked to be forgotten")}</span>
                ) : (
                  <>
                    <span className="font-medium">{user.display_name ?? t("no name")}</span>
                    <span className="text-xs text-subtle">{user.email ?? t("no email")}</span>
                  </>
                )}

                <span className="ml-auto text-xs text-subtle">
                  {t((user.tenants ?? 0) === 1 ? "{count} organisation" : "{count} organisations", { count: user.tenants ?? 0 })}
                </span>
              </div>

              <p className="mt-1 text-xs text-muted">
                <code className="select-all">{user.id}</code>
                {erased && user.erased_at !== null && user.erased_at !== undefined && (
                  <> {t("· erased")}{' '}{new Date(user.erased_at).toLocaleDateString(currentLocale())}</>
                )}
              </p>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function Subscriptions({ status }: { status: string }) {
  const list = useAdminSubscriptions(status);

  if (list.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (list.error !== null) {
    return <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />;
  }

  const rows = list.data.subscriptions;

  if (rows.length === 0) {
    return <EmptyState title={t("No subscriptions")} description={t("Nothing matches that status.")} />;
  }

  return (
    <div className="space-y-3">
      <Count shown={rows.length} total={list.data.total} />
      <ul className="space-y-2">
        {rows.map((subscription) => (
          <li
            key={subscription.id ?? ''}
            data-directory-row={subscription.id ?? ''}
            className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
          >
            <div className="flex flex-wrap items-baseline gap-2">
              <span className="font-medium">{subscription.tenant_name}</span>
              {/* Who holds it (2026-09-26). Since ADR-055 every subscription
                  the tenant surface sells is a seat held by one person, and
                  this list named only the organisation — so three seats in
                  one tenant read as three identical rows and the operator
                  could not tell whose was whose. */}
              <Holder
                kind={subscription.subscriber_kind}
                name={subscription.holder_name}
                email={subscription.holder_email}
              />
              <span className="rounded bg-well px-1.5 py-0.5 text-xs">
                {subscription.status}
              </span>
              <span className="text-xs text-subtle">
                {subscription.product_code} · {subscription.offer_code} v{subscription.offer_version}
              </span>
              <span className="ml-auto">
                <Amount
                  money={{
                    minor_units: subscription.price_minor_units ?? 0,
                    currency: subscription.currency ?? 'EUR',
                  }}
                />
              </span>
            </div>

            {/* Periodicity and commitment, side by side and never added
                together (non-negotiable #23). */}
            <p className="mt-1 text-xs text-muted">
              {t("period ends")}{' '}
              {subscription.current_period_end === null ||
              subscription.current_period_end === undefined
                ? 'open'
                : new Date(subscription.current_period_end).toLocaleDateString(currentLocale())}{' '}
              {t("· commitment")}{' '}
              {subscription.commitment_ends_at === null ||
              subscription.commitment_ends_at === undefined
                ? t("none recorded")
                : `until ${new Date(subscription.commitment_ends_at).toLocaleDateString(currentLocale())}`}
              {subscription.cancel_at_period_end === true && ' · ends at the period boundary'}
            </p>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Invoices({ status }: { status: string }) {
  const list = useAdminInvoices(status);

  if (list.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (list.error !== null) {
    return <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />;
  }

  const rows = list.data.invoices;

  if (rows.length === 0) {
    return <EmptyState title={t("No invoices")} description={t("Nothing matches that status.")} />;
  }

  return (
    <div className="space-y-3">
      <Count shown={rows.length} total={list.data.total} />
      <ul className="space-y-2">
        {rows.map((invoice) => (
          <li
            key={invoice.id ?? ''}
            data-directory-row={invoice.id ?? ''}
            className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
          >
            <div className="flex flex-wrap items-baseline gap-2">
              {/* A draft has no legal number, and no placeholder is invented. */}
              <code data-testid="invoice-number" className="text-xs">
                {invoice.number ?? t("no number yet")}
              </code>
              <span className="rounded bg-well px-1.5 py-0.5 text-xs">
                {invoice.status}
              </span>
              <span className="text-xs text-subtle">{invoice.tenant_name}</span>
              {/* Who the document names, from the snapshots it keeps (§25).
                  For a seat these are not the tenant: Acme sold it, one
                  person bought it — which is the fact this list could not
                  show until today. */}
              <Parties
                supplier={invoice.supplier_name}
                customer={invoice.customer_name}
                issuedByTenant={invoice.issuer_tenant_id !== null && invoice.issuer_tenant_id !== undefined}
              />
              <span className="text-xs text-subtle">{invoice.product_code}</span>
              <span className="ml-auto font-medium">
                <Amount
                  money={{
                    minor_units: invoice.gross_minor_units ?? 0,
                    currency: invoice.currency ?? 'EUR',
                  }}
                />
              </span>
            </div>

            <p className="mt-1 text-xs text-muted">
              {t("net")}{' '}
              <Amount
                money={{
                  minor_units: invoice.net_minor_units ?? 0,
                  currency: invoice.currency ?? 'EUR',
                }}
              />{' '}
              {t("· VAT")}{' '}
              <Amount
                money={{
                  minor_units: invoice.vat_minor_units ?? 0,
                  currency: invoice.currency ?? 'EUR',
                }}
              />
              {invoice.issued_at !== null &&
                invoice.issued_at !== undefined &&
                t(" · issued {value}", { value: new Date(invoice.issued_at).toLocaleDateString(currentLocale()) })}
            </p>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * Who holds a subscription (2026-09-26).
 *
 * A seat names a person; the organisation's own subscription names nobody,
 * and says so rather than borrowing the tenant's name — naming Acme as the
 * holder of Acme's own subscription would read as a person called Acme.
 */
function Holder({
  kind,
  name,
  email,
}: {
  kind: string | undefined;
  name: string | null | undefined;
  email: string | null | undefined;
}) {
  if (kind !== 'USER') {
    return (
      <span data-holder="tenant" className="text-xs text-subtle">
        {t("the organisation")}</span>
    );
  }

  const who = name ?? email ?? null;

  return (
    <span data-holder={who === null ? 'unrecorded' : 'person'} className="text-xs">
      {/* An arrow, because the direction is the information: the
          organisation sold this seat to that person. */}
      <span className="text-subtle">{'→ '}</span>
      {who ?? t("nobody recorded")}
    </span>
  );
}

/**
 * The supplier and the customer a document carries, when they differ from the
 * organisation it belongs to.
 *
 * Shown only when the invoice was raised by a tenant: the platform's own
 * invoices are addressed to the organisation, whose name is already on the
 * row, and repeating it would be noise on every line.
 */
function Parties({
  supplier,
  customer,
  issuedByTenant,
}: {
  supplier: string | null | undefined;
  customer: string | null | undefined;
  issuedByTenant: boolean;
}) {
  if (!issuedByTenant || customer === null || customer === undefined) {
    return null;
  }

  return (
    <span data-parties="tenant-issued" className="text-xs">
      <span className="text-subtle">{supplier ?? t("the organisation")}{' → '}</span>
      {customer}
    </span>
  );
}