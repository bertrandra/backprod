import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { useChooseProduct } from '@/app/frame/ProductSwitcher';
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
import { PageHeader } from '@/ui/Page';
import { t } from '@/i18n';
import { tx } from '@/i18n/react';

import { ProductCredentials } from './ProductCredentials';
import { ProductWebhook } from './ProductWebhook';

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
        title={t("Products")}
        description={tx("Everything this platform hosts. A product is what tenants belong to and what offers are priced for — its {code} is what clients send as {header} and what the public storefront reads from {query}.", {
          code: <strong>{t("code")}</strong>,
          header: <code>X-Product</code>,
          query: <code>?product=</code>,
        })}
      />

      {create.error !== null && <ErrorSurface error={create.error} />}
      {update.error !== null && <ErrorSurface error={update.error} />}

      <section className="space-y-3">
        {products.data.length === 0 ? (
          // Reachable only on a database nothing installed, but said properly
          // rather than left as an empty list somebody reads as a bug.
          <EmptyState
            title={t("No products")}
            description={t("Nothing is registered. Create one below; the installer normally creates the first.")}
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
                onSetAppUrl={(appUrl) => update.mutate({ productId: product.id, app_url: appUrl })}
                onSetWebhookUrl={(webhookUrl) => update.mutate({ productId: product.id, webhook_url: webhookUrl })}
                onSetDisplayOrder={(position) => update.mutate({ productId: product.id, display_order: position })}
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
        <h2 className="text-xl font-semibold">{t("Add a product")}</h2>

        <p className="text-sm text-muted">
          {t("The code cannot be changed afterwards. A new product starts with no plans, no offers and no tenants — nothing is copied from an existing one.")}</p>

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
            label={t("Code")}
            hint={t("Lowercase letters, digits and hyphens. It travels in URLs and headers, so it cannot contain spaces.")}
          >
            <input
              id="product-code"
              className={inputClass()}
              placeholder="atlas"
              value={code}
              onChange={(event) => setCode(event.target.value)}
            />
          </Field>

          <Field id="product-name" label={t("Name")} hint={t("What people read. This one can be changed.")}>
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
            {t("Create product")}</Button>
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
  onSetAppUrl,
  onSetWebhookUrl,
  onSetDisplayOrder,
  onStorefront,
  onCatalogue,
  onInvoicing,
}: {
  product: PlatformProduct;
  pending: boolean;
  onRename: (name: string) => void;
  onSetActive: (active: boolean) => void;
  onSetAppUrl: (appUrl: string | null) => void;
  onSetWebhookUrl: (webhookUrl: string | null) => void;
  onSetDisplayOrder: (position: number) => void;
  onStorefront: () => void;
  onCatalogue: () => void;
  onInvoicing: () => void;
}) {
  const [renaming, setRenaming] = useState(false);
  const [draft, setDraft] = useState(product.name);
  const [addressing, setAddressing] = useState(false);
  const [address, setAddress] = useState(product.app_url ?? '');
  const [ordering, setOrdering] = useState(false);
  // A string while it is being typed: an empty field is a state a number
  // input has, and `NaN` on the way to `20` would be a validation error
  // shown to somebody in the middle of typing.
  const [position, setPosition] = useState(String(product.display_order));
  // The keys (ADR-051 §4) and the webhook (§5) together, behind one
  // disclosure closed by default (2026-09-22). They were two buttons in the
  // row of everyday actions, which put the rarest thing on the screen next
  // to the most common and made a product look like it needed both. They
  // belong to one question — *does this product have a server of its own?*
  // — and most do not: a product whose screens are a page talking to the
  // platform on the person's session needs neither, which is now said
  // rather than left to be inferred from two empty panels.
  //
  // Mounted only once open, not merely hidden: `<details>` keeps its
  // children in the document, and rendering them for every row would ask
  // the platform for every product's keys and deliveries to draw a closed
  // triangle.
  const [serverOpen, setServerOpen] = useState(false);

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

        {/* Where it sits in the lists (2026-09-23). On the row rather than
            behind the button that changes it: the number only means
            anything next to its neighbours', and a list whose order looks
            arbitrary is one somebody renames a product to fix. */}
        <span data-testid="display-order" className="text-xs text-subtle" title={t("Position in every list of products")}>
          #{product.display_order}
        </span>

        {!product.active && (
          <span
            data-testid="retired"
            className="rounded bg-well px-1.5 py-0.5 text-xs"
          >
            {t("retired")}</span>
        )}
      </div>

      {/* Where the product lives when it is deployed beside the platform
          (ADR-051 §3): the switcher and the landing send people there. Said
          on the row, because a wrong address here is a phishing page. */}
      {product.app_url != null && !addressing && (
        <p className="mt-1 text-xs text-muted" data-testid="app-url">
          {t("Lives at")}{' '}
          <a href={product.app_url} className="underline underline-offset-2" target="_blank" rel="noreferrer">
            {product.app_url}
          </a>
        </p>
      )}

      {addressing && (
        <form
          className="mt-3 flex flex-wrap items-end gap-2"
          data-testid="app-url-form"
          onSubmit={(event) => {
            event.preventDefault();
            onSetAppUrl(address.trim() === '' ? null : address.trim());
            setAddressing(false);
          }}
        >
          <Field
            id={`app-url-${product.id}`}
            label={t("Application address")}
            hint={t("Where this product's screens live when they are deployed beside the platform: an https address, no query. Empty puts the product back inside this shell.")}
          >
            <input
              id={`app-url-${product.id}`}
              type="url"
              placeholder="https://plan.example.test"
              className={inputClass()}
              value={address}
              onChange={(event) => setAddress(event.target.value)}
            />
          </Field>
          <Button type="submit" pending={pending}>
            {t("Save")}</Button>
          <Button type="button" variant="secondary" onClick={() => setAddressing(false)}>
            {t("Cancel")}</Button>
        </form>
      )}

      {ordering && (
        <form
          className="mt-3 flex flex-wrap items-end gap-2"
          data-testid="display-order-form"
          onSubmit={(event) => {
            event.preventDefault();

            const wanted = Number.parseInt(position, 10);

            // A blank or unreadable field leaves the product where it is,
            // rather than sending a number the server would refuse: there
            // is nothing to correct here, only something not yet said.
            if (Number.isInteger(wanted) && wanted >= 0) {
              onSetDisplayOrder(wanted);
              setOrdering(false);
            }
          }}
        >
          <Field
            id={`display-order-${product.id}`}
            label={t("Position")}
            hint={t("Where this product sits in every list — the switcher, the public storefront, this screen. Lowest first, and the products are numbered in tens so one can be slipped between two others.")}
          >
            <input
              id={`display-order-${product.id}`}
              type="number"
              min={0}
              max={100000}
              step={10}
              className={inputClass()}
              value={position}
              onChange={(event) => setPosition(event.target.value)}
            />
          </Field>
          <Button type="submit" pending={pending}>
            {t("Save")}</Button>
          <Button type="button" variant="secondary" onClick={() => setOrdering(false)}>
            {t("Cancel")}</Button>
        </form>
      )}

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
          <Field id={`rename-${product.id}`} label={t("New name")}>
            <input
              id={`rename-${product.id}`}
              className={inputClass()}
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
            />
          </Field>
          <Button type="submit" pending={pending}>
            {t("Save")}</Button>
          <Button type="button" variant="secondary" onClick={() => setRenaming(false)}>
            {t("Cancel")}</Button>
        </form>
      ) : (
        <div className="mt-2 flex flex-wrap gap-2">
          <Button type="button" variant="secondary" onClick={onCatalogue}>
            {t("Catalogue")}</Button>
          <Button type="button" variant="secondary" onClick={onStorefront}>
            {t("Storefront")}</Button>
          {/* The step that is easy to forget and refuses last: a product can be
              created, priced and advertised and still not raise an invoice,
              because the issuer its documents must name is configuration. */}
          <Button type="button" variant="secondary" onClick={onInvoicing}>
            {t("Invoicing")}</Button>
          <Button type="button" variant="secondary" onClick={() => setRenaming(true)}>
            {t("Rename")}</Button>
          <Button type="button" variant="secondary" onClick={() => setAddressing(true)}>
            {t("Application address")}</Button>
          <Button type="button" variant="secondary" data-testid="reorder" onClick={() => setOrdering(true)}>
            {t("Position")}</Button>
          <Button
            type="button"
            variant={product.active ? 'danger' : 'secondary'}
            pending={pending}
            onClick={() => onSetActive(!product.active)}
          >
            {product.active ? t("Retire") : t("Reinstate")}
          </Button>
        </div>
      )}

      <details
        className="mt-3 rounded-control border border-line px-3 py-2"
        data-testid={`server-${product.code}`}
        onToggle={(event) => setServerOpen(event.currentTarget.open)}
      >
        <summary className="cursor-pointer text-xs font-semibold">
          {t("If this product has a server of its own")}
        </summary>

        <p className="mt-2 max-w-prose text-xs text-muted">
          {t("A key is what that server sends to call the platform with nobody signed in — a tenant's entitlements, its members, the usage it reports — and the webhook is where the platform tells it what happened. A product whose screens talk to the platform on the person's own session needs neither, and leaves this closed.")}
        </p>

        {serverOpen && (
          <>
            <ProductCredentials productId={product.id} />
            <ProductWebhook product={product} pending={pending} onSetWebhookUrl={onSetWebhookUrl} />
          </>
        )}
      </details>

      {product.active ? (
        <p className="mt-2 text-xs text-subtle">
          {t("Retiring closes every door into this product. Its tenants, subscriptions and invoices stay — an invoice is a legal document and nothing here deletes one.")}</p>
      ) : (
        <p data-testid="retired-note" className="mt-2 text-xs text-subtle">
          {t("Nobody can sign in to this product or buy from it. Its records are untouched.")}</p>
      )}
    </li>
  );
}
