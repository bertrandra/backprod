import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { useViewState } from '@/app/frame/viewState';
import {
  useCreateFeature,
  useCreatePlan,
  useCreateStaffOffer,
  useCreateStaffOfferVersion,
  usePublishStaffOfferVersion,
  useStaffCatalogue,
  useStorefrontOffers,
  useUpdatePlan,
  type OfferGrantInput,
  type StaffFeature,
  type StaffPlan,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `console.admin.catalogue` — the platform pricing its own product.
 *
 * ADR-040 established that offers are the platform's, keyed on product and not
 * on tenant, and then left the only way to author one being *as a tenant*,
 * through `catalog.manage` on the tenant shell. So after it shipped, the
 * platform could price its own catalogue only by lending it to a customer and
 * acting as that customer. This is the door that should have existed first.
 *
 * **And plans and features could not be created at all.** `INSERT INTO plans`
 * appeared once in the whole repository, in the demo seeder; there was no
 * endpoint. A fresh installation therefore had a product, no plans, and no way
 * to make one — so creating an offer was impossible regardless of permissions,
 * because its insert selects the plan by id and matched nothing.
 *
 * The three sections are in the order the chain runs: a plan groups offers, a
 * feature is what a plan grants, an offer is a plan with a price. Nothing here
 * branches on a plan's name or a product's code (non-negotiable #25) — plans
 * are ordered by `rank` and that is the only ordering there is.
 */
export function CatalogueScreen() {
  const { selected } = useViewState();
  const productCode = selected ?? null;

  const catalogue = useStaffCatalogue(productCode);
  const offers = useStorefrontOffers(productCode);

  if (productCode === null || productCode === '') {
    return (
      <EmptyState
        title="No product chosen"
        description="A catalogue belongs to a product, and the console has no default. Pick one from Products."
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

  if (catalogue.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (catalogue.error !== null) {
    return <ErrorSurface error={catalogue.error} onRetry={() => void catalogue.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-8">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Catalogue</h1>
        <p className="text-sm text-muted">
          What <strong>{catalogue.data.product.name}</strong> sells. A plan groups offers and orders
          them; a feature is a capability a plan grants; an offer is a plan with a price. You need a
          plan before you can write an offer.
        </p>
      </header>

      <Plans productCode={productCode} plans={catalogue.data.plans} />
      <Features productCode={productCode} features={catalogue.data.features} />

      <Offers
        productCode={productCode}
        plans={catalogue.data.plans}
        features={catalogue.data.features}
        offers={offers.data?.offers ?? []}
        loading={offers.isPending}
        error={offers.error}
      />
    </div>
  );
}

function Plans({ productCode, plans }: { productCode: string; plans: readonly StaffPlan[] }) {
  const create = useCreatePlan(productCode);
  const update = useUpdatePlan(productCode);

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [rank, setRank] = useState('');

  return (
    <section className="space-y-3">
      <h2 className="text-xl font-semibold">Plans</h2>
      <p className="text-sm text-muted">
        Ordered by <strong>rank</strong>, which is the only ordering this platform has — an upgrade
        is a comparison of two numbers, never of two names. A plan cannot be deleted: offers point
        at it, and those offers price live subscriptions.
      </p>

      {create.error !== null && <ErrorSurface error={create.error} />}
      {update.error !== null && <ErrorSurface error={update.error} />}

      {plans.length === 0 ? (
        <EmptyState
          title="No plans yet"
          description="Nothing can be priced until there is one. Add the first below."
        />
      ) : (
        <ul className="space-y-2" data-testid="plan-list">
          {plans.map((plan) => (
            <li
              key={plan.id}
              data-plan={plan.code}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm sm:flex sm:items-center sm:gap-3"
            >
              <span className="font-medium sm:flex-1">{plan.name}</span>
              <code className="select-all text-xs text-muted">
                {plan.code}
              </code>
              <label className="mt-2 flex items-center gap-2 sm:mt-0">
                <span className="text-xs text-subtle">rank</span>
                <input
                  aria-label={`Rank of ${plan.name}`}
                  type="number"
                  defaultValue={plan.rank}
                  className={`${inputClass()} w-20`}
                  onBlur={(event) => {
                    const next = Number(event.target.value);

                    // Only on a real change, and only for a whole number: a
                    // blur that moved nothing must not record a REORDER in
                    // the trail.
                    if (Number.isInteger(next) && next >= 0 && next !== plan.rank) {
                      update.mutate({ planId: plan.id, rank: next });
                    }
                  }}
                />
              </label>
            </li>
          ))}
        </ul>
      )}

      <form
        className="grid gap-3 sm:grid-cols-[1fr_1fr_6rem_auto] sm:items-end"
        onSubmit={(event) => {
          event.preventDefault();

          if (code.trim() !== '' && name.trim() !== '' && rank.trim() !== '') {
            create.mutate(
              { code: code.trim().toLowerCase(), name: name.trim(), rank: Number(rank) },
              {
                onSuccess: () => {
                  setCode('');
                  setName('');
                  setRank('');
                },
              },
            );
          }
        }}
      >
        <Field id="plan-code" label="Plan code">
          <input
            id="plan-code"
            className={inputClass()}
            placeholder="pro"
            value={code}
            onChange={(event) => setCode(event.target.value)}
          />
        </Field>
        <Field id="plan-name" label="Plan name">
          <input
            id="plan-name"
            className={inputClass()}
            placeholder="Pro"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Field id="plan-rank" label="Rank">
          <input
            id="plan-rank"
            type="number"
            min={0}
            className={inputClass()}
            placeholder="10"
            value={rank}
            onChange={(event) => setRank(event.target.value)}
          />
        </Field>
        <Button
          type="submit"
          pending={create.isPending}
          disabled={code.trim() === '' || name.trim() === '' || rank.trim() === ''}
        >
          Add plan
        </Button>
      </form>
    </section>
  );
}

function Features({
  productCode,
  features,
}: {
  productCode: string;
  features: readonly StaffFeature[];
}) {
  const create = useCreateFeature(productCode);

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [kind, setKind] = useState<'BOOLEAN' | 'QUOTA'>('QUOTA');
  const [unit, setUnit] = useState('');

  return (
    <section className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">Features</h2>
      <p className="text-sm text-muted">
        What a plan grants. A <strong>quota</strong> is counted in a unit; a <strong>switch</strong>{' '}
        is on or off. The kind cannot be changed afterwards — every grant written against a feature
        meant one or the other, and flipping it would reinterpret prices somebody is already paying.
      </p>

      {create.error !== null && <ErrorSurface error={create.error} />}

      {features.length === 0 ? (
        <EmptyState
          title="No features yet"
          description="An offer can be sold without them — they are what a plan grants beyond access."
        />
      ) : (
        <ul className="space-y-2" data-testid="feature-list">
          {features.map((feature) => (
            <li
              key={feature.id}
              data-feature={feature.code}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
            >
              <span className="font-medium">{feature.name}</span>{' '}
              <code className="select-all text-xs text-muted">
                {feature.code}
              </code>
              <span className="ml-2 text-xs text-subtle">
                {feature.kind === 'QUOTA' ? `quota in ${feature.unit ?? '—'}` : 'switch'}
              </span>
            </li>
          ))}
        </ul>
      )}

      <form
        className="grid gap-3 sm:grid-cols-[1fr_1fr_8rem_1fr_auto] sm:items-end"
        onSubmit={(event) => {
          event.preventDefault();

          if (code.trim() !== '' && name.trim() !== '') {
            create.mutate(
              {
                code: code.trim().toLowerCase(),
                name: name.trim(),
                kind,
                // Null rather than "", and never on a switch: the API refuses
                // a unit on a boolean and the form should not send one.
                unit: kind === 'QUOTA' && unit.trim() !== '' ? unit.trim() : null,
              },
              {
                onSuccess: () => {
                  setCode('');
                  setName('');
                  setUnit('');
                },
              },
            );
          }
        }}
      >
        <Field id="feature-code" label="Feature code">
          <input
            id="feature-code"
            className={inputClass()}
            placeholder="projects"
            value={code}
            onChange={(event) => setCode(event.target.value)}
          />
        </Field>
        <Field id="feature-name" label="Feature name">
          <input
            id="feature-name"
            className={inputClass()}
            placeholder="Projects"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Field id="feature-kind" label="Kind">
          <select
            id="feature-kind"
            className={inputClass()}
            value={kind}
            onChange={(event) => setKind(event.target.value === 'BOOLEAN' ? 'BOOLEAN' : 'QUOTA')}
          >
            <option value="QUOTA">Quota</option>
            <option value="BOOLEAN">Switch</option>
          </select>
        </Field>
        <Field id="feature-unit" label="Unit">
          <input
            id="feature-unit"
            className={inputClass()}
            placeholder="projects"
            // Disabled rather than hidden, so the rule is visible instead of
            // being discovered through a 400.
            disabled={kind === 'BOOLEAN'}
            value={kind === 'BOOLEAN' ? '' : unit}
            onChange={(event) => setUnit(event.target.value)}
          />
        </Field>
        <Button
          type="submit"
          pending={create.isPending}
          disabled={code.trim() === '' || name.trim() === ''}
        >
          Add feature
        </Button>
      </form>
    </section>
  );
}

type AuthoredOffer = NonNullable<ReturnType<typeof useStorefrontOffers>['data']>['offers'][number];

function Offers({
  productCode,
  plans,
  features,
  offers,
  loading,
  error,
}: {
  productCode: string;
  plans: readonly StaffPlan[];
  features: readonly StaffFeature[];
  offers: readonly AuthoredOffer[];
  loading: boolean;
  error: Error | null;
}) {
  const create = useCreateStaffOffer(productCode);
  const addVersion = useCreateStaffOfferVersion(productCode);
  const publish = usePublishStaffOfferVersion(productCode);

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [planId, setPlanId] = useState('');
  const [price, setPrice] = useState('');
  const [currency, setCurrency] = useState('EUR');
  const [period, setPeriod] = useState<'MONTHLY' | 'YEARLY'>('MONTHLY');
  const [grants, setGrants] = useState<GrantDraft>({});

  return (
    <section className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">Offers</h2>
      <p className="text-sm text-muted">
        A plan with a price. An offer is born a <strong>draft</strong> and is not on sale until it
        is published — and a published version is frozen, so a new price is always a new version
        rather than an edit.
      </p>

      {create.error !== null && <ErrorSurface error={create.error} />}
      {addVersion.error !== null && <ErrorSurface error={addVersion.error} />}
      {publish.error !== null && <ErrorSurface error={publish.error} />}
      {error !== null && <ErrorSurface error={error} />}

      {loading ? (
        <SkeletonRows rows={3} />
      ) : offers.length === 0 ? (
        <EmptyState title="Nothing priced yet" description="Write the first offer below." />
      ) : (
        <ul className="space-y-2" data-testid="offer-list">
          {offers.map((offer) => (
            <OfferRow
              key={offer.id}
              offer={offer}
              pending={publish.isPending || addVersion.isPending}
              onPublish={(version) => publish.mutate({ offerId: offer.id, version })}
              onRepeatPrice={(minorUnits, offerCurrency, billingPeriod, carried) =>
                addVersion.mutate({
                  offerId: offer.id,
                  price_minor_units: minorUnits,
                  currency: offerCurrency,
                  billing_period: billingPeriod,
                  // Carried from the version being copied. Without this the new
                  // draft granted nothing: the button says "from this", and a
                  // version that kept the price and silently dropped every
                  // entitlement would put something on sale that gives the
                  // buyer less than what they compared it against.
                  grants: carried,
                })
              }
            />
          ))}
        </ul>
      )}

      {plans.length === 0 ? (
        <p data-testid="needs-a-plan" className="text-sm text-muted">
          Add a plan first — an offer is a plan with a price, and it cannot be written without one.
        </p>
      ) : (
        <form
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault();

            if (code.trim() !== '' && name.trim() !== '' && planId !== '' && price.trim() !== '') {
              create.mutate(
                {
                  code: code.trim().toLowerCase(),
                  name: name.trim(),
                  plan_id: planId,
                  billing_period: period,
                  // Minor units, never a decimal: a cent lost to binary
                  // rounding is a cent an auditor asks about.
                  price_minor_units: Number(price),
                  currency: currency.trim().toUpperCase(),
                  grants: grantsFrom(grants, features),
                },
                {
                  onSuccess: () => {
                    setCode('');
                    setName('');
                    setPrice('');
                    setGrants({});
                  },
                },
              );
            }
          }}
        >
          <Field id="offer-code" label="Offer code">
            <input
              id="offer-code"
              className={inputClass()}
              placeholder="pro-monthly"
              value={code}
              onChange={(event) => setCode(event.target.value)}
            />
          </Field>
          <Field id="offer-name" label="Offer name">
            <input
              id="offer-name"
              className={inputClass()}
              placeholder="Pro, monthly"
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
          </Field>
          <Field id="offer-plan" label="Plan">
            <select
              id="offer-plan"
              className={inputClass()}
              value={planId}
              onChange={(event) => setPlanId(event.target.value)}
            >
              <option value="">Choose a plan…</option>
              {plans.map((plan) => (
                <option key={plan.id} value={plan.id}>
                  {plan.name} (rank {plan.rank})
                </option>
              ))}
            </select>
          </Field>
          <Field id="offer-period" label="Billed">
            <select
              id="offer-period"
              className={inputClass()}
              value={period}
              onChange={(event) => setPeriod(event.target.value === 'YEARLY' ? 'YEARLY' : 'MONTHLY')}
            >
              <option value="MONTHLY">Monthly</option>
              <option value="YEARLY">Yearly</option>
            </select>
          </Field>
          <Field
            id="offer-price"
            label="Price in minor units"
            hint="2900 is €29.00. Integer minor units, never a decimal — a cent lost to rounding is a cent an auditor asks about. 0 is a legitimate price."
          >
            <input
              id="offer-price"
              type="number"
              min={0}
              className={inputClass()}
              placeholder="2900"
              value={price}
              onChange={(event) => setPrice(event.target.value)}
            />
          </Field>
          <Field id="offer-currency" label="Currency">
            <input
              id="offer-currency"
              className={inputClass()}
              maxLength={3}
              value={currency}
              onChange={(event) => setCurrency(event.target.value)}
            />
          </Field>

          <div className="sm:col-span-2">
            <GrantsEditor features={features} draft={grants} onChange={setGrants} />
          </div>

          <div className="sm:col-span-2">
            <Button
              type="submit"
              pending={create.isPending}
              disabled={
                code.trim() === '' || name.trim() === '' || planId === '' || price.trim() === ''
              }
            >
              Add offer as a draft
            </Button>
          </div>
        </form>
      )}
    </section>
  );
}

function OfferRow({
  offer,
  pending,
  onPublish,
  onRepeatPrice,
}: {
  offer: AuthoredOffer;
  pending: boolean;
  onPublish: (version: number) => void;
  onRepeatPrice: (
    minorUnits: number,
    currency: string,
    period: 'MONTHLY' | 'YEARLY' | 'CUSTOM',
    grants: OfferGrantInput[],
  ) => void;
}) {
  return (
    <li
      data-offer={offer.code}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-medium">{offer.name}</span>
        <code className="select-all text-xs text-muted">
          {offer.code}
        </code>
        <span className="text-xs text-subtle">{offer.plan.name}</span>
        {offer.publicly_listed && (
          <span
            data-testid="advertised"
            className="rounded bg-well px-1.5 py-0.5 text-xs"
          >
            on the public page
          </span>
        )}
      </div>

      <ul className="mt-2 space-y-1">
        {offer.versions.map((version) => (
          <li
            key={version.id}
            data-version={version.version}
            data-status={version.status}
            className="flex flex-wrap items-center gap-2 text-xs"
          >
            <span className="text-subtle">v{version.version}</span>
            <Amount money={version.price} className="font-medium" />
            <span className="text-subtle">{version.billing_period.toLowerCase()}</span>
            <span
              className={
                version.status === 'ACTIVE'
                  ? 'rounded bg-inverse px-1.5 py-0.5 text-white dark:bg-well dark:text-ink'
                  : 'rounded bg-well px-1.5 py-0.5'
              }
            >
              {version.status.toLowerCase()}
            </span>

            {version.status === 'DRAFT' && (
              <Button
                type="button"
                pending={pending}
                onClick={() => onPublish(version.version)}
              >
                Publish
              </Button>
            )}

            {version.grants.length > 0 && (
              <span data-testid="version-grants" className="text-subtle">
                {version.grants
                  .map((grant) =>
                    grant.kind === 'QUOTA'
                      ? `${grant.name} ${grant.unlimited ? 'unlimited' : String(grant.limit ?? 0)}`
                      : grant.name,
                  )
                  .join(' · ')}
              </span>
            )}

            {version.status === 'ACTIVE' && (
              <Button
                type="button"
                variant="secondary"
                pending={pending}
                onClick={() =>
                  onRepeatPrice(
                    version.price.minor_units,
                    version.price.currency,
                    version.billing_period,
                    version.grants.map((grant) => ({
                      feature_id: grant.feature_id,
                      limit: grant.limit,
                    })),
                  )
                }
              >
                New version from this
              </Button>
            )}
          </li>
        ))}
      </ul>

      <p className="mt-2 text-xs text-subtle">
        Publishing puts this price on sale: every quote, order and subscription written afterwards
        prices from it, and it can never be edited — only superseded.
      </p>
    </li>
  );
}

/**
 * What the offer being written grants, per feature.
 *
 * Keyed by feature id, because that is what the API takes: a grant names a
 * feature of this product by id, and one belonging to another product is
 * refused rather than ignored.
 */
type GrantDraft = Record<string, { readonly on: boolean; readonly limit: string }>;

/**
 * Turns the form's state into the list the API takes.
 *
 * A quota with an empty box is **unlimited**, not zero — the two are different
 * entitlements and `limit: null` is how the platform says the first. A switch
 * carries null always; it is on by being granted at all.
 */
function grantsFrom(draft: GrantDraft, features: readonly StaffFeature[]): OfferGrantInput[] {
  const grants: OfferGrantInput[] = [];

  for (const feature of features) {
    const entry = draft[feature.id];

    if (entry === undefined || !entry.on) {
      continue;
    }

    if (feature.kind !== 'QUOTA' || entry.limit.trim() === '') {
      grants.push({ feature_id: feature.id, limit: null });
      continue;
    }

    grants.push({ feature_id: feature.id, limit: Number(entry.limit) });
  }

  return grants;
}

/**
 * The grants half of the offer form — the half the API accepted and no screen
 * ever sent.
 *
 * ADR-043 shipped the offer form without it, which meant every offer the console
 * wrote granted nothing: it could be priced, published and bought, and the
 * subscription it created resolved to no entitlements at all. The endpoint had
 * taken `grants` since offers existed.
 *
 * Labelled with `aria-label` rather than a `<label>` per row: a feature's name
 * appears twice in this section, and two controls sharing one label is a screen
 * a keyboard or a screen reader cannot tell apart.
 */
function GrantsEditor({
  features,
  draft,
  onChange,
}: {
  features: readonly StaffFeature[];
  draft: GrantDraft;
  onChange: (next: GrantDraft) => void;
}) {
  if (features.length === 0) {
    return (
      <p data-testid="no-features-to-grant" className="text-sm text-muted">
        No features yet, so this offer grants access to the product and nothing more. That is a
        legitimate offer — add features above if it should grant more than that.
      </p>
    );
  }

  const update = (id: string, change: Partial<{ on: boolean; limit: string }>) =>
    onChange({
      ...draft,
      [id]: { on: false, limit: '', ...draft[id], ...change },
    });

  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">What it grants</legend>
      <p className="text-xs text-muted">
        A quota left empty is <strong>unlimited</strong>, which is not the same as a limit of zero.
        Grants belong to the version, so changing them later means publishing a new one — ADR-033
        freezes what somebody bought.
      </p>

      <ul className="space-y-1" data-testid="grant-editor">
        {features.map((feature) => {
          const entry = draft[feature.id] ?? { on: false, limit: '' };

          return (
            <li key={feature.id} data-grant={feature.code} className="flex flex-wrap items-center gap-2 text-sm">
              <input
                type="checkbox"
                aria-label={`Grant ${feature.name}`}
                checked={entry.on}
                onChange={(event) => update(feature.id, { on: event.target.checked })}
              />
              <span className="flex-1">{feature.name}</span>

              {feature.kind === 'QUOTA' ? (
                <>
                  <input
                    type="number"
                    min={0}
                    aria-label={`Limit for ${feature.name}`}
                    placeholder="unlimited"
                    className={`${inputClass()} w-28`}
                    // Disabled rather than hidden while the feature is not
                    // granted, so the rule is visible instead of being
                    // discovered by typing into a box that does nothing.
                    disabled={!entry.on}
                    value={entry.limit}
                    onChange={(event) => update(feature.id, { limit: event.target.value })}
                  />
                  <span className="text-xs text-subtle">{feature.unit ?? ''}</span>
                </>
              ) : (
                <span className="text-xs text-subtle">switch</span>
              )}
            </li>
          );
        })}
      </ul>
    </fieldset>
  );
}
