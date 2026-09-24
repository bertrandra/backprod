import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import {
  useForm,
  type FieldErrors,
  type UseFormRegister,
  type UseFormReturn,
  type UseFormWatch,
} from 'react-hook-form';
import { z } from 'zod';

import { useViewState } from '@/app/frame/viewState';
import {
  isDraft,
  useAddOfferVersion,
  useAuthoredOffer,
  useCreateOffer,
  usePublishOfferVersion,
  useRenameOffer,
  type AuthoredOfferVersion,
} from '@/queries/authoring';
import { useOffers, usePlans } from '@/queries/catalogue';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';
import { currentLocale, t } from '@/i18n';
import { tx } from '@/i18n/react';
import { billingPeriod } from '@/ui/period';

/**
 * `commerce.catalogue_authoring` — drafting a version, and publishing it.
 *
 * **A published version has no editable field anywhere on this screen**
 * (ADR-033). That is U5's exit criterion and it is met by *absence*: there is no
 * disabled input, no "edit" that explains itself, no form that checks a status
 * before submitting. A published version renders as a row of facts, because the
 * contract has no operation that would change one and inventing an affordance for
 * it would promise something the platform refuses.
 *
 * The absence is explained once, in words, so it reads as a decision rather than
 * an unbuilt feature — which is exactly what the roadmap asks for.
 *
 * Two identifiers behave differently, and the screen shows why:
 *
 *   - **`code` is permanent** — documents name the offer by it, and an identifier
 *     that can change is not an identifier. It is shown, never in an input;
 *   - **`name` is presentation**, so renaming exists and takes only a name.
 *
 * The version number is never typed here either: the database assigns `max + 1`,
 * so a draft is added and the number comes back.
 */
const PERIODS = ['MONTHLY', 'YEARLY', 'CUSTOM'] as const;

const draftSchema = z.object({
  billing_period: z.enum(PERIODS),
  price_minor_units: z.coerce.number().int().min(0),
  currency: z
    .string()
    .trim()
    .regex(/^[A-Z]{3}$/, 'Three uppercase letters, as ISO 4217 defines them.'),
});

type DraftValues = z.input<typeof draftSchema>;

const offerSchema = draftSchema.extend({
  code: z.string().trim().min(1, 'A code is permanent, so it cannot be empty.').max(64),
  name: z.string().trim().min(1, 'An offer needs a name.').max(200),
  plan_id: z.string().uuid('Choose the plan this offer belongs to.'),
});

