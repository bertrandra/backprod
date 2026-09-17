import { Link, useNavigate } from '@tanstack/react-router';

import { useViewState } from '@/app/frame/viewState';
import { useDeferredValue, useState } from 'react';

import { can } from '@/app/access/access';
import { useAdminUsers } from '@/queries/admin';
import {
  staffAccess,
  useAssignTenantProduct,
  useCreateTenant,
  usePlatformProducts,
  useSetTenantOfferAuthoring,
  useStaffIdentity,
  useStaffTenant,
  useStaffTenants,
  useUnassignTenantProduct,
  useUpdateTenant,
  type AccessMotive,
  type PlatformProduct,
} from '@/queries/staff';
import { Field, inputClass } from '@/ui/Field';
import { SearchPicker } from '@/ui/pickers/SearchPicker';
import { type Person } from '@/ui/pickers/Select';

import { AccessMotiveGate, MotiveInEffect } from './AccessMotiveGate';
import { TenantEntitlements } from './TenantEntitlement';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';

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
  const me = useStaffIdentity();

  const select = (id: string) => {
    void navigate({ to: '/console/tenants', search: { selected: id } });
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title={'Tenants'}
        description={'Opening a tenant records an entry against your name, with the permission you used. That record is the reason this access is allowed at all.'}
      />

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
                      <span className="block font-medium">
                        {tenant.name}
                        {tenant.is_default && (
                          <span data-testid="default-tenant" className="ml-2 rounded bg-well px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-subtle">
                            bare host
                          </span>
                        )}
                      </span>
                      <span className="block text-xs text-muted">
                        {tenant.is_default ? '/' : `/${tenant.slug}/`}
                      </span>
                    </button>
                    {/* Reading, as opposed to the administering the panel
                        beside does: the workspace, read-only, per product. */}
                    <Link
                      to="/console/tenants/$tenantId"
                      params={{ tenantId: tenant.id }}
                      data-testid="browse-tenant"
                      className="mt-1 inline-block min-h-[44px] px-1 text-xs underline underline-offset-2"
                    >
                      Browse {tenant.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </>
          )}

          {can(staffAccess(me.data), 'staff.tenants.manage') && (
            <NewTenant onCreated={select} />
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
          <dt className="text-xs uppercase tracking-wide text-subtle">Address</dt>
          <dd>
            <code className="text-xs">{tenant.data.is_default ? '/' : `/${tenant.data.slug}/`}</code>
            {tenant.data.is_default && <span className="ml-2 text-xs text-muted">the bare host</span>}
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">Identifier</dt>
          <dd>
            <code className="select-all text-xs">{tenant.data.id}</code>
          </dd>
        </div>
      </dl>

      <TenantAddress tenantId={tenantId} name={tenant.data.name} slug={tenant.data.slug} isDefault={tenant.data.is_default} />

      <TenantProducts tenantId={tenantId} held={tenant.data.products} />

      <TenantEntitlements tenantId={tenantId} held={tenant.data.products} />

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
/**
 * Which products this tenant holds (ADR-047), and the platform deciding it.
 *
 * One checkbox per product: every active product the platform hosts, plus any
 * retired one the tenant still holds — a retired product is not offered, but
 * a tenant that has one is shown it, because that is a fact about them. Ticking
 * assigns; un-ticking withdraws; the server may refuse either (a retired
 * product, a subscription still owed service) and the box does not move until
 * it has answered — nothing here is optimistic, for the reason the toggle
 * below is not.
 *
 * Read-only without `staff.tenants.manage`, exactly as offer authoring is:
 * support may answer "which products do they have?" without being able to
 * change the answer.
 */
function TenantProducts({ tenantId, held }: { tenantId: string; held: readonly PlatformProduct[] }) {
  const me = useStaffIdentity();
  const mayManage = me.data?.permissions.includes('staff.tenants.manage') ?? false;
  const platform = usePlatformProducts(mayManage);
  const assign = useAssignTenantProduct(tenantId);
  const unassign = useUnassignTenantProduct(tenantId);

  const heldIds = new Set(held.map((product) => product.id));
  // Offered: every active product; shown: those plus what is held already.
  const rows: readonly PlatformProduct[] = mayManage
    ? [
        ...(platform.data ?? []).filter((product) => product.active || heldIds.has(product.id)),
        ...held.filter((product) => !(platform.data ?? []).some((known) => known.id === product.id)),
      ]
    : held;

  const pending = assign.isPending || unassign.isPending;
  const error = assign.error ?? unassign.error;

  return (
    <section data-testid="tenant-products" className="space-y-2 border-t border-line pt-3">
      <h3 className="text-sm font-medium">Products</h3>

      <p className="text-sm text-muted">
        {held.length === 0
          ? 'This tenant holds no product: nobody in it can reach anything until one is assigned.'
          : 'Every member of this tenant is a member of each product it holds, with the roles they hold in the organisation.'}
      </p>

      {error !== null && <ErrorSurface error={error} />}

      {me.isPending || (mayManage && platform.isPending) ? (
        // Until the identity has answered, nobody knows whether these are
        // boxes to tick or a list to read — so neither is shown yet.
        <SkeletonRows rows={2} />
      ) : rows.length === 0 ? (
        <p className="text-xs text-subtle">No product to show.</p>
      ) : (
        <ul className="space-y-1">
          {rows.map((product) => (
            <li key={product.id}>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  data-product={product.code}
                  checked={heldIds.has(product.id)}
                  disabled={!mayManage || pending || (!product.active && !heldIds.has(product.id))}
                  onChange={(event) =>
                    event.target.checked ? assign.mutate(product.id) : unassign.mutate(product.id)
                  }
                />
                <span>
                  {product.name}
                  {!product.active && <span className="ml-1 text-xs text-subtle">(retired)</span>}
                </span>
                <code className="text-xs text-subtle">{product.code}</code>
              </label>
            </li>
          ))}
        </ul>
      )}

      {!mayManage && (
        <p data-testid="tenant-products-readonly" className="text-xs text-subtle">
          Changing this needs <code>staff.tenants.manage</code>, which an administrator holds.
        </p>
      )}
    </section>
  );
}

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

/**
 * Making an organisation (2026-09-17): the only way one comes to exist since
 * sign-up stopped making them. Name, the slug that becomes its address,
 * the products it holds from the start, and — found in the directory — its
 * first administrator, an existing user. The address is shown as it will be
 * typed, so a slug that would shadow the console is refused by the server
 * with the reason beside the field.
 */
function NewTenant({ onCreated }: { onCreated: (id: string) => void }) {
  const me = useStaffIdentity();
  const platformProducts = usePlatformProducts(true);
  const create = useCreateTenant();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [products, setProducts] = useState<readonly string[]>([]);
  const [admin, setAdmin] = useState<Person | null>(null);
  const [query, setQuery] = useState('');
  const search = useDeferredValue(query.trim());
  const maySearch = can(staffAccess(me.data), 'admin.directory.read');
  const found = useAdminUsers(search, 8, 0, maySearch && search !== '');

  if (!open) {
    return (
      <Button type="button" variant="secondary" onClick={() => setOpen(true)} data-testid="new-tenant">
        New organisation
      </Button>
    );
  }

  const active = (platformProducts.data ?? []).filter((product) => product.active);

  return (
    <form
      data-testid="new-tenant-form"
      className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-raise"
      onSubmit={(event) => {
        event.preventDefault();
        create.mutate(
          { name: name.trim(), slug: slug.trim(), products, admin_user_id: admin?.id ?? null },
          {
            onSuccess: (tenant) => {
              setOpen(false);
              setName('');
              setSlug('');
              setProducts([]);
              setAdmin(null);
              setQuery('');
              onCreated(tenant.id);
            },
          },
        );
      }}
    >
      <h3 className="text-sm font-semibold">New organisation</h3>

      <Field id="tenant-name" label="Name">
        <input id="tenant-name" className={inputClass()} value={name} onChange={(event) => setName(event.target.value)} />
      </Field>

      <Field id="tenant-slug" label="Address" hint={`Its root: ${window.location.origin}/${slug === '' ? '…' : slug}/ — lowercase letters, digits and hyphens.`}>
        <input
          id="tenant-slug"
          className={inputClass()}
          value={slug}
          onChange={(event) => setSlug(event.target.value.toLowerCase())}
          placeholder="acme"
        />
      </Field>

      <fieldset className="space-y-1">
        <legend className="text-sm font-medium">Products it holds</legend>
        {active.map((product) => (
          <label key={product.id} className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              className="size-4"
              data-product={product.code}
              checked={products.includes(product.code)}
              onChange={(event) =>
                setProducts(event.target.checked ? [...products, product.code] : products.filter((code) => code !== product.code))
              }
            />
            {product.name}
          </label>
        ))}
      </fieldset>

      {maySearch && (
        <Field id="tenant-admin" label="First administrator" hint="An existing account, found in the directory; leave empty to appoint one later.">
          <SearchPicker
            id="tenant-admin"
            query={query}
            onQueryChange={setQuery}
            pending={found.isPending && search !== ''}
            results={(found.data?.users ?? [])
              .filter((user) => user.erased_at === null && typeof user.id === 'string')
              .map((user) => ({ id: String(user.id), name: user.display_name ?? null, email: user.email ?? null }))}
            value={admin}
            onPick={setAdmin}
            placeholder="ada@example.test"
          />
        </Field>
      )}

      {create.error !== null && <ErrorSurface error={create.error} />}

      <div className="flex gap-2">
        <Button type="submit" pending={create.isPending} disabled={name.trim() === '' || slug.trim() === ''}>
          Create
        </Button>
        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

/**
 * Renaming, re-addressing, and giving the bare host to this organisation.
 * A new address is refused by the server once an invoice exists — its
 * links are in the world — and the refusal is shown beside the field.
 */
function TenantAddress({ tenantId, name, slug, isDefault }: { tenantId: string; name: string; slug: string; isDefault: boolean }) {
  const me = useStaffIdentity();
  const update = useUpdateTenant(tenantId);
  const [draftName, setDraftName] = useState(name);
  const [draftSlug, setDraftSlug] = useState(slug);

  if (!can(staffAccess(me.data), 'staff.tenants.manage')) {
    return null;
  }

  const dirty = draftName.trim() !== name || draftSlug.trim() !== slug;

  return (
    <section data-testid="tenant-address" className="space-y-3 border-t border-line pt-4">
      <h3 className="text-sm font-semibold">Name and address</h3>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field id="tenant-rename" label="Name">
          <input id="tenant-rename" className={inputClass()} value={draftName} onChange={(event) => setDraftName(event.target.value)} />
        </Field>
        <Field id="tenant-reslug" label="Address" hint="Kept once an invoice exists: its links are in the world.">
          <input id="tenant-reslug" className={inputClass()} value={draftSlug} onChange={(event) => setDraftSlug(event.target.value.toLowerCase())} />
        </Field>
      </div>

      {update.error !== null && <ErrorSurface error={update.error} />}

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          pending={update.isPending}
          disabled={!dirty}
          onClick={() =>
            update.mutate({
              ...(draftName.trim() !== name ? { name: draftName.trim() } : {}),
              ...(draftSlug.trim() !== slug ? { slug: draftSlug.trim() } : {}),
            })
          }
        >
          Save
        </Button>
        {isDefault ? (
          <span className="self-center text-xs text-muted">This organisation is the bare host.</span>
        ) : (
          <Button type="button" variant="secondary" pending={update.isPending} data-testid="make-default" onClick={() => update.mutate({ is_default: true })}>
            Make it the bare host
          </Button>
        )}
      </div>
    </section>
  );
}
