import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { useQueryClient } from '@tanstack/react-query';

import { useChooseProduct } from '@/app/frame/ProductSwitcher';
import {
  useCreateProduct,
  usePlatformProducts,
  useDemoPage,
  useResetDemoWorld,
  useSetDemoPage,
  useStaffIdentity,
  useUpdateProduct,
  type DemoWorld,
  type PlatformProduct,
} from '@/queries/staff';
import { useSessionStore } from '@/state/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';

/**
 * `console.admin.products` — the top of the model, and the screen that was
 * missing from it.
 *
 * A product is what every tenant belongs to, every offer is priced for and
 * every membership is scoped by. Until this screen existed, exactly one thing
 * on the platform could create one — `setup.php`, once, at install — and
 * *nothing at all* could list them: `GET /api/v1/products` resolves through
 * membership, and a platform role never grants membership (non-negotiable
 * #22), so an administrator asking "what does this deployment host?" was
 * answered about their own tenant or not at all. The answer was an `INSERT`
 * and a `SELECT` typed against production, which is what ADR-039 removed for
 * staff and had never removed here.
 *
 * **The code is shown large and is never editable.** It is the thing somebody
 * came here to find — what clients send as `X-Product`, what the storefront
 * takes as `?product=`, what `VITE_DEFAULT_PRODUCT` has to match — and it is
 * chosen once, at creation, because an identifier that can change is not an
 * identifier.
 *
 * **Retiring is not deleting, and there is no delete.** A product carries
 * tenants, subscriptions and invoices, and an invoice is a legal document
 * (§25). Switching one off closes every door into it and leaves the history
 * exactly where the law requires it — so the button says "Retire" and the
 * screen says what that does, rather than offering a deletion that would have
 * to refuse.
 */
