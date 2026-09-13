import { Link, useNavigate } from '@tanstack/react-router';

import { useViewState } from '@/app/frame/viewState';
import {
  usePlatformProducts,
  useProductReadiness,
  type SetupStepKey,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `console.admin.readiness` — the console's landing, and the map of a maze.
 *
 * A fresh installation was navigable only by walking into its refusals. No plan,
 * so creating an offer matched nothing. A published version and an empty shop
 * window, because advertising is a separate decision. A checkout that got all
 * the way through and refused with `BILLING_NOT_CONFIGURED`, because an invoice
 * must name its issuer. Every refusal is correct; together they were a maze, and
 * the only way to learn the order was to get it wrong.
 *
 * **The order is the dependency order.** Following this list top to bottom never
 * meets a refusal — which is the whole difference between a path and a
 * checklist, and why the steps are not sorted by state. A done step stays where
 * it is so the shape of the chain does not move under somebody halfway through
 * it.
 *
 * **Nothing here is computed in the browser.** Counting plans and offers on this
 * screen would be a second implementation of "can this sell", and the first to
 * drift would be the one an operator trusts. The backend answers through the
 * same ports the enforcing code uses — including asking the *clock* whether a
 * version is sellable now, which a status field cannot answer.
 */
export function ReadinessScreen() {
  const { selected } = useViewState();
  const products = usePlatformProducts();

  // A deployment with one product has nothing to choose, and asking anyway is
  // a question with one answer. With several, the console has no ambient
  // product (a platform role grants no membership) so it has to be named.
  const only = products.data?.length === 1 ? (products.data[0]?.code ?? null) : null;
  const productCode = selected ?? only;

  const readiness = useProductReadiness(productCode);

  if (products.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (products.error !== null) {
    return <ErrorSurface error={products.error} onRetry={() => void products.refetch()} />;
  }

  if (products.data.length === 0) {
    return (
      <EmptyState
        title="No product yet"
        description="Everything starts with a product: it is what tenants belong to and what offers are priced for. Create the first one."
        action={
          <Link
            to="/console/products"
            className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            Go to Products
          </Link>
        }
      />
    );
  }

  if (productCode === null) {
    return (
      <EmptyState
        title="Choose a product"
        description="This deployment hosts several, and the console has no default — a platform role grants no membership, so there is no ambient product to assume."
        action={
          <Link
            to="/console/products"
            className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            Go to Products
          </Link>
        }
      />
    );
  }

  if (readiness.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (readiness.error !== null) {
    return <ErrorSurface error={readiness.error} onRetry={() => void readiness.refetch()} />;
  }

  const { product, steps, sellable, next } = readiness.data;

  return (
    <div className="max-w-3xl space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Setting up {product.name}</h1>
        <p className="text-sm text-muted">
          Every step depends on the one above it, so working down this list never meets a refusal.
          What is counted here is read the same way a sale reads it — this cannot say ready where a
          checkout would refuse.
        </p>
      </header>

      {/*
        One card, and a dot that says which state it is in.

        The two branches used to be different objects — a surface card for
        “can be bought” and a raw amber block for “not yet” — so the page
        changed shape on the transition that matters most, and the amber was the
        last thing on this screen still painted outside the token system. The
        card is the same either way; the colour is carried by 6px of dot, the
        way the platform band carries its own.
      */}
      {sellable ? (
        <p
          data-testid="sellable"
          className="flex items-start gap-2.5 rounded-card border border-line bg-surface p-4 text-sm shadow-raise"
        >
          <span aria-hidden="true" className="mt-1.5 size-1.5 shrink-0 rounded-full bg-success" />
          <span>
            <strong>This product can be bought.</strong> Somebody with no account can reach the
            public page, choose an offer, create an account and pay for it.
          </span>
        </p>
      ) : (
        <p
          data-testid="not-sellable"
          role="status"
          className="flex items-start gap-2.5 rounded-card border border-line bg-surface p-4 text-sm shadow-raise"
        >
          <span aria-hidden="true" className="mt-1.5 size-1.5 shrink-0 rounded-full bg-warning" />
          <span>
            <strong>Not on sale yet.</strong> {blockingCount(steps)} step
            {blockingCount(steps) === 1 ? '' : 's'} left before a stranger can buy from this product.
          </span>
        </p>
      )}

      <ol className="space-y-2" data-testid="setup-chain">
        {steps.map((step, index) => (
          <Step
            key={step.key}
            index={index + 1}
            step={step}
            isNext={step.key === next}
            productCode={productCode}
          />
        ))}
      </ol>
    </div>
  );
}

function blockingCount(steps: readonly { done: boolean; blocking: boolean }[]): number {
  return steps.filter((step) => step.blocking && !step.done).length;
}

/**
 * The words for each step, and where it is fixed.
 *
 * A mapping table rather than prose in the API: the backend answers with facts
 * so that a typo is fixed here, in the surface that speaks to a person, rather
 * than in a contract other clients also read.
 *
 * `payments` deliberately has no route. It is the deployment's configuration,
 * not the product's, and offering a button that leads to a screen which cannot
 * change it would be worse than saying so.
 */
/**
 * The console paths a step can send somebody to.
 *
 * A union of literals rather than `string`, because the router types its search
 * parameters per route: with a plain string it cannot tell that `/console/products`
 * takes `?selected=`, and the call stops type-checking. Naming the four here also
 * means a step pointing at a route that does not exist fails the build.
 */
type StepRoute =
  | '/console/products'
  | '/console/invoicing'
  | '/console/catalogue'
  | '/console/storefront';

const WORDS: Record<
  SetupStepKey,
  { title: string; why: string; to?: StepRoute; action?: string; hint?: string }
> = {
  product: {
    title: 'An active product',
    why: 'Everything hangs from it — tenants belong to a product, offers are priced for one, and its code is what clients send as X-Product.',
    to: '/console/products',
    action: 'Products',
  },
  billing_identity: {
    title: 'Who the invoices name',
    why: 'An invoice is a legal document and must name its issuer. Without this, a checkout gets all the way to the end and refuses with BILLING_NOT_CONFIGURED.',
    to: '/console/invoicing',
    action: 'Invoicing',
  },
  tax: {
    title: 'The supplier’s tax position',
    why: 'Optional while you sell at home — the invoice falls back to the issuer’s own country. State it before selling across a border, or VAT gets filed in the wrong one.',
    to: '/console/invoicing',
    action: 'Invoicing',
  },
  plans: {
    title: 'At least one plan',
    why: 'An offer is a plan with a price, so nothing can be priced until one exists. Rank is what orders them — an upgrade is a comparison of two integers, never of two names.',
    to: '/console/catalogue',
    action: 'Catalogue',
  },
  features: {
    title: 'Features to grant',
    why: 'Optional. An offer that grants access to the product and nothing more is a legitimate offer; features are what it grants beyond that.',
    to: '/console/catalogue',
    action: 'Catalogue',
  },
  offers: {
    title: 'At least one offer',
    why: 'A plan with a price. It is born a draft — writing it does not put it on sale.',
    to: '/console/catalogue',
    action: 'Catalogue',
  },
  published: {
    title: 'A published version',
    why: 'Publishing is what puts a price on sale, and it freezes that version: every order afterwards prices from it, and a new price is always a new version.',
    to: '/console/catalogue',
    action: 'Catalogue',
  },
  advertised: {
    title: 'Shown on the public page',
    why: 'Being sellable and being shown are two decisions. Until an offer is advertised, the storefront answers a stranger with nothing.',
    to: '/console/storefront',
    action: 'Storefront',
  },
  payments: {
    title: 'A payment provider',
    why: 'Configured when the server is deployed, not from a screen. Without one, the platform can price and advertise but cannot take money.',
    hint: 'Set it in the deployment’s environment, then reload.',
  },
};

/**
 * One literal `navigate` per route, and not a variable one.
 *
 * The router types search parameters *per route*, so a `to` holding a union of
 * four cannot resolve a single search shape and the call stops type-checking.
 * Writing the four out keeps every one of them checked against its own route,
 * and the switch is exhaustive — adding a route to `StepRoute` without a case
 * here fails the build rather than silently doing nothing.
 */
function go(
  navigate: ReturnType<typeof useNavigate>,
  route: StepRoute,
  selected: string,
): void {
  switch (route) {
    case '/console/products':
      void navigate({ to: '/console/products', search: { selected } });
      return;
    case '/console/invoicing':
      void navigate({ to: '/console/invoicing', search: { selected } });
      return;
    case '/console/catalogue':
      void navigate({ to: '/console/catalogue', search: { selected } });
      return;
    case '/console/storefront':
      void navigate({ to: '/console/storefront', search: { selected } });
      return;
  }
}

function Step({
  index,
  step,
  isNext,
  productCode,
}: {
  index: number;
  step: { key: SetupStepKey; done: boolean; blocking: boolean; detail: Record<string, unknown> };
  isNext: boolean;
  productCode: string;
}) {
  const navigate = useNavigate();
  const words = WORDS[step.key];

  return (
    <li
      data-step={step.key}
      data-done={step.done ? 'true' : 'false'}
      data-next={isNext ? 'true' : 'false'}
      className={
        isNext
          ? 'rounded-card border border-accent bg-accent-wash/40 p-3 shadow-raise'
          : 'rounded-card border border-line bg-surface p-4 shadow-raise'
      }
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span
          aria-hidden="true"
          className="text-xs tabular-nums text-subtle"
        >
          {index}
        </span>
        <span className="font-medium">{words.title}</span>

        <span
          data-testid={`state-${step.key}`}
          className={
            // A finished step is the quietest thing on its row, not the loudest.
            // The old "done" badge was solid black, which made a page of
            // completed work shout and the one remaining step whisper.
            step.done
              ? 'rounded-full bg-success-wash px-2 py-0.5 text-2xs font-semibold uppercase text-success'
              : step.blocking
                ? 'rounded-full bg-warning-wash px-2 py-0.5 text-2xs font-semibold uppercase text-warning'
                : 'rounded-full bg-well px-2 py-0.5 text-2xs font-semibold uppercase text-subtle'
          }
        >
          {step.done ? 'done' : step.blocking ? 'required' : 'optional'}
        </span>

        {isNext && (
          <span
            data-testid="do-this-next"
            className="rounded-full bg-accent px-2 py-0.5 text-2xs font-semibold uppercase text-on-accent"
          >
            do this next
          </span>
        )}
      </div>

      <p className="mt-1 text-sm text-muted">{words.why}</p>

      <Detail step={step} />

      <div className="mt-2">
        {words.to !== undefined && words.action !== undefined ? (
          <Button
            type="button"
            variant={isNext ? 'primary' : 'secondary'}
            onClick={() => go(navigate, words.to as StepRoute, productCode)}
          >
            {step.done ? `Review in ${words.action}` : `Go to ${words.action}`}
          </Button>
        ) : (
          <p className="text-xs text-subtle">{words.hint}</p>
        )}
      </div>
    </li>
  );
}

/**
 * What was counted, or what is missing — the audit half.
 *
 * Named fields rather than a dump: "2 plans" and "missing legal_name,
 * country_code" are what somebody can act on, and everything else in the detail
 * object is there for a script.
 */
function Detail({
  step,
}: {
  step: { key: SetupStepKey; done: boolean; detail: Record<string, unknown> };
}) {
  const missing = step.detail['missing'];
  const count = step.detail['count'];

  if (Array.isArray(missing) && missing.length > 0) {
    return (
      <p data-testid={`missing-${step.key}`} className="mt-1 text-xs text-subtle">
        Missing: <strong className="font-mono">{missing.join(', ')}</strong>
      </p>
    );
  }

  if (typeof count === 'number') {
    return (
      <p data-testid={`count-${step.key}`} className="mt-1 text-xs tabular-nums text-subtle">
        {count} so far
      </p>
    );
  }

  if (step.key === 'product' && step.detail['active'] === false) {
    return (
      <p data-testid="missing-product" className="mt-1 text-xs text-subtle">
        This product is retired. Nobody can sign in to it or buy from it.
      </p>
    );
  }

  return null;
}
