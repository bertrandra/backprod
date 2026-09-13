import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { useViewState } from '@/app/frame/viewState';
import {
  useProductConfiguration,
  useSetBillingIdentity,
  useSetTaxSettings,
  type BillingSupplier,
  type TaxSettings,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

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
 * **Two forms, not one save button.** The issuer's identity and the supplier's
 * fiscal position are separate decisions with separate consequences — one is who
 * the document names, the other is which country's VAT it charges — and they are
 * recorded separately in the access log for that reason.
 */
export function InvoicingScreen() {
  const { selected } = useViewState();
  const productCode = selected ?? null;

  const configuration = useProductConfiguration(productCode);

  if (productCode === null || productCode === '') {
    return (
      <EmptyState
        title="No product chosen"
        description="An invoice is issued by the company behind one product, and the console has no default. Pick one from Products."
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

  if (configuration.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (configuration.error !== null) {
    return <ErrorSurface error={configuration.error} onRetry={() => void configuration.refetch()} />;
  }

  const { product, billing_supplier: supplier, tax, can_invoice: canInvoice, missing } = configuration.data;

  return (
    <div className="max-w-3xl space-y-8">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Invoicing</h1>
        <p className="text-sm text-muted">
          Who <strong>{product.name}</strong> invoices as, and under which VAT regime. Both are
          configuration and neither is guessed: an invoice is a legal document with a permanent
          number, so a product that cannot name its issuer refuses to raise one rather than issuing
          a blank.
        </p>
      </header>

      {canInvoice ? (
        <p
          data-testid="can-invoice"
          className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
        >
          This product can invoice. Every document it raises copies the identity below as it stood at
          that moment, so changing it later never rewrites an invoice already issued.
        </p>
      ) : (
        <p
          data-testid="cannot-invoice"
          role="alert"
          className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
        >
          This product cannot invoice yet, so a checkout against it refuses with{' '}
          <code>BILLING_NOT_CONFIGURED</code>. Missing: <strong>{missing.join(', ')}</strong>.
        </p>
      )}

      <BillingIdentityForm productCode={productCode} initial={supplier} />
      <TaxForm productCode={productCode} initial={tax} />
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
    <section className="space-y-3">
      <h2 className="text-xl font-semibold">The issuer</h2>
      <p className="text-sm text-muted">
        What appears on the document as the company issuing it. The legal name and the country are
        the two an invoice cannot be raised without — the country because it decides which VAT
        regime the document is issued under. A supplier under the <em>franchise en base</em> has no
        VAT number and invoices perfectly legally, so that field may stay empty.
      </p>

      {save.error !== null && <ErrorSurface error={save.error} />}

      <form
        className="grid gap-3 sm:grid-cols-2"
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
        <Field id="supplier-legal-name" label="Legal name">
          <input
            id="supplier-legal-name"
            className={inputClass()}
            placeholder="Atlas SAS"
            value={form.legal_name}
            onChange={set('legal_name')}
          />
        </Field>
        <Field id="supplier-country" label="Country" hint="Two letters, such as FR.">
          <input
            id="supplier-country"
            className={inputClass()}
            maxLength={2}
            placeholder="FR"
            value={form.country_code}
            onChange={set('country_code')}
          />
        </Field>
        <Field id="supplier-vat" label="VAT number">
          <input
            id="supplier-vat"
            className={inputClass()}
            placeholder="FR12345678901"
            value={form.vat_number}
            onChange={set('vat_number')}
          />
        </Field>
        <Field id="supplier-registration" label="Registration number" hint="SIREN or SIRET.">
          <input
            id="supplier-registration"
            className={inputClass()}
            placeholder="123 456 789 00012"
            value={form.registration_number}
            onChange={set('registration_number')}
          />
        </Field>
        <Field id="supplier-address1" label="Address">
          <input
            id="supplier-address1"
            className={inputClass()}
            value={form.address_line1}
            onChange={set('address_line1')}
          />
        </Field>
        <Field id="supplier-address2" label="Address, continued">
          <input
            id="supplier-address2"
            className={inputClass()}
            value={form.address_line2}
            onChange={set('address_line2')}
          />
        </Field>
        <Field id="supplier-postal-code" label="Postal code">
          <input
            id="supplier-postal-code"
            className={inputClass()}
            value={form.postal_code}
            onChange={set('postal_code')}
          />
        </Field>
        <Field id="supplier-city" label="City">
          <input
            id="supplier-city"
            className={inputClass()}
            value={form.city}
            onChange={set('city')}
          />
        </Field>

        <div className="sm:col-span-2">
          <Button type="submit" pending={save.isPending}>
            Save the issuer
          </Button>
        </div>
      </form>
    </section>
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
    <section className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">The tax position</h2>
      <p className="text-sm text-muted">
        Stated, never derived. What is being supplied decides where a sale is taxed, and whether the
        supplier is registered for the One Stop Shop decides how a sale to a consumer in another
        member state is treated. Getting one wrong files a VAT return in the wrong country.
      </p>

      {save.error !== null && <ErrorSurface error={save.error} />}

      <form
        className="grid gap-3 sm:grid-cols-2"
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
        <Field
          id="tax-country"
          label="Jurisdiction"
          hint="The country a VAT return is filed in — the supplier's, since that is the regime the invoice is issued under."
        >
          <input
            id="tax-country"
            className={inputClass()}
            maxLength={2}
            placeholder="FR"
            value={country}
            onChange={(event) => setCountry(event.target.value)}
          />
        </Field>
        <Field id="tax-currency" label="Currency" hint="Three letters, such as EUR.">
          <input
            id="tax-currency"
            className={inputClass()}
            maxLength={3}
            value={currency}
            onChange={(event) => setCurrency(event.target.value)}
          />
        </Field>
        <Field id="tax-supply" label="What is supplied">
          <select
            id="tax-supply"
            className={inputClass()}
            value={supply}
            onChange={(event) => setSupply(event.target.value as TaxSettings['supply_type'])}
          >
            <option value="DIGITAL_SERVICES">Digital services</option>
            <option value="SERVICES">Services</option>
            <option value="GOODS">Goods</option>
          </select>
        </Field>

        <div className="flex items-center">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={oss}
              onChange={(event) => setOss(event.target.checked)}
            />
            Registered for the One Stop Shop
          </label>
        </div>

        <div className="sm:col-span-2">
          <Button type="submit" pending={save.isPending}>
            Save the tax position
          </Button>
        </div>
      </form>
    </section>
  );
}