export function ProductsScreen() {
  const navigate = useNavigate();
  const chooseProduct = useChooseProduct();
  const products = usePlatformProducts();
  const create = useCreateProduct();
  const update = useUpdateProduct();

  // A row's buttons hand somebody to a screen *for that product*: choose it
  // in the switcher — the one product every console screen reads (ADR-047) —
  // then go. Nothing travels in the address.
  const handTo = (
    to: '/console/storefront' | '/console/catalogue' | '/console/invoicing',
    productCode: string,
  ) => {
    chooseProduct(productCode);
    return navigate({ to });
  };

  const [code, setCode] = useState('');
  const [name, setName] = useState('');

  if (products.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (products.error !== null) {
    return <ErrorSurface error={products.error} onRetry={() => void products.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title={'Products'}
        description={<>Everything this platform hosts. A product is what tenants belong to and what offers are priced for — its <strong>code</strong> is what clients send as <code>X-Product</code> and what the public storefront reads from <code>?product=</code>.</>}
      />

      {create.error !== null && <ErrorSurface error={create.error} />}
      {update.error !== null && <ErrorSurface error={update.error} />}

      <section className="space-y-3">
        {products.data.length === 0 ? (
          // Reachable only on a database nothing installed, but said properly
          // rather than left as an empty list somebody reads as a bug.
          <EmptyState
            title="No products"
            description="Nothing is registered. Create one below; the installer normally creates the first."
          />
        ) : (
          <ul className="space-y-2" data-testid="product-list">
            {products.data.map((product) => (
              <ProductRow
                key={product.id}
                product={product}
                pending={update.isPending}
                onRename={(newName) => update.mutate({ productId: product.id, name: newName })}
                onSetActive={(active) => update.mutate({ productId: product.id, active })}
                onStorefront={() =>
                  void handTo('/console/storefront', product.code)
                }
                onCatalogue={() =>
                  void handTo('/console/catalogue', product.code)
                }
                onInvoicing={() =>
                  void handTo('/console/invoicing', product.code)
                }
              />
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-3 border-t border-line pt-4">
        <h2 className="text-xl font-semibold">Add a product</h2>

        <p className="text-sm text-muted">
          The code cannot be changed afterwards. A new product starts with no plans, no offers and
          no tenants — nothing is copied from an existing one.
        </p>

        <form
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault();

            if (code.trim() !== '' && name.trim() !== '') {
              create.mutate(
                { code: code.trim().toLowerCase(), name: name.trim() },
                {
                  onSuccess: () => {
                    setCode('');
                    setName('');
                  },
                },
              );
            }
          }}
        >
          <Field
            id="product-code"
            label="Code"
            hint="Lowercase letters, digits and hyphens. It travels in URLs and headers, so it cannot contain spaces."
          >
            <input
              id="product-code"
              className={inputClass()}
              placeholder="atlas"
              value={code}
              onChange={(event) => setCode(event.target.value)}
            />
          </Field>

          <Field id="product-name" label="Name" hint="What people read. This one can be changed.">
            <input
              id="product-name"
              className={inputClass()}
              placeholder="Atlas"
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
          </Field>

          <Button
            type="submit"
            pending={create.isPending}
            disabled={code.trim() === '' || name.trim() === ''}
          >
            Create product
          </Button>
        </form>
      </section>

      <DemoPagePanel />

      <DemoWorldPanel />
    </div>
  );
}

/**
 * The public demonstration page's switch (2026-09-18).
 *
 * `/demo` shows what the platform hosts — products and offers, every
 * organisation with its people and their roles — to anybody, with no
 * session. That is a membership's answer everywhere else, so it is off
 * until whoever holds `staff.demo.publish` turns it on here, and the copy
 * says what is being published rather than calling it a feature flag.
 */
function DemoPagePanel() {
  const me = useStaffIdentity();
  const mayPublish = me.data?.permissions.includes('staff.demo.publish') ?? false;
  const page = useDemoPage(mayPublish);
  const set = useSetDemoPage();

  if (!mayPublish) {
    return null;
  }

  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="demo-page">
      <h2 className="text-xl font-semibold">Demonstration page</h2>

      <p className="text-sm text-muted">
        <a href="/demo" className="underline underline-offset-2" target="_blank" rel="noreferrer">
          <code>/demo</code>
        </a>{' '}
        shows anybody, with no sign-in, every product and its offers on sale, and every
        organisation with its address, its products, its subscriptions and its people with their
        roles. Everywhere else that is a member&rsquo;s answer; switch it on only on a deployment
        that exists to be shown.
      </p>

      {page.error !== null && <ErrorSurface error={page.error} onRetry={() => void page.refetch()} />}
      {set.error !== null && <ErrorSurface error={set.error} />}

      {page.isPending ? (
        <SkeletonRows rows={1} />
      ) : (
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            data-testid="demo-page-switch"
            checked={page.data ?? false}
            disabled={set.isPending}
            onChange={(event) => set.mutate(event.target.checked)}
          />
          <span>
            {(page.data ?? false) ? 'Shown — /demo answers to anybody' : 'Hidden — /demo is a 404'}
          </span>
        </label>
      )}
    </section>
  );
}

/**
 * The demonstration world, put back the way it started.
 *
 * Offered only to whoever holds `staff.demo.reset` — PLATFORM_ADMIN alone —
 * and behind a second, explicit step, because it is the widest destructive
 * act on this platform: every product, organisation, invoice and person,
 * the reader included. The server refuses it anyway while a product that is
 * not the demonstration's exists; the copy says so rather than hiding the
 * button, so an administrator of a real deployment learns why it is not
 * for them.
 *
 * After it succeeds the reader's token names a row that no longer exists.
 * Nothing is refetched: the answer is shown — who to sign in as — and the
 * one button left signs out, which is the honest state.
 */
function DemoWorldPanel() {
  const me = useStaffIdentity();
  const reset = useResetDemoWorld();
  const queryClient = useQueryClient();
  const forget = useSessionStore((state) => state.forget);
  const [armed, setArmed] = useState(false);

  if (!(me.data?.permissions.includes('staff.demo.reset') ?? false)) {
    return null;
  }

  if (reset.data !== undefined) {
    return <DemoWorldReset world={reset.data} onSignOut={() => { forget(); queryClient.clear(); }} />;
  }

  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="demo-world">
      <h2 className="text-xl font-semibold">Demonstration world</h2>

      <p className="text-sm text-muted">
        Put the demonstration back the way it started: four products, two organisations, one
        person per role, two live subscriptions with their invoices. Everything else is emptied —
        every order, payment, invoice, conversation and account, <strong>including yours</strong> —
        and everybody is signed out. Refused while this platform hosts a product that is not the
        demonstration's.
      </p>

      {reset.error !== null && <ErrorSurface error={reset.error} />}

      {armed ? (
        <div className="space-y-3 rounded border border-danger/40 bg-danger/5 p-3" data-testid="demo-world-confirm">
          <p className="text-sm">
            This cannot be undone. The four products, the two organisations and the six people
            come back; nothing done since the last reset survives.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="danger"
              pending={reset.isPending}
              onClick={() => reset.mutate()}
              data-testid="demo-world-reset"
            >
              Yes, wipe and reseed
            </Button>
            <Button variant="secondary" onClick={() => setArmed(false)} disabled={reset.isPending}>
              Keep it
            </Button>
          </div>
        </div>
      ) : (
        <Button variant="danger" onClick={() => setArmed(true)} data-testid="demo-world-arm">
          Reset the demonstration world…
        </Button>
      )}
    </section>
  );
}

function DemoWorldReset({ world, onSignOut }: { world: DemoWorld; onSignOut: () => void }) {
  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="demo-world-done">
      <h2 className="text-xl font-semibold">The demonstration world is back</h2>

      <p className="text-sm text-muted">
        {world.products.map((product) => product.name).join(', ')} — with invoices{' '}
        {world.invoices.map((number) => <code key={number} className="mx-0.5">{number}</code>)}.
        Your account was among the rows emptied, so sign in again as one of these; every one of
        them has the password <code>{world.password}</code>.
      </p>

      <ul className="space-y-1 text-sm" data-testid="demo-world-people">
        {world.people.map((person) => (
          <li key={person.email} className="flex flex-wrap items-baseline gap-x-2">
            <code className="select-all">{person.email}</code>
            <span className="text-muted">
              {person.role}
              {person.scope === 'tenant' ? ` · ${person.tenants.join(', ')}` : ' · the console'}
            </span>
          </li>
        ))}
      </ul>

      <Button onClick={onSignOut} data-testid="demo-world-sign-in">
        Sign in again
      </Button>
    </section>
  );
}

function ProductRow({
  product,
  pending,
  onRename,
  onSetActive,
  onStorefront,
  onCatalogue,
  onInvoicing,
}: {
  product: PlatformProduct;
  pending: boolean;
  onRename: (name: string) => void;
  onSetActive: (active: boolean) => void;
  onStorefront: () => void;
  onCatalogue: () => void;
  onInvoicing: () => void;
}) {
  const [renaming, setRenaming] = useState(false);
  const [draft, setDraft] = useState(product.name);

  return (
    <li
      data-product={product.code}
      data-active={product.active ? 'true' : 'false'}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-medium">{product.name}</span>

        {/* Selectable, because copying it is the single commonest reason
            anybody opens this screen: it is what a deployment's
            VITE_DEFAULT_PRODUCT and every `?product=` link have to match. */}
        <code data-testid="product-code" className="select-all rounded bg-well px-1.5 py-0.5 text-xs">
          {product.code}
        </code>

        {!product.active && (
          <span
            data-testid="retired"
            className="rounded bg-well px-1.5 py-0.5 text-xs"
          >
            retired
          </span>
        )}
      </div>

      {renaming ? (
        <form
          className="mt-3 flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault();

            if (draft.trim() !== '') {
              onRename(draft.trim());
              setRenaming(false);
            }
          }}
        >
          {/* "New name" rather than "Name": the create form below has a
              field by that name, and two controls with one label is a screen
              a keyboard or a screen reader cannot tell apart. */}
          <Field id={`rename-${product.id}`} label="New name">
            <input
              id={`rename-${product.id}`}
              className={inputClass()}
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
            />
          </Field>
          <Button type="submit" pending={pending}>
            Save
          </Button>
          <Button type="button" variant="secondary" onClick={() => setRenaming(false)}>
            Cancel
          </Button>
        </form>
      ) : (
        <div className="mt-2 flex flex-wrap gap-2">
          <Button type="button" variant="secondary" onClick={onCatalogue}>
            Catalogue
          </Button>
          <Button type="button" variant="secondary" onClick={onStorefront}>
            Storefront
          </Button>
          {/* The step that is easy to forget and refuses last: a product can be
              created, priced and advertised and still not raise an invoice,
              because the issuer its documents must name is configuration. */}
          <Button type="button" variant="secondary" onClick={onInvoicing}>
            Invoicing
          </Button>
          <Button type="button" variant="secondary" onClick={() => setRenaming(true)}>
            Rename
          </Button>
          <Button
            type="button"
            variant={product.active ? 'danger' : 'secondary'}
            pending={pending}
            onClick={() => onSetActive(!product.active)}
          >
            {product.active ? 'Retire' : 'Reinstate'}
          </Button>
        </div>
      )}

      {product.active ? (
        <p className="mt-2 text-xs text-subtle">
          Retiring closes every door into this product. Its tenants, subscriptions and invoices
          stay — an invoice is a legal document and nothing here deletes one.
        </p>
      ) : (
        <p data-testid="retired-note" className="mt-2 text-xs text-subtle">
          Nobody can sign in to this product or buy from it. Its records are untouched.
        </p>
      )}
    </li>
  );
}
