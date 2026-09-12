import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import {
  useCreateProduct,
  usePlatformProducts,
  useUpdateProduct,
  type PlatformProduct,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

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
  const products = usePlatformProducts();
  const create = useCreateProduct();
  const update = useUpdateProduct();

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
      <header className="space-y-1">
        <h1 className="text-lg font-semibold">Products</h1>
        <p className="text-sm text-neutral-600 dark:text-neutral-400">
          Everything this platform hosts. A product is what tenants belong to and what offers are
          priced for — its <strong>code</strong> is what clients send as <code>X-Product</code> and
          what the public storefront reads from <code>?product=</code>.
        </p>
      </header>

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
                  void navigate({ to: '/console/storefront', search: { selected: product.code } })
                }
                onCatalogue={() =>
                  void navigate({ to: '/console/catalogue', search: { selected: product.code } })
                }
              />
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
        <h2 className="text-base font-semibold">Add a product</h2>

        <p className="text-sm text-neutral-600 dark:text-neutral-400">
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
    </div>
  );
}

function ProductRow({
  product,
  pending,
  onRename,
  onSetActive,
  onStorefront,
  onCatalogue,
}: {
  product: PlatformProduct;
  pending: boolean;
  onRename: (name: string) => void;
  onSetActive: (active: boolean) => void;
  onStorefront: () => void;
  onCatalogue: () => void;
}) {
  const [renaming, setRenaming] = useState(false);
  const [draft, setDraft] = useState(product.name);

  return (
    <li
      data-product={product.code}
      data-active={product.active ? 'true' : 'false'}
      className="rounded border border-neutral-200 p-3 text-sm dark:border-neutral-800"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-medium">{product.name}</span>

        {/* Selectable, because copying it is the single commonest reason
            anybody opens this screen: it is what a deployment's
            VITE_DEFAULT_PRODUCT and every `?product=` link have to match. */}
        <code data-testid="product-code" className="select-all rounded bg-neutral-100 px-1.5 py-0.5 text-xs dark:bg-neutral-900">
          {product.code}
        </code>

        {!product.active && (
          <span
            data-testid="retired"
            className="rounded bg-neutral-200 px-1.5 py-0.5 text-xs dark:bg-neutral-800"
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
        <p className="mt-2 text-xs text-neutral-500">
          Retiring closes every door into this product. Its tenants, subscriptions and invoices
          stay — an invoice is a legal document and nothing here deletes one.
        </p>
      ) : (
        <p data-testid="retired-note" className="mt-2 text-xs text-neutral-500">
          Nobody can sign in to this product or buy from it. Its records are untouched.
        </p>
      )}
    </li>
  );
}
