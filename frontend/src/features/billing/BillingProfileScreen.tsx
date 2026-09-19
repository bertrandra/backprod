import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useBillingProfile, useSaveBillingProfile } from '@/queries/billing';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { CountrySelect } from '@/ui/pickers/Select';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';
import { FieldCell, FieldGroup, FieldRow, FormActions, FormCard } from '@/ui/Form';
import { t } from '@/i18n';

/**
 * `billing.profile` — the legal identity that appears on the document.
 *
 * **Changing it never rewrites an invoice already sent.** The profile is copied
 * onto each document at issue, so what is edited here is what *future* documents
 * will say. The screen states that plainly, because the opposite assumption is
 * both natural and expensive: somebody correcting a typo in their company name
 * would otherwise expect last quarter's invoices to change, and would be wrong.
 *
 * Only `legal_name` is required by the contract. The rest is nullable, and an
 * empty field is sent as null rather than as an empty string — a document with
 * `address_line2: ""` prints a blank line where a missing one prints nothing.
 */
const schema = z.object({
  legal_name: z.string().trim().min(1, 'A legal name is what appears on the invoice.'),
  vat_number: z.string().trim(),
  registration_number: z.string().trim(),
  address_line1: z.string().trim(),
  address_line2: z.string().trim(),
  postal_code: z.string().trim(),
  city: z.string().trim(),
  country_code: z
    .string()
    .trim()
    .regex(/^([A-Z]{2})?$/, 'Two uppercase letters, as ISO 3166 defines them.'),
  billing_email: z.union([z.literal(''), z.string().email('That is not an email address.')]),
});

type Values = z.infer<typeof schema>;

/** An empty field is an absent value, not an empty string on a printed document. */
const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

export function BillingProfileScreen() {
  const profile = useBillingProfile();
  const save = useSaveBillingProfile();

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    // `values` rather than `defaultValues`: the profile arrives after the first
    // render, and defaults would leave the form permanently empty.
    values: {
      legal_name: profile.data?.legal_name ?? '',
      vat_number: profile.data?.vat_number ?? '',
      registration_number: profile.data?.registration_number ?? '',
      address_line1: profile.data?.address_line1 ?? '',
      address_line2: profile.data?.address_line2 ?? '',
      postal_code: profile.data?.postal_code ?? '',
      city: profile.data?.city ?? '',
      country_code: profile.data?.country_code ?? '',
      billing_email: profile.data?.billing_email ?? '',
    },
  });

  if (profile.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (profile.error !== null) {
    return <ErrorSurface error={profile.error} onRetry={() => void profile.refetch()} />;
  }

  return (
    <div className="max-w-2xl space-y-6">
      <PageHeader
        title={t("Billing identity")}
        description={t("This is what appears on invoices. It is copied onto each document when the document is issued, so changing it here affects future invoices and never one already sent.")}
      />

      <FormCard
        onSubmit={(event) => {
          void form.handleSubmit((values) =>
            save.mutate({
              legal_name: values.legal_name,
              vat_number: orNull(values.vat_number),
              registration_number: orNull(values.registration_number),
              address_line1: orNull(values.address_line1),
              address_line2: orNull(values.address_line2),
              postal_code: orNull(values.postal_code),
              city: orNull(values.city),
              country_code: orNull(values.country_code),
              billing_email: orNull(values.billing_email),
            }),
          )(event);
        }}
      >
        <FieldGroup
          legend="Who is being invoiced"
          hint={t("The legal identity the invoice names. A VAT number here is what decides whether VAT is charged at all on a cross-border sale.")}
        >
          <Field
            id="legal_name"
            label={t("Legal name")}
            error={form.formState.errors.legal_name?.message}
          >
            <input
              id="legal_name"
              className={inputClass(form.formState.errors.legal_name !== undefined)}
              {...form.register('legal_name')}
            />
          </Field>

          <FieldRow>
            <FieldCell width="medium">
              <Field id="vat_number" label={t("VAT number")} hint={t("Optional.")}>
                <input id="vat_number" className={inputClass()} {...form.register('vat_number')} />
              </Field>
            </FieldCell>

            <FieldCell width="medium">
              <Field id="registration_number" label={t("Registration number")} hint={t("Optional.")}>
                <input
                  id="registration_number"
                  className={inputClass()}
                  {...form.register('registration_number')}
                />
              </Field>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        <FieldGroup
          legend="Where they are"
          hint={t("The country is what the tax rules are looked up by, so it decides the rate as much as the address decides the delivery.")}
        >
          <Field id="address_line1" label={t("Address")}>
            <input
              id="address_line1"
              className={inputClass()}
              {...form.register('address_line1')}
            />
          </Field>

          <Field id="address_line2" label={t("Address, continued")} hint={t("Optional.")}>
            <input
              id="address_line2"
              className={inputClass()}
              {...form.register('address_line2')}
            />
          </Field>

          <FieldRow>
            {/* Three questions that are one answer, at widths that say how much
                each of them wants: a postcode is not as long as a city. */}
            <FieldCell width="short">
              <Field id="postal_code" label={t("Postal code")}>
                <input
                  id="postal_code"
                  className={inputClass()}
                  {...form.register('postal_code')}
                />
              </Field>
            </FieldCell>

            <FieldCell>
              <Field id="city" label={t("City")}>
                <input id="city" className={inputClass()} {...form.register('city')} />
              </Field>
            </FieldCell>

            <FieldCell width="short">
              <Field
                id="country_code"
                label={t("Country")}
                error={form.formState.errors.country_code?.message}
              >
                <CountrySelect
                  id="country_code"
                  emptyLabel="Choose a country…"
                  invalid={form.formState.errors.country_code !== undefined}
                  {...form.register('country_code')}
                />
              </Field>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        <FieldGroup legend="Where the invoice is sent">
          <Field
            id="billing_email"
            label={t("Billing email")}
            error={form.formState.errors.billing_email?.message}
          >
            <input
              id="billing_email"
              type="email"
              className={inputClass(form.formState.errors.billing_email !== undefined)}
              {...form.register('billing_email')}
            />
          </Field>
        </FieldGroup>

        {save.error !== null && <ErrorSurface error={save.error} />}

        <FormActions
          note={
            save.isSuccess && (
              <p data-testid="saved" className="text-sm text-muted">
                {t("Saved. Invoices issued from now on will carry this.")}</p>
            )
          }
        >
          <Button type="submit" pending={save.isPending}>
            {t("Save")}</Button>
        </FormActions>
      </FormCard>
    </div>
  );
}
