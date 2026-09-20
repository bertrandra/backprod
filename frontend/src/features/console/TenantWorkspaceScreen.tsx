import { Link, useNavigate } from '@tanstack/react-router';

import { useViewState } from '@/app/frame/viewState';
import { useAdminTenantInvoices, useAdminTenantSubscriptions } from '@/queries/admin';
import { useStaffIdentity, useStaffTenant, useStaffTenantMembers } from '@/queries/staff';
import { useConsoleStore } from '@/state/console';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Amount } from '@/ui/Money';
import { PageHeader } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { cn } from '@/utils/cn';

import { AccessMotiveGate, MotiveInEffect } from './AccessMotiveGate';
import { ConversationsTab, JobsTab, PaymentsTab, SalesTab, TaxTab, WorkspaceTab } from './tenantTabs';
import { currentLocale, t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * `console.support.tenant` — one customer, read from the console.
 *
 * **Read-only by construction.** Every tab reads; nothing here writes. What
 * staff may change about a customer — which products it holds, whether it
 * may author offers — stays on the Tenants list, beside the row, so that
 * the two kinds of act are not on one screen where a read-only tab could
 * grow a button. A platform role never edits a membership (#22); it may see
 * them, with a reason, on the record (R14).
 *
 * **The motive gates the whole workspace.** It is asked once when the
 * customer is opened, kept per tenant (`useConsoleStore`), attached to every
 * read of this tenant, and asked again for the next customer. Two of the
 * tabs — subscriptions and invoices — read the platform's own finance
 * listings, which the Directory already opens without a motive; they are
 * behind the gate here anyway, because the gate is about *why this
 * customer*, and the person answered that once for all of it.
 *
 * **The product picker in the bar narrows every tab.** Members on that
 * product, subscriptions to it, invoices for it; "every product it holds"
 * is the default and the honest one.
 */
const TABS = ['overview', 'members', 'subscriptions', 'invoices', 'payments', 'sales', 'tax', 'conversations', 'workspace', 'jobs'] as const;

type Tab = (typeof TABS)[number];

const TAB_LABELS: Record<Tab, string> = {
  overview: 'Overview',
  members: 'Members',
  subscriptions: 'Subscriptions',
  invoices: 'Invoices',
  payments: 'Payments',
  sales: 'Sales',
  tax: 'Tax',
  conversations: 'Conversations',
  workspace: 'Workspace',
  jobs: 'Jobs',
};

function isTab(value: unknown): value is Tab {
  return typeof value === 'string' && (TABS as readonly string[]).includes(value);
}

export function TenantWorkspaceScreen({ tenantId }: { tenantId: string }) {
  const navigate = useNavigate();
  const { tab } = useViewState();
  const current: Tab = isTab(tab) ? tab : 'overview';

  const motive = useConsoleStore((state) => state.motives[tenantId] ?? null);
  const giveMotive = useConsoleStore((state) => state.giveMotive);
  const withdrawMotive = useConsoleStore((state) => state.withdrawMotive);
  const productCode = useConsoleStore((state) => state.productCode);

  const tenant = useStaffTenant(tenantId, motive);
  const me = useStaffIdentity();

  if (motive === null) {
    return <AccessMotiveGate what="this customer" onGiven={(given) => giveMotive(tenantId, given)} />;
  }

  if (tenant.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (tenant.error !== null) {
    return <ErrorSurface error={tenant.error} onRetry={() => void tenant.refetch()} />;
  }

  const held = tenant.data.products;
  const narrowed = held.find((product) => product.code === productCode) ?? null;
  const mayReadFinance = me.data?.permissions.includes('admin.finance.read') ?? false;

  return (
    <div className="max-w-4xl space-y-6" data-testid="tenant-workspace" data-tenant={tenantId}>
      <PageHeader
        title={tenant.data.name}
        description={
          <>
            <code>{tenant.data.slug}</code>
            {t(" · holds ")}
            {held.length === 0 ? t("no product") : held.map((product) => product.name).join(', ')}
            {narrowed !== null && (
              <>
                {' · '}
                <span data-testid="narrowed-to">{t("showing")}{' '}{narrowed.name} {t("only")}</span>
              </>
            )}
          </>
        }
      />

      <MotiveInEffect motive={motive} onChange={() => withdrawMotive(tenantId)} />

      <nav aria-label={t("Customer sections")} className="flex flex-wrap gap-1 border-b border-line">
        {TABS.map((name) => (
          <button
            key={name}
            type="button"
            role="tab"
            aria-selected={current === name}
            data-tab={name}
            onClick={() =>
              void navigate({ to: '/console/tenants/$tenantId', params: { tenantId }, search: { tab: name } })
            }
            className={cn(
              'min-h-[44px] px-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2',
              current === name ? 'border-b-2 border-accent font-medium' : 'text-muted',
            )}
          >
            {TAB_LABELS[name]}
          </button>
        ))}
      </nav>

      {current === 'overview' && <Overview tenant={tenant.data} />}
      {current === 'members' && <Members tenantId={tenantId} productCode={narrowed?.code ?? null} motive={motive} />}
      {current === 'subscriptions' &&
        (mayReadFinance ? (
          <Subscriptions tenantId={tenantId} productId={narrowed?.id ?? null} />
        ) : (
          <NotYours what="subscriptions" />
        ))}
      {current === 'invoices' &&
        (mayReadFinance ? (
          <Invoices tenantId={tenantId} productId={narrowed?.id ?? null} />
        ) : (
          <NotYours what="invoices" />
        ))}
      {current === 'payments' && <PaymentsTab tenantId={tenantId} productCode={narrowed?.code ?? null} motive={motive} />}
      {current === 'sales' && <SalesTab tenantId={tenantId} productCode={narrowed?.code ?? null} motive={motive} />}
      {current === 'tax' && <TaxTab tenantId={tenantId} motive={motive} />}
      {current === 'conversations' &&
        (me.data?.permissions.includes('support.read') ?? false ? (
          <ConversationsTab tenantId={tenantId} />
        ) : (
          <EmptyState title={t("Not yours to read")} description={t("Support threads need support.read, which your role does not hold.")} />
        ))}
      {current === 'workspace' && <WorkspaceTab tenantId={tenantId} productCode={narrowed?.code ?? null} motive={motive} />}
      {current === 'jobs' && <JobsTab tenantId={tenantId} productCode={narrowed?.code ?? null} motive={motive} />}
    </div>
  );
}

/** Hiding is courtesy; the API refuses regardless — but a tab that always 403s is worse than one that says why. */
function NotYours({ what }: { what: string }) {
  return (
    <EmptyState
      title={`Not yours to read`}
      description={t("A customer's {what} are the platform's finance listings, which your role does not hold (admin.finance.read).", { what: what })}
    />
  );
}

function Overview({ tenant }: { tenant: { id: string; name: string; slug: string; may_author_offers: boolean; products: { id: string; code: string; name: string; active: boolean }[] } }) {
  return (
    <section className="space-y-4" data-testid="tab-overview">
      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
        <dt className="text-muted">{t("Products held")}</dt>
        <dd>
          {tenant.products.length === 0 ? (
            'None — nothing here can be used until the platform assigns one.'
          ) : (
            <ul className="flex flex-wrap gap-1">
              {tenant.products.map((product) => (
                <li key={product.id} data-product={product.code} className="rounded bg-well px-1.5 py-0.5 text-xs">
                  {product.name}
                  {!product.active && ' (retired)'}
                </li>
              ))}
            </ul>
          )}
        </dd>
        <dt className="text-muted">{t("Offer authoring")}</dt>
        <dd>{tenant.may_author_offers ? t("Lent the platform's catalogue") : t("Not lent")}</dd>
        <dt className="text-muted">{t("Tenant id")}</dt>
        <dd>
          <code className="select-all text-xs">{tenant.id}</code>
        </dd>
      </dl>

      <p className="text-sm text-muted">
        {tx("Changing what this customer holds or may author is done from the {list}. Everything here is read-only.", {
          list: (
            <Link to="/console/tenants" search={{ selected: tenant.id }} className="underline underline-offset-2">
              {t("Tenants list")}
            </Link>
          ),
        })}
      </p>
    </section>
  );
}

function Members({ tenantId, productCode, motive }: { tenantId: string; productCode: string | null; motive: NonNullable<ReturnType<typeof useConsoleStore.getState>['motives'][string]> }) {
  const members = useStaffTenantMembers(tenantId, productCode, motive);

  if (members.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (members.error !== null) {
    return <ErrorSurface error={members.error} onRetry={() => void members.refetch()} />;
  }

  if (members.data.length === 0) {
    return (
      <EmptyState
        title={t("Nobody")}
        description={productCode === null ? t("No member on any product this customer holds.") : t("No member on {productCode}.", { productCode: productCode })}
      />
    );
  }

  return (
    <ul className="space-y-2" data-testid="tab-members">
      {members.data.map((member) => (
        <li key={member.user_id} data-member={member.user_id} className="rounded-card border border-line bg-surface p-4 text-sm shadow-raise">
          <div className="flex flex-wrap items-baseline gap-2">
            {/* Erased people keep their place with no name (§26). */}
            <span className="font-medium">{member.display_name ?? member.email ?? t("Erased")}</span>
            {member.email !== null && member.display_name !== null && (
              <span className="text-xs text-muted">{member.email}</span>
            )}
            <span className="ml-auto flex flex-wrap gap-1">
              {member.roles.map((role) => (
                <span key={role} className="rounded bg-well px-1.5 py-0.5 text-xs">
                  {role}
                </span>
              ))}
            </span>
          </div>
          <p className="mt-1 text-xs text-muted">{t("on")}{' '}{member.products.join(', ')}</p>
        </li>
      ))}
    </ul>
  );
}

function Subscriptions({ tenantId, productId }: { tenantId: string; productId: string | null }) {
  const list = useAdminTenantSubscriptions(tenantId, productId);

  if (list.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (list.error !== null) {
    return <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />;
  }

  const rows = list.data.subscriptions;

  if (rows.length === 0) {
    return <EmptyState title={t("No subscription")} description={t("This customer has subscribed to nothing here.")} />;
  }

  return (
    <ul className="space-y-2" data-testid="tab-subscriptions">
      {rows.map((subscription) => (
        <li key={subscription.id ?? ''} data-subscription={subscription.id ?? ''} className="rounded-card border border-line bg-surface p-4 text-sm shadow-raise">
          <div className="flex flex-wrap items-baseline gap-2">
            <span className="rounded bg-well px-1.5 py-0.5 text-xs">{subscription.status}</span>
            <span className="text-xs text-subtle">
              {subscription.offer_code} v{subscription.offer_version}
            </span>
            <span className="ml-auto">
              <Amount money={{ minor_units: subscription.price_minor_units ?? 0, currency: subscription.currency ?? 'EUR' }} />
            </span>
          </div>
          {/* Periodicity and commitment, side by side and never added together (#23). */}
          <p className="mt-1 text-xs text-muted">
            {t("period ends")}{' '}
            {subscription.current_period_end === null || subscription.current_period_end === undefined
              ? 'open'
              : new Date(subscription.current_period_end).toLocaleDateString(currentLocale())}{' '}
            {t("· commitment")}{' '}
            {subscription.commitment_ends_at === null || subscription.commitment_ends_at === undefined
              ? t("none recorded")
              : `until ${new Date(subscription.commitment_ends_at).toLocaleDateString(currentLocale())}`}
          </p>
        </li>
      ))}
    </ul>
  );
}

function Invoices({ tenantId, productId }: { tenantId: string; productId: string | null }) {
  const list = useAdminTenantInvoices(tenantId, productId);

  if (list.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (list.error !== null) {
    return <ErrorSurface error={list.error} onRetry={() => void list.refetch()} />;
  }

  const rows = list.data.invoices;

  if (rows.length === 0) {
    return <EmptyState title={t("No invoice")} description={t("Nothing has been invoiced to this customer here.")} />;
  }

  return (
    <ul className="space-y-2" data-testid="tab-invoices">
      {rows.map((invoice) => (
        <li key={invoice.id ?? ''} data-invoice={invoice.id ?? ''} className="rounded-card border border-line bg-surface p-4 text-sm shadow-raise">
          <div className="flex flex-wrap items-baseline gap-2">
            {/* A draft has no legal number, and no placeholder is invented. */}
            <code className="text-xs">{invoice.number ?? t("no number yet")}</code>
            <span className="rounded bg-well px-1.5 py-0.5 text-xs">{invoice.status}</span>
            <span className="ml-auto">
              <Amount money={{ minor_units: invoice.gross_minor_units ?? 0, currency: invoice.currency ?? 'EUR' }} />
            </span>
          </div>
          <p className="mt-1 text-xs text-muted">
            {invoice.issued_at === null || invoice.issued_at === undefined
              ? t("not issued")
              : `issued ${new Date(invoice.issued_at).toLocaleDateString(currentLocale())}`}
            {invoice.paid_at !== null && invoice.paid_at !== undefined && t(" · paid {value}", { value: new Date(invoice.paid_at).toLocaleDateString(currentLocale()) })}
          </p>
        </li>
      ))}
    </ul>
  );
}
