import { Link } from '@tanstack/react-router';
import { useEffect, useRef, useState } from 'react';

import {
  useProductConfiguration,
  useProductManifest,
  useSetBillingIdentity,
  useSetProjectSchemaVersions,
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
 * **Three forms, not one save button.** The issuer's identity, the supplier's
 * fiscal position and the document versions the product accepts are separate
 * decisions with separate consequences — who the document names, which country's
 * VAT it charges, and whether a customer's save is taken at all — and they are
 * recorded separately in the access log for that reason.
 *
 * **The third closed the same hole one layer down** (2026-09-29). Creating a
 * product writes a row in `products` and none in `product_configuration`, and a
 * product that has declared no schema versions accepts no project of any
 * version — so every product created here refused every project, and nothing on
 * the platform could change it. Exactly what ADR-042 found about the issuer.
 */
/**
 * The anchor of the accepted document versions, which the Products screen
 * links to: that list is where a product's saves are refused when a release
 * writes a version nobody added, and an operator looks for it on the product.
 */
export const DOCUMENT_VERSIONS = 'document-versions';

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
    project_schema_versions: schemaVersions,
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
      <SchemaVersionsForm productCode={productCode} initial={schemaVersions} />
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
/**
 * Which document versions the product takes (non-negotiable #10).
 *
 * **Chips and a number, not a comma-separated box.** A text field would make
 * the screen parse what the operator typed, and the one thing that must not
 * differ between here and the server is what counts as a version — `"2"` is
 * refused there precisely so a product never displays a version it refuses.
 * Entering one at a time leaves nothing to parse.
 *
 * The empty state gets an alert of its own rather than a quiet form, because it
 * is the state of every product the console has ever created and it looks
 * exactly like a screen that has finished loading.
 */
function SchemaVersionsForm({
  productCode,
  initial,
}: {
  productCode: string;
  initial: number[];
}) {
  const save = useSetProjectSchemaVersions(productCode);

  const [versions, setVersions] = useState<number[]>(initial);
  const [entry, setEntry] = useState('');
  const field = useRef<HTMLInputElement>(null);

  // Arriving from a product's "Document versions" button: this section is
  // drawn only once the configuration has loaded, which is after the router
  // has already looked for the anchor, so it brings itself into view and
  // puts the cursor where the version is typed.
  useEffect(() => {
    if (window.location.hash === `#${DOCUMENT_VERSIONS}`) {
      field.current?.scrollIntoView({ block: 'center' });
      field.current?.focus({ preventScroll: true });
    }
  }, []);

  const parsed = Number(entry);
  // What the server would take: a whole number of 1 or more that is not already
  // in the list. `Number('')` is 0 and `Number('2.5')` is not an integer, so
  // both fail here for the same reason they would fail there.
  const addable =
    entry.trim() !== '' && Number.isInteger(parsed) && parsed >= 1 && !versions.includes(parsed);

  function add() {
    if (!addable) {
      return;
    }

    setVersions([...versions, parsed].sort((left, right) => left - right));
    setEntry('');
  }

  return (
    <Section
      id={DOCUMENT_VERSIONS}
      className="scroll-mt-4 border-t border-line pt-6"
      title={t("Accepted document versions")}
      description={t("Which versions of this product's project documents the platform will store. A product that accepts none refuses every save, which is the state of one nobody has configured — creating a product writes no configuration at all.")}
    >
      {save.error !== null && <ErrorSurface error={save.error} />}

      <WhatTheProductDeclares
        productCode={productCode}
        versions={versions}
        onAdd={(adding) =>
          setVersions([...versions, ...adding].sort((left, right) => left - right))
        }
      />

      {versions.length === 0 && (
        <p data-testid="accepts-no-version" role="alert" className={notice('danger')}>
          {tx("This product accepts no document version, so every project saved against it refuses with {code}. Add the versions its releases write.", {
            code: <code>UNSUPPORTED_SCHEMA_VERSION</code>,
          })}
        </p>
      )}

      <FormCard
        onSubmit={(event) => {
          event.preventDefault();
          save.mutate(versions);
        }}
      >
        <FieldGroup
          legend="The versions"
          hint={t("Keep the versions earlier releases wrote, not only the newest: a document written last month is still one its owner opens, and dropping its version refuses their own work.")}
        >
          <ul data-testid="schema-versions" className="flex flex-wrap gap-2">
            {versions.map((version) => (
              <li
                key={version}
                data-version={version}
                className="flex items-center gap-2 rounded-control border border-line bg-well px-3 py-1.5 text-sm"
              >
                <span className="tabular-nums">{version}</span>
                <button
                  type="button"
                  aria-label={t("Remove version {version}", { version: String(version) })}
                  className="text-muted underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
                  onClick={() => setVersions(versions.filter((kept) => kept !== version))}
                >
                  {t("Remove")}</button>
              </li>
            ))}
          </ul>

          <FieldRow>
            <FieldCell width="short">
              <Field id="schema-version" label={t("Add a version")}>
                <input
                  ref={field}
                  id="schema-version"
                  type="number"
                  min={1}
                  step={1}
                  inputMode="numeric"
                  className={inputClass()}
                  value={entry}
                  onChange={(event) => setEntry(event.target.value)}
                  onKeyDown={(event) => {
                    // Enter adds the version rather than submitting the form:
                    // typing a number and pressing Enter must not save a list
                    // that does not yet contain it.
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      add();
                    }
                  }}
                />
              </Field>
            </FieldCell>

            <FieldCell>
              <Button type="button" variant="secondary" disabled={!addable} onClick={add}>
                {t("Add")}</Button>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        <FormActions>
          {/* Disabled on an empty list as a courtesy only — the server refuses
              it either way, and the frontend is never the authority. */}
          <Button type="submit" pending={save.isPending} disabled={versions.length === 0}>
            {t("Save the accepted versions")}</Button>
        </FormActions>
      </FormCard>
    </Section>
  );
}

