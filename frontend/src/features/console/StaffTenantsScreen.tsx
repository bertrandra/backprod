import { useNavigate } from '@tanstack/react-router';

import { useViewState } from '@/app/frame/viewState';
import { useState } from 'react';

import {
  useSetTenantOfferAuthoring,
  useStaffIdentity,
  useStaffTenant,
  useStaffTenants,
  type AccessMotive,
} from '@/queries/staff';

import { AccessMotiveGate, MotiveInEffect } from './AccessMotiveGate';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `console.support.tenants` — a customer as support sees them.
 *
 * **Opening one is a recorded act.** The read writes a `StaffAccessEntry`
 * naming who looked, at what, and under which permission, and the screen says
 * so *before* the click rather than in a footnote afterwards. Non-negotiable
 * #21 makes the trace mandatory; making it visible is what stops it being a
 * surveillance mechanism the surveilled party alone knows about.
 *
 * **The tenant is named in the path** — the one place this platform allows it.
 * ADR-015 forbids a client naming a tenant everywhere else, and this is not the
 * exception it looks like: the path names the tenant, the *platform role*
 * authorises the read, and the read is recorded either way.
 *
 * What is *read* here is deliberately thin: name, slug and id. A support agent
 * needs to confirm they have the right company, not to read its data. Anything
 * more would be a boundary crossing the contract has not authorised, and there
 * is no endpoint for it.
 *
 * The one thing that can be *changed* is whether the platform lends this
 * customer its catalogue (`may_author_offers`). That is not the customer's data
 * — it is a statement about what the platform permits them to do — which is why
 * it lives on this screen and behind `staff.tenants.manage` rather than behind
 * `staff.tenants.read`. Everybody who can open a tenant can see the answer;
 * only an administrator can change it.
 */
export function StaffTenantsScreen() {
  const { selected } = useViewState();
  const navigate = useNavigate();
  const tenants = useStaffTenants();

  const select = (id: string) => {
    void navigate({ to: '/console/tenants', search: { selected: id } });
  };

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Tenants</h1>
        <p className="text-sm text-muted">
          Opening a tenant records an entry against your name, with the permission you used. That
          record is the reason this access is allowed at all.
        </p>
      </header>

      <div className="grid gap-8 lg:grid-cols-[22rem_1fr]">
        <section className="space-y-2">
          {tenants.isPending ? (
            <SkeletonRows rows={6} />
          ) : tenants.error !== null ? (
            <ErrorSurface error={tenants.error} onRetry={() => void tenants.refetch()} />
          ) : tenants.data.tenants.length === 0 ? (
            <EmptyState title="No tenants" description="Nothing is registered on this platform." />
          ) : (
            <>
              <p data-testid="tenant-count" className="text-xs text-subtle">
                {/* Counted, not inferred from a short page. */}
                Showing {tenants.data.tenants.length} of {tenants.data.total}.
              </p>

              <ul className="space-y-2">
                {tenants.data.tenants.map((tenant) => (
                  <li key={tenant.id}>
                    <button
                      type="button"
                      data-tenant={tenant.id}
                      aria-current={selected === tenant.id ? 'true' : undefined}
                      onClick={() => select(tenant.id)}
                      className={`w-full rounded border p-3 text-left text-sm focus-visible:outline-2 focus-visible:outline-offset-2 ${
                        selected === tenant.id
                          ? 'border-ink'
                          : 'border-line hover:bg-canvas dark:hover:bg-inverse'
                      }`}
                    >
                      <span className="block font-medium">{tenant.name}</span>
                      <span className="block text-xs text-muted">
                        {tenant.slug}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            </>
          )}
        </section>

        <section className="min-w-0">
          {selected === undefined ? (
            <EmptyState
              title="No tenant open"
              description="Choosing one performs a recorded read across the tenant boundary."
            />
          ) : (
            <TenantDetail key={selected} tenantId={selected} />
          )}
        </section>
      </div>
    </div>
  );
}

/**
 * A customer, once somebody has said why (R14).
 *
 * The motive is asked for *before* the read, and the read is disabled until it
 * arrives — so this screen never fetches a tenant and then explains that it
 * should not have. Changing the selected tenant resets it: a reason given for
 * opening one customer is not a reason for opening the next.
 */
function TenantDetail({ tenantId }: { tenantId: string }) {
  const [motive, setMotive] = useState<AccessMotive | null>(null);
  const tenant = useStaffTenant(tenantId, motive);

  if (motive === null) {
    return <AccessMotiveGate what="this customer" onGiven={setMotive} />;
  }

  if (tenant.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (tenant.error !== null) {
    return <ErrorSurface error={tenant.error} onRetry={() => void tenant.refetch()} />;
  }

  return (
    <div
      data-testid="tenant-detail"
      className="space-y-4 rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <h2 className="text-xl font-semibold">{tenant.data.name}</h2>

      <MotiveInEffect motive={motive} onChange={() => setMotive(null)} />

      <dl className="grid gap-3 sm:grid-cols-2">
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">Slug</dt>
          <dd>{tenant.data.slug}</dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">Identifier</dt>
          <dd>
            <code className="select-all text-xs">{tenant.data.id}</code>
          </dd>
        </div>
      </dl>

      <OfferAuthoring tenantId={tenantId} mayAuthor={tenant.data.may_author_offers} />

      <p data-testid="read-recorded" className="border-t border-line pt-3 text-xs text-muted">
        This read has been recorded under <code>staff.tenants.read</code>, with the reason you gave.
        It appears in the access log with your user id against it.
      </p>
    </div>
  );
}

/**
 * Whether this customer may author offers of their own.
 *
 * Off for every tenant that has ever been created, and it takes an
 * administrator to turn it on — that is the whole point. The catalogue is the
 * platform's: a tenant with `catalog.manage` in a role still cannot reach it
 * until somebody here decides they may, because the permission is not resolved
 * at all while the flag is false. So this control is not a convenience for
 * hiding buttons; it is where the authority comes from.
 *
 * The state is shown to anybody who can open the tenant and the control only to
 * somebody holding `staff.tenants.manage`. A support agent asked "can they edit
 * their prices?" should be able to answer it without being able to change the
 * answer.
 */
function OfferAuthoring({ tenantId, mayAuthor }: { tenantId: string; mayAuthor: boolean }) {
  const me = useStaffIdentity();
  const set = useSetTenantOfferAuthoring(tenantId);

  const mayManage = me.data?.permissions.includes('staff.tenants.manage') ?? false;

  return (
    <section
      data-testid="offer-authoring"
      data-may-author={mayAuthor ? 'true' : 'false'}
      className="space-y-2 border-t border-line pt-3"
    >
      <h3 className="text-sm font-medium">Offer authoring</h3>

      <p className="text-sm text-muted">
        {mayAuthor
          ? 'This tenant may create and publish offers of their own. Members holding a role with catalog.manage can reach the catalogue.'
          : 'This tenant uses the platform catalogue and cannot change it. Members see offers; nobody can author one, whatever their tenant role says.'}
      </p>

      {set.error !== null && <ErrorSurface error={set.error} />}

      {mayManage ? (
        <Button
          type="button"
          variant={mayAuthor ? 'danger' : 'primary'}
          pending={set.isPending}
          onClick={() => set.mutate(!mayAuthor)}
        >
          {mayAuthor ? 'Withdraw offer authoring' : 'Allow offer authoring'}
        </Button>
      ) : (
        <p data-testid="offer-authoring-readonly" className="text-xs text-subtle">
          Changing this needs <code>staff.tenants.manage</code>, which an administrator holds.
        </p>
      )}
    </section>
  );
}
