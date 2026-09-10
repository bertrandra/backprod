import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { can } from '@/app/access/access';
import { useSession } from '@/queries/session';
import { useSaveTaxProfile, useTaxProfile, type TaxProfile } from '@/queries/tax';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `tax.profile` — what the tenant *claims*, and what the platform *checked*.
 *
 * Those are two different facts about the same VAT number, and §25.2 requires
 * both on screen. `taxable_person` is a claim: the contract says so outright —
 * *"claiming it is not the same as proving it"*. `vat_number_status` is the
 * check. A screen that showed only the number would let somebody conclude that
 * typing one grants reverse charge, and it does not.
 *
 * **UNAVAILABLE is its own answer.** Not "invalid", not a quiet retry: it means
 * the verification service was asked and gave no answer. Fail-closed — R8 — so
 * reverse charge stays unavailable while a number is unproved, and the screen
 * says which of the two it is rather than collapsing them into a red mark.
 *
 * Nothing here is edited in place after a save: the backend normalises the
 * number before checking it (*"FR 123 456" is not what a verification service
 * accepts*), so the answer may legitimately differ from what was typed, and the
 * response is what the form shows.
 */
const schema = z.object({
  customer_kind: z.enum(['B2B', 'B2C']),
  country_code: z
    .string()
    .trim()
    .regex(/^([A-Za-z]{2})?$/, 'Two letters, as ISO 3166 defines them.'),
  taxable_person: z.boolean(),
  vat_number: z.string().trim(),
});

type Values = z.infer<typeof schema>;

/** What each verification outcome means, in the words §25.2 uses for it. */
const STATUS: Record<string, { label: string; explanation: string; tone: string }> = {
  VERIFIED: {
    label: 'Verified',
    explanation: 'The number was checked against the registry and the registry recognised it.',
    tone: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200',
  },
  INVALID: {
    label: 'Invalid',
    explanation: 'The registry was asked and answered that this number is not one of its own.',
    tone: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200',
  },
  UNAVAILABLE: {
    label: 'Unavailable',
    explanation:
      'The registry was asked and gave no answer. That is not a refusal and not a verification — the number stays unproved until the check succeeds.',
    tone: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200',
  },
};

export function TaxProfileScreen() {
  const { data: session } = useSession();
  const profile = useTaxProfile();
  const save = useSaveTaxProfile();

  const mayManage = can(session, 'tax.manage');

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    // `values`, not `defaultValues`: the profile arrives after the first render
    // and a default would leave the form permanently empty.
    values: {
      customer_kind: profile.data?.customer_kind ?? 'B2C',
      country_code: profile.data?.country_code ?? '',
      taxable_person: profile.data?.taxable_person ?? false,
      vat_number: profile.data?.vat_number ?? '',
    },
  });

  if (profile.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (profile.error !== null) {
    return <ErrorSurface error={profile.error} onRetry={() => void profile.refetch()} />;
  }

  const current = profile.data;

  return (
    <div className="max-w-2xl space-y-8">
      <header className="space-y-1">
        <h1 className="text-lg font-semibold">Tax profile</h1>
        <p className="text-sm text-neutral-600 dark:text-neutral-400">
          What is recorded here decides which VAT regime applies to what you are invoiced. A VAT
          number is a claim until the registry confirms it; the state below says which it is.
        </p>
      </header>

      <Verification profile={current} />

      <form
        className="space-y-4 border-t border-neutral-200 pt-6 dark:border-neutral-800"
        onSubmit={(event) => {
          void form.handleSubmit((values) =>
            save.mutate({
              customer_kind: values.customer_kind,
              country_code: values.country_code.trim() === '' ? null : values.country_code.toUpperCase(),
              taxable_person: values.taxable_person,
              vat_number: values.vat_number.trim() === '' ? null : values.vat_number,
            }),
          )(event);
        }}
      >
        <fieldset disabled={!mayManage} className="space-y-4">
          <Field
            id="customer_kind"
            label="Customer kind"
            hint="B2B and B2C are taxed differently in the same country — this is the question that decides it."
          >
            <select id="customer_kind" className={inputClass()} {...form.register('customer_kind')}>
              <option value="B2C">B2C — a private individual</option>
              <option value="B2B">B2B — a business</option>
            </select>
          </Field>

          <Field
            id="country_code"
            label="Country"
            hint="Two letters, ISO 3166. Where you are is where the rate is looked up."
            error={form.formState.errors.country_code?.message}
          >
            <input
              id="country_code"
              className={inputClass(form.formState.errors.country_code !== undefined)}
              {...form.register('country_code')}
            />
          </Field>

          <div className="space-y-1">
            <label htmlFor="taxable_person" className="flex items-start gap-2 text-sm">
              <input
                id="taxable_person"
                type="checkbox"
                className="mt-0.5 size-5"
                {...form.register('taxable_person')}
              />
              <span>
                This organisation is a taxable person
                <span className="block text-xs text-neutral-600 dark:text-neutral-400">
                  A statement about yourself. It is not proof, and on its own it grants nothing —
                  the verified number below is what does.
                </span>
              </span>
            </label>
          </div>

          <Field
            id="vat_number"
            label="VAT number"
            hint="Checked against the registry when saved. Spaces and punctuation are removed first."
          >
            <input id="vat_number" className={inputClass()} {...form.register('vat_number')} />
          </Field>

          {save.error !== null && <ErrorSurface error={save.error} />}

          <Button type="submit" pending={save.isPending}>
            Save
          </Button>
        </fieldset>

        {!mayManage && (
          <p data-testid="read-only" className="text-sm text-neutral-600 dark:text-neutral-400">
            You can read this profile. Changing it needs <code>tax.manage</code>, which an
            administrator of your organisation grants.
          </p>
        )}

        {save.isSuccess && (
          <p data-testid="saved" className="text-sm text-neutral-600 dark:text-neutral-400">
            Saved. The number was normalised and re-checked — the state above is what the check
            concluded, not what was typed.
          </p>
        )}
      </form>
    </div>
  );
}