/**
 * What the product itself says it accepts.
 *
 * The list above is a copy, made by hand, of a fact the product owns — it
 * writes the migrations and ships the spec. The copy has fallen behind twice,
 * and both times every save was refused `UNSUPPORTED_SCHEMA_VERSION` and it
 * looked like a bug in the product. This is where the two can be seen at once.
 *
 * **It proposes and never applies.** Pressing the button puts the missing
 * versions into the list above, and saving is still the form's own button — so
 * what gets stored is what the operator can see, and a remote file never
 * decides a list the platform is the authority over. It is also why nothing
 * here offers to *remove* a version the product no longer names: retiring one
 * refuses edits on documents customers already hold, which is a decision
 * somebody makes rather than one a fetch suggests.
 *
 * **Silence is the ordinary answer.** Most products serve no manifest, and a
 * product that runs inside this shell has no address to ask. Neither is a
 * fault, and showing a red failure for each would teach an operator to ignore
 * the one that matters — so only `WRONG_PRODUCT` is called out, because it
 * means an `app_url` is pointing at somebody else.
 */
function WhatTheProductDeclares({
  productCode,
  versions,
  onAdd,
}: {
  productCode: string;
  versions: number[];
  onAdd: (adding: number[]) => void;
}) {
  const asked = useProductManifest(productCode);

  if (asked.isPending) {
    return <SkeletonRows rows={1} />;
  }

  // The request itself failed — not the product's silence, which arrives as a
  // 200 with an `error` code. A console whose own call broke says so.
  if (asked.error !== null || asked.data === undefined) {
    return <ErrorSurface error={asked.error} />;
  }

  const { declared, error, adds, product } = asked.data;

  if (declared === null) {
    return (
      <p
        data-testid="product-declares-nothing"
        className={notice(error === 'WRONG_PRODUCT' ? 'danger' : 'neutral')}
      >
        {error === 'WRONG_PRODUCT'
          ? tx("The host at {url} serves a manifest for a different product. Check this product's address before trusting anything else on this screen.", {
              url: <code>{product.app_url ?? ''}</code>,
            })
          : t("This product does not say which versions it accepts. The list below is the only answer, and it has to be kept by hand.")}{' '}
        <RefetchManifest asked={asked} />
      </p>
    );
  }

  // Already in the list the operator is editing, which is not the same as
  // already stored: they may have added it a moment ago and not saved yet.
  const outstanding = adds.filter((version) => !versions.includes(version));

  return (
    <div data-testid="product-manifest" className={notice(outstanding.length > 0 ? 'warning' : 'neutral')}>
      <p>
        {tx("{name} declares {versions}{release}.", {
          name: <strong>{product.name}</strong>,
          versions: <span data-testid="declared-versions">{declared.schema_versions.join(', ')}</span>,
          release: declared.app_version === null ? '' : ` (${declared.app_version})`,
        })}{' '}
        {outstanding.length === 0
          ? t("The list already covers it.")
          : t("The list below does not cover all of it, so a save in a version it is missing is refused.")}{' '}
        <RefetchManifest asked={asked} />
      </p>

      {outstanding.length > 0 && (
        <p className="mt-3">
          <Button
            type="button"
            variant="secondary"
            data-testid="adopt-declared-versions"
            onClick={() => onAdd(outstanding)}
          >
            {t("Add {versions} to the list", { versions: outstanding.join(', ') })}</Button>
        </p>
      )}
    </div>
  );
}

/** Asking again, for the minute after a release of the product goes out. */
function RefetchManifest({ asked }: { asked: ReturnType<typeof useProductManifest> }) {
  return (
    <button
      type="button"
      data-testid="ask-the-product-again"
      disabled={asked.isFetching}
      className="text-muted underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
      onClick={() => void asked.refetch()}
    >
      {asked.isFetching ? t("Asking…") : t("Ask again")}</button>
  );
}

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
 * due on the day the period starts, not the day it is raised, and a period never
 * rolls past the subscription's term — renewing a commitment stays a decision.
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
              {t("Off, every subscription ends at its period and nothing is invoiced. A period never rolls past the subscription's term: renewing a commitment stays a decision.")}</span>
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
