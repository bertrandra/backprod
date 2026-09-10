import { useNavigate } from '@tanstack/react-router';

import { useViewState } from '@/app/frame/viewState';
import { useState } from 'react';

import { useStaffTenant, useStaffTenants, type AccessMotive } from '@/queries/staff';

import { AccessMotiveGate, MotiveInEffect } from './AccessMotiveGate';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
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
 * What is shown is deliberately thin: name, slug and id. A support agent needs
 * to confirm they have the right company, not to read its data. Anything more
 * would be a boundary crossing the contract has not authorised, and there is no
 * endpoint for it.
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
        <h1 className="text-lg font-semibold">Tenants</h1>
        <p className="text-sm text-neutral-600 dark:text-neutral-400">
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
              <p data-testid="tenant-count" className="text-xs text-neutral-500">
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
                          ? 'border-neutral-900 dark:border-neutral-100'
                          : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900'
                      }`}
                    >
                      <span className="block font-medium">{tenant.name}</span>
                      <span className="block text-xs text-neutral-600 dark:text-neutral-400">
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
      className="space-y-4 rounded border border-neutral-200 p-4 text-sm dark:border-neutral-800"
    >
      <h2 className="text-base font-semibold">{tenant.data.name}</h2>

      <MotiveInEffect motive={motive} onChange={() => setMotive(null)} />

      <dl className="grid gap-3 sm:grid-cols-2">
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">Slug</dt>
          <dd>{tenant.data.slug}</dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-neutral-500">Identifier</dt>
          <dd>
            <code className="select-all text-xs">{tenant.data.id}</code>
          </dd>
        </div>
      </dl>

      <p data-testid="read-recorded" className="border-t border-neutral-200 pt-3 text-xs text-neutral-600 dark:border-neutral-800 dark:text-neutral-400">
        This read has been recorded under <code>staff.tenants.read</code>, with the reason you gave.
        It appears in the access log with your user id against it.
      </p>
    </div>
  );
}