/**
 * The check, and what it licenses.
 *
 * `reverse_charge_available` is the backend's own conclusion and is rendered as
 * such rather than derived here from `taxable_person && status === 'VERIFIED'`.
 * Recomputing it would put the fail-closed rule in two places, and the copy in
 * the browser would be the one nobody updated.
 */
function Verification({ profile }: { profile: TaxProfile }) {
  const status = profile.vat_number_status;
  const known = status === null ? undefined : STATUS[status];

  return (
    <section
      data-testid="verification"
      data-status={status ?? 'NONE'}
      data-reverse-charge={String(profile.reverse_charge_available)}
      className="space-y-3 rounded border border-neutral-200 p-4 text-sm dark:border-neutral-800"
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">VAT number</span>
        {profile.vat_number === null ? (
          <span className="text-neutral-600 dark:text-neutral-400">none recorded</span>
        ) : (
          <code data-testid="vat-number">{profile.vat_number}</code>
        )}

        {status !== null && (
          <span
            data-testid="vat-status"
            className={`rounded px-2 py-0.5 text-xs font-medium ${known?.tone ?? 'bg-neutral-200 dark:bg-neutral-800'}`}
          >
            {known?.label ?? status}
          </span>
        )}
      </div>

      {known !== undefined && (
        <p data-testid="status-explanation" className="text-neutral-600 dark:text-neutral-400">
          {known.explanation}
        </p>
      )}

      {profile.vat_number_verified_at !== null && (
        // Evidence with a date on it: a number verified last year is re-checked
        // when this gets old, not on every save.
        <p className="text-xs text-neutral-500">
          Last checked {new Date(profile.vat_number_verified_at).toLocaleDateString()}
          {profile.vat_number_country !== null && ` · registry of ${profile.vat_number_country}`}
        </p>
      )}

      <p data-testid="reverse-charge" className="border-t border-neutral-200 pt-3 dark:border-neutral-800">
        {profile.reverse_charge_available
          ? 'Reverse charge is available: the invoice carries no VAT and states that you account for it.'
          : 'Reverse charge is not available. Until the number is verified, VAT is charged — an unproved number is treated as unproved rather than trusted.'}
      </p>
    </section>
  );
}
