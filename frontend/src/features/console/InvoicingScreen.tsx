import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import {
  useProductConfiguration,
  useSetBillingIdentity,
  useSetRenewal,
  useSetTaxSettings,
  type BillingSupplier,
  type RenewalSettings,
  type TaxSettings,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { CountrySelect, CurrencySelect } from '@/ui/pickers/Select';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice } from '@/ui/tone';
import { PageHeader, Section } from '@/ui/Page';
import { useSessionStore } from '@/state/session';
import { FieldCell, FieldGroup, FieldRow, FormActions, FormCard } from '@/ui/Form';
import { t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * `console.admin.invoicing` — what a product needs configured before it can take
 * money, and the screen that was missing under the last two.
 *
 * ADR-042 gave the console a way to create a product and ADR-043 a way to price
 * it. A checkout against a product created that way still refused:
 * `BILLING_NOT_CONFIGURED`, because an invoice must name its issuer (§25) and
 * the issuer lives in `product_configuration` — a table exactly one thing on
 * this platform ever wrote, `bin/seed-demo.php`. So the products that could
 * invoice were the demo's and the installer's, and a product an administrator
 * created could be priced, advertised and bought right up to the moment it had
 * to raise a document.
 *
 * **The warning names the fields.** A screen that said only "not configured"
 * would leave somebody comparing a form against a specification; `missing` comes
 * from the same rule the invoice path applies, so what it lists is exactly what
 * the checkout is refusing over.
 *
 * **Separate forms, not one save button.** The issuer's identity, the
 * supplier's fiscal position and the renewal are separate decisions with
 * separate consequences — who the document names, which country's VAT it
 * charges, when the next period is billed — and each is saved on its own for
 * that reason.
 *
 * **The document versions a product accepts were here until 2026-11-20** and
 * are on the product's own row now (`ProductDocumentVersions`). They are not
 * invoicing, and an operator whose product refused every save looked for them
 * on the product and not here.
 */
export function InvoicingScreen() {
  const productCode = useSessionStore((state) => state.productCode);

  const configuration = useProductConfiguration(productCode);

  if (productCode === null || productCode === '') {
    return (
      <EmptyState
        title={t("No product chosen")}
        description={t("An invoice is issued by the company behind one product. Choose one in the bar above — the switcher there lists every product the platform hosts.")}
        action={
          <Link
            to="/console/products"
            className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            {t("Go to Products")}</Link>
        }
      />
    );
  }

  if (configuration.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (configuration.error !== null) {
    return <ErrorSurface error={configuration.error} onRetry={() => void configuration.refetch()} />;
  }

  const {
    product,
    billing_supplier: supplier,
    tax,
    renewal,
    can_invoice: canInvoice,
    missing,
  } = configuration.data;

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={t("Invoicing")}
        description={tx("Who {product} invoices as, and under which VAT regime. Both are configuration and neither is guessed: an invoice is a legal document with a permanent number, so a product that cannot name its issuer refuses to raise one rather than issuing a blank.", { product: <strong>{product.name}</strong> })}
      />

      {canInvoice ? (
        <p
          data-testid="can-invoice"
          className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
        >
          {t("This product can invoice. Every document it raises copies the identity below as it stood at that moment, so changing it later never rewrites an invoice already issued.")}</p>
      ) : (
        <p
          data-testid="cannot-invoice"
          role="alert"
          className={notice('danger')}
        >
          {tx("This product cannot invoice yet, so a checkout against it refuses with {code}. Missing: {missing}.", {
            code: <code>BILLING_NOT_CONFIGURED</code>,
            missing: <strong>{missing.join(', ')}</strong>,
          })}
        </p>
      )}

      <BillingIdentityForm productCode={productCode} initial={supplier} />
      <TaxForm productCode={productCode} initial={tax} />
      <RenewalForm productCode={productCode} initial={renewal} />
    </div>
  );
}

/**
 * The mandatory mentions of a French invoice (§25).
 *
 * Every field is sent every time, because the endpoint is a PUT: a supplier that
 * stops being liable for VAT has to be able to *remove* its number, and under
 * "omitted means leave it" removing anything would be impossible.
 */
function BillingIdentityForm({
  productCode,
  initial,
}: {
  productCode: string;
  initial: BillingSupplier;
}) {
  const save = useSetBillingIdentity(productCode);

  // One piece of state for the whole document rather than eight, so the PUT
  // carries what the form shows and not a field the last render missed.
  const [form, setForm] = useState<Record<string, string>>({
    legal_name: initial.legal_name ?? '',
    vat_number: initial.vat_number ?? '',
    registration_number: initial.registration_number ?? '',
    address_line1: initial.address_line1 ?? '',
    address_line2: initial.address_line2 ?? '',
    postal_code: initial.postal_code ?? '',
    city: initial.city ?? '',
    country_code: initial.country_code ?? '',
  });

  const set = (field: string) => (event: { target: { value: string } }) =>
    setForm((previous) => ({ ...previous, [field]: event.target.value }));

  // Blank is how a field is cleared, so it travels as null rather than as "".
  const cleared = (value: string) => (value.trim() === '' ? null : value.trim());

  return (
    <Section
      title={t("The issuer")}
      description={
        tx("What appears on the document as the company issuing it. The legal name and the country are the two an invoice cannot be raised without — the country because it decides which VAT regime the document is issued under. A supplier under the {regime} has no VAT number and invoices perfectly legally, so that field may stay empty.", {
          regime: <em>{t("franchise en base")}</em>,
        })
      }
    >
      {save.error !== null && <ErrorSurface error={save.error} />}

      <FormCard
        onSubmit={(event) => {
          event.preventDefault();

          save.mutate({
            legal_name: cleared(form.legal_name ?? ''),
            vat_number: cleared(form.vat_number ?? ''),
            registration_number: cleared(form.registration_number ?? ''),
            address_line1: cleared(form.address_line1 ?? ''),
            address_line2: cleared(form.address_line2 ?? ''),
            postal_code: cleared(form.postal_code ?? ''),
            city: cleared(form.city ?? ''),
            country_code: cleared(form.country_code ?? ''),
          });
        }}
      >
        <FieldGroup
          legend="Required"
          hint={t("Without both of these a checkout reaches its last step and refuses with BILLING_NOT_CONFIGURED.")}
        >
          <FieldRow>
            <FieldCell>
              <Field id="supplier-legal-name" label={t("Legal name")}>
                <input
                  id="supplier-legal-name"
                  className={inputClass()}
                  placeholder="Atlas SAS"
                  value={form.legal_name}
                  onChange={set('legal_name')}
                />
              </Field>
            </FieldCell>

            <FieldCell width="short">
              <Field id="supplier-country" label={t("Country")}>
                <CountrySelect
                  id="supplier-country"
                  emptyLabel="Choose a country…"
                  value={form.country_code}
                  onChange={set('country_code')}
                />
              </Field>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        <FieldGroup
          legend="Registrations"
          hint={t("Both optional. A supplier under the franchise en base has neither and invoices legally.")}
        >
          <FieldRow>
            <FieldCell width="medium">
              <Field id="supplier-vat" label={t("VAT number")}>
                <input
                  id="supplier-vat"
                  className={inputClass()}
                  placeholder="FR12345678901"
                  value={form.vat_number}
                  onChange={set('vat_number')}
                />
              </Field>
            </FieldCell>

            <FieldCell width="medium">
              <Field
                id="supplier-registration"
                label={t("Registration number")}
                hint={t("SIREN or SIRET.")}
              >
                <input
                  id="supplier-registration"
                  className={inputClass()}
                  placeholder="123 456 789 00012"
                  value={form.registration_number}
                  onChange={set('registration_number')}
                />
              </Field>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        {/* Not "Address": that is the label of the first field inside it, and a
            legend repeating its own first child says nothing twice. */}
        <FieldGroup legend="Where the issuer is">
          <Field id="supplier-address1" label={t("Address")}>
            <input
              id="supplier-address1"
              className={inputClass()}
              value={form.address_line1}
              onChange={set('address_line1')}
            />
          </Field>

          <Field id="supplier-address2" label={t("Address, continued")}>
            <input
              id="supplier-address2"
              className={inputClass()}
              value={form.address_line2}
              onChange={set('address_line2')}
            />
          </Field>

          <FieldRow>
            <FieldCell width="short">
              <Field id="supplier-postal-code" label={t("Postal code")}>
                <input
                  id="supplier-postal-code"
                  className={inputClass()}
                  value={form.postal_code}
                  onChange={set('postal_code')}
                />
              </Field>
            </FieldCell>

            <FieldCell>
              <Field id="supplier-city" label={t("City")}>
                <input
                  id="supplier-city"
                  className={inputClass()}
                  value={form.city}
                  onChange={set('city')}
                />
              </Field>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        <FormActions>
          <Button type="submit" pending={save.isPending}>
            {t("Save the issuer")}</Button>
        </FormActions>
      </FormCard>
    </Section>
  );
}

/**
 * The supplier's own fiscal position, which §25.3 keeps a human decision.
 *
 * `oss_registered` is the one that matters most: it decides how a cross-border
 * consumer sale is taxed, and crossing the distance-selling threshold is a dated
 * event that changes the regime of *subsequent* sales only. Deriving it from
 * turnover would retroactively restate invoices already issued.
 */
function TaxForm({ productCode, initial }: { productCode: string; initial: TaxSettings }) {
  const save = useSetTaxSettings(productCode);

  const [country, setCountry] = useState(initial.country);
  const [currency, setCurrency] = useState(initial.currency);
  const [supply, setSupply] = useState<TaxSettings['supply_type']>(initial.supply_type);
  const [oss, setOss] = useState(initial.oss_registered);

  return (
    <Section
      className="border-t border-line pt-6"
      title={t("The tax position")}
      description={t("Stated, never derived. What is being supplied decides where a sale is taxed, and whether the supplier is registered for the One Stop Shop decides how a sale to a consumer in another member state is treated. Getting one wrong files a VAT return in the wrong country.")}
    >
      {save.error !== null && <ErrorSurface error={save.error} />}

      <FormCard
        onSubmit={(event) => {
          event.preventDefault();

          save.mutate({
            country: country.trim().toUpperCase(),
            currency: currency.trim().toUpperCase(),
            supply_type: supply,
            oss_registered: oss,
          });
        }}
      >
        <FieldGroup legend="Where and in what">
          <FieldRow>
            <FieldCell>
              <Field
                id="tax-country"
                label={t("Jurisdiction")}
                hint={t("The country a VAT return is filed in — the supplier's, since that is the regime the invoice is issued under.")}
              >
                <CountrySelect
                  id="tax-country"
                  emptyLabel="Choose a country…"
                  value={country}
                  onChange={(event) => setCountry(event.target.value)}
                />
              </Field>
            </FieldCell>

            <FieldCell width="short">
              <Field id="tax-currency" label={t("Currency")}>
                <CurrencySelect
                  id="tax-currency"
                  emptyLabel="Choose a currency…"
                  value={currency}
                  onChange={(event) => setCurrency(event.target.value)}
                />
              </Field>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        <FieldGroup
          legend="What is being sold"
          hint={t("These two together decide the rule a cross-border consumer sale falls under.")}
        >
          <Field id="tax-supply" label={t("What is supplied")}>
            <select
              id="tax-supply"
              className={inputClass()}
              value={supply}
              onChange={(event) => setSupply(event.target.value as TaxSettings['supply_type'])}
            >
              <option value="DIGITAL_SERVICES">{t("Digital services")}</option>
              <option value="SERVICES">{t("Services")}</option>
              <option value="GOODS">{t("Goods")}</option>
            </select>
          </Field>

          {/* A checkbox with its consequence beside it, not a bare label. What
              this decides is invisible until a sale crosses a border, which is
              the worst moment to find out it was left unticked. */}
          <label className="flex items-start gap-2.5 rounded-control border border-line bg-well p-3 text-sm">
            <input
              type="checkbox"
              className="mt-0.5 size-4 shrink-0"
              checked={oss}
              onChange={(event) => setOss(event.target.checked)}
            />
            <span>
              {t("Registered for the One Stop Shop")}<span className="mt-0.5 block text-xs text-muted">
                {t("Crossing the distance-selling threshold changes the regime of later sales only, so this is a dated decision and never read back from turnover.")}</span>
            </span>
          </label>
        </FieldGroup>

        <FormActions>
          <Button type="submit" pending={save.isPending}>
            {t("Save the tax position")}</Button>
        </FormActions>
      </FormCard>
    </Section>
  );
}

/**
 * Whether subscriptions renew by themselves, and how early the customer is asked
 * to pay (2026-10-05).
 *
 * The operator's words: the payment request goes out J-7, or however many days
 * they choose, as a mail with a link to the invoice, and the customer pays it by
 * card or any other means. The renewal pass had read this setting since ADR-068
 * and nothing could write it.
 *
 * Said on the form, because it is the part nobody would guess: the invoice is
 * due on the day the period starts, not the day it is raised, and renewal is
 * the baseline (ADR-071): on until switched off, and at the term a new term,
 * once the customer's renewal notice has run its days.
 */
function RenewalForm({ productCode, initial }: { productCode: string; initial: RenewalSettings }) {
  const save = useSetRenewal(productCode);

  const [automatic, setAutomatic] = useState(initial.automatic);
  const [lead, setLead] = useState(String(initial.lead_days));

  return (
    <Section
      className="border-t border-line pt-6"
      title={t("Renewal and the payment request")}
      description={t("How a paid period continues. The next period is invoiced a number of days before the current one ends, and the customer is mailed a request to pay it, with a link to the invoice — by card or any other means. The invoice is due on the day the new period starts; unpaid after that, the collection schedule takes over.")}
    >
      {save.error !== null && <ErrorSurface error={save.error} />}

      <FormCard
        onSubmit={(event) => {
          event.preventDefault();

          save.mutate({ automatic, lead_days: Number(lead) });
        }}
      >
        <label className="flex items-start gap-2.5 rounded-control border border-line bg-well p-3 text-sm">
          <input
            type="checkbox"
            data-testid="renewal-automatic"
            className="mt-0.5 size-4 shrink-0"
            checked={automatic}
            onChange={(event) => setAutomatic(event.target.checked)}
          />
          <span>
            {t("Renew each paid period and ask for payment")}<span className="mt-0.5 block text-xs text-muted">
              {t("On unless you switch it off. Off, every subscription ends at its period and nothing is invoiced. At the end of its term, an offer sold as renewing starts a new term, once the customer's renewal notice has run its days.")}</span>
          </span>
        </label>

        <Field
          id="renewal-lead"
          label={t("Days before the period ends")}
          hint={t("When the invoice and the payment request go out. Between 1 and 30; 7 by default.")}
        >
          <input
            id="renewal-lead"
            type="number"
            min={1}
            max={30}
            required
            className={inputClass()}
            value={lead}
            onChange={(event) => setLead(event.target.value)}
          />
        </Field>

        <FormActions>
          <Button type="submit" pending={save.isPending}>
            {t("Save the renewal setting")}</Button>
        </FormActions>
      </FormCard>
    </Section>
  );
}