export function OfferAuthoringScreen() {
  const { selected } = useViewState();
  const offers = useOffers();
  const plans = usePlans();
  const create = useCreateOffer();

  const form = useForm<z.input<typeof offerSchema>>({
    resolver: zodResolver(offerSchema),
    defaultValues: {
      code: '',
      name: '',
      plan_id: '',
      billing_period: 'MONTHLY',
      price_minor_units: 0,
      currency: 'EUR',
    },
  });

  if (offers.isPending || plans.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (offers.error !== null) {
    return <ErrorSurface error={offers.error} onRetry={() => void offers.refetch()} />;
  }

  // The plan list is not decoration here: an offer belongs to a plan and cannot
  // be created without one, so a failed plan read is a failed screen rather than
  // a form with an empty select.
  if (plans.error !== null) {
    return <ErrorSurface error={plans.error} onRetry={() => void plans.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={t("Offer authoring")}
        description={t("A published version is frozen: its terms can never be edited, only replaced by a newer version. That is what lets a quote pin the version that priced it.")}
      />

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">{t("Offers")}</h2>

        {offers.data.length === 0 ? (
          <EmptyState title={t("No offers yet")} description={t("Create one below.")} />
        ) : (
          <ul className="space-y-2">
            {offers.data.map((offer) => (
              <li key={offer.id} data-authored-offer={offer.id}>
                <a
                  href={`?selected=${offer.id}`}
                  className={
                    selected === offer.id
                      ? 'block rounded border border-accent bg-accent-wash p-3'
                      : 'block rounded-card border border-line bg-surface p-4 shadow-raise hover:bg-canvas dark:hover:bg-inverse'
                  }
                >
                  <span className="block font-medium">{offer.name}</span>
                  <span className="text-xs text-muted">
                    <code>{offer.code}</code> · {offer.plan.name}
                  </span>
                </a>
              </li>
            ))}
          </ul>
        )}
      </section>

      {selected !== undefined && <OfferVersions offerId={selected} />}

      <section className="space-y-3 border-t border-line pt-6">
        <h2 className="text-xl font-semibold">{t("New offer")}</h2>

        <form
          className="max-w-md space-y-4"
          onSubmit={(event) => {
            void form.handleSubmit((values) =>
              create.mutate(
                {
                  code: values.code,
                  name: values.name,
                  plan_id: values.plan_id,
                  billing_period: values.billing_period,
                  price_minor_units: Number(values.price_minor_units),
                  currency: values.currency,
                },
                { onSuccess: () => form.reset() },
              ),
            )(event);
          }}
        >
          <Field
            id="offer-code"
            label={t("Code")}
            hint={t("Permanent. Documents name the offer by this, so it cannot be changed later.")}
            error={form.formState.errors.code?.message}
          >
            <input
              id="offer-code"
              className={inputClass(form.formState.errors.code !== undefined)}
              {...form.register('code')}
            />
          </Field>

          <Field id="offer-name" label={t("Name")} error={form.formState.errors.name?.message}>
            <input
              id="offer-name"
              className={inputClass(form.formState.errors.name !== undefined)}
              {...form.register('name')}
            />
          </Field>

          <Field id="offer-plan" label={t("Plan")} error={form.formState.errors.plan_id?.message}>
            <select
              id="offer-plan"
              className={inputClass(form.formState.errors.plan_id !== undefined)}
              {...form.register('plan_id')}
            >
              <option value="">{t("Choose a plan")}</option>
              {/* Ordered by rank, like everywhere else. */}
              {plans.data.map((plan) => (
                <option key={plan.id} value={plan.id}>
                  {plan.name} {t("(rank")}{' '}{plan.rank})
                </option>
              ))}
            </select>
          </Field>

          <DraftFields form={form} />

          {create.error !== null && <ErrorSurface error={create.error} />}

          <Button type="submit" pending={create.isPending}>
            {t("Create offer")}</Button>
        </form>
      </section>
    </div>
  );
}

/**
 * The draft terms, shared by "new offer" and "new version".
 *
 * The price is entered in **minor units**, labelled as such. A decimal field
 * would mean parsing "29,90" and multiplying by 100 in the browser, and a cent
 * lost to that is a cent an auditor asks about (§25). The formatted amount is
 * shown beside it so nobody has to trust their own arithmetic either.
 */
function DraftFields<T extends DraftValues>({ form }: { form: UseFormReturn<T> }) {
  // The two forms differ only in the fields *around* these three, so this is
  // written once and reused. The narrowing is explicit rather than `any`:
  // `T extends DraftValues` guarantees the three names exist, but TypeScript
  // cannot prove `'currency' extends Path<T>` on its own.
  const register = form.register as UseFormRegister<DraftValues>;
  const errors = form.formState.errors as FieldErrors<DraftValues>;
  const watch = form.watch as UseFormWatch<DraftValues>;

  const minor = Number(watch('price_minor_units'));
  const currency = watch('currency');
  const showable = Number.isInteger(minor) && minor >= 0 && /^[A-Z]{3}$/.test(currency);

  return (
    <>
      <Field id="offer-period" label={t("Billing period")}>
        <select id="offer-period" className={inputClass()} {...register('billing_period')}>
          {PERIODS.map((period) => (
            <option key={period} value={period}>
              {period}
            </option>
          ))}
        </select>
      </Field>

      <Field
        id="offer-price"
        label={t("Price in minor units")}
        hint={t("Cents, not euros. Zero is legitimate — a free tier is still an offer.")}
        error={errors.price_minor_units?.message}
      >
        <input
          id="offer-price"
          inputMode="numeric"
          className={inputClass(errors.price_minor_units !== undefined)}
          {...register('price_minor_units')}
        />
      </Field>

      <Field
        id="offer-currency"
        label={t("Currency")}
        error={errors.currency?.message}
      >
        <input
          id="offer-currency"
          className={inputClass(errors.currency !== undefined)}
          {...register('currency')}
        />
      </Field>

      {showable && (
        <p className="text-sm text-muted">
          {t("That is")}{' '}<Amount money={{ minor_units: minor, currency }} />.
        </p>
      )}
    </>
  );
}

function OfferVersions({ offerId }: { offerId: string }) {
  const authored = useAuthoredOffer(offerId);
  const rename = useRenameOffer(offerId);
  const addVersion = useAddOfferVersion(offerId);
  const publish = usePublishOfferVersion(offerId);

  const [name, setName] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);

  const versionForm = useForm<z.input<typeof draftSchema>>({
    resolver: zodResolver(draftSchema),
    defaultValues: { billing_period: 'MONTHLY', price_minor_units: 0, currency: 'EUR' },
  });

  if (authored.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (authored.error !== null) {
    return <ErrorSurface error={authored.error} onRetry={() => void authored.refetch()} />;
  }

  const offer = authored.data;

  return (
    <section className="space-y-4 border-t border-line pt-6">
      <div className="space-y-1">
        <h2 className="text-xl font-semibold">{offer.name}</h2>
        {/* Shown, not editable: the code is identity. */}
        <p className="text-xs text-subtle">
          {tx("{code} — permanent", { code: <code>{offer.code}</code> })}
        </p>
      </div>

      <div className="flex max-w-md flex-wrap items-end gap-2">
        {/* "Offer name", not "Name": the create form below has a name field too,
            and two controls with the same label on one screen is an ambiguity for
            anyone reading it with a screen reader. */}
        <Field id="rename" label={t("Offer name")}>
          <input
            id="rename"
            className={inputClass()}
            value={name ?? offer.name}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Button
          type="button"
          variant="secondary"
          pending={rename.isPending}
          disabled={name === null || name.trim() === '' || name === offer.name}
          onClick={() => {
            if (name !== null) {
              rename.mutate(name.trim(), { onSuccess: () => setName(null) });
            }
          }}
        >
          {t("Rename")}</Button>
      </div>

      {rename.error !== null && <ErrorSurface error={rename.error} />}
      {publish.error !== null && <ErrorSurface error={publish.error} />}

      <ul className="space-y-2">
        {offer.versions.map((version) => (
          <VersionRow
            key={version.id}
            version={version}
            publishing={publish.isPending}
            onPublish={() => publish.mutate(version.version)}
          />
        ))}
      </ul>

      {adding ? (
        <form
          className="max-w-md space-y-4 rounded-card border border-line bg-surface p-4 shadow-raise"
          onSubmit={(event) => {
            void versionForm.handleSubmit((values) =>
              addVersion.mutate(
                {
                  billing_period: values.billing_period,
                  price_minor_units: Number(values.price_minor_units),
                  currency: values.currency,
                },
                {
                  onSuccess: () => {
                    versionForm.reset();
                    setAdding(false);
                  },
                },
              ),
            )(event);
          }}
        >
          <p className="text-sm text-muted">
            {t("A new version is born a draft — publishing it is a separate step.")}</p>

          <DraftFields form={versionForm} />

          {addVersion.error !== null && <ErrorSurface error={addVersion.error} />}

          <div className="flex flex-wrap gap-2">
            <Button type="submit" pending={addVersion.isPending}>
              {t("Add draft version")}</Button>
            <Button type="button" variant="secondary" onClick={() => setAdding(false)}>
              {t("Cancel")}</Button>
          </div>
        </form>
      ) : (
        <Button type="button" variant="secondary" onClick={() => setAdding(true)}>
          {t("New version")}</Button>
      )}
    </section>
  );
}

/**
 * One version.
 *
 * A draft can be published. Anything else is a row of facts — and that is the
 * whole of ADR-033 on screen: there is no branch here that renders an input for
 * a published version, because there is no operation it could call.
 */
function VersionRow({
  version,
  publishing,
  onPublish,
}: {
  version: AuthoredOfferVersion;
  publishing: boolean;
  onPublish: () => void;
}) {
  const draft = isDraft(version);

  return (
    <li
      data-version={version.version}
      data-status={version.status}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm md:flex md:items-center md:gap-3"
    >
      <div className="min-w-0 md:flex-1">
        <p className="font-medium">
          v{version.version}{' '}
          <span className="text-xs font-normal text-subtle">{version.status}</span>
        </p>
        <p className="text-xs text-muted">
          {billingPeriod(version.billing_period)} · <Amount money={version.price} /> {t("· sellable from")}{' '}
          {new Date(version.valid_from).toLocaleDateString(currentLocale())}
          {version.valid_until !== null &&
            ` to ${new Date(version.valid_until).toLocaleDateString(currentLocale())}`}
        </p>
      </div>

      {draft ? (
        <Button type="button" pending={publishing} onClick={onPublish}>
          {t("Publish")}</Button>
      ) : (
        // No control, and a reason. The absence is the design.
        <span data-testid="frozen" className="text-xs text-subtle">
          {t("Published versions are frozen")}</span>
      )}
    </li>
  );
}
