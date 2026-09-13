import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useCalculateTax, useTaxRates, type TaxCalculation } from '@/queries/tax';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount, formatVatRate, minorUnitDigits } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `tax.rates` — the rates in force on a date, and the calculator that explains
 * itself.
 *
 * **A rate has a date.** Rates carry validity windows, so a correction closes
 * one window and opens another rather than restating an invoice already issued
 * (R7). Asking "what is the rate" without saying *when* is asking a question
 * with a hidden assumption, and the date control here is that assumption made
 * explicit — an invoice from March is checked against March's rates.
 *
 * **`POST /tax/calculate` is a diagnostic, not a quote** (§25.3). It answers
 * what *would* be applied and, more importantly, *why*: the rule that fired, the
 * regime it put the supply in, the customer status it read, and the reasons in
 * words. An invoice priced under a regime the customer disputes is a
 * conversation, and those are the fields that conversation needs. So the answer
 * below never renders as a number alone — the reasoning is the answer.
 */
const schema = z.object({
  amount: z
    .string()
    .trim()
    .regex(/^\d+([.,]\d{1,4})?$/, 'An amount, digits only — 129 or 129.90.'),
  currency: z
    .string()
    .trim()
    .regex(/^[A-Za-z]{3}$/, 'Three letters, as ISO 4217 defines them.'),
  supply_type: z.string().trim(),
});

type Values = z.infer<typeof schema>;

/**
 * Typed money, sent as the integer the API speaks.
 *
 * The field takes what a person writes — "129.90" — and the contract takes minor
 * units. The conversion is by the currency's own exponent rather than by 100,
 * for the reason `Money.tsx` gives: 100 is right for EUR and wrong for JPY.
 * Rounded, not truncated, so 0.005 does not silently become nothing.
 */
function toMinorUnits(amount: string, currency: string): number {
  const digits = minorUnitDigits(currency);

  return Math.round(Number(amount.replace(',', '.')) * 10 ** digits);
}

export function TaxRatesScreen() {
  // Not `?on=` in the URL: the view-state parser has no date field and inventing
  // one here would put a second, differently-validated parser in the
  // application. A date the person picked to check one figure is also not the
  // sort of state a link needs to carry.
  const [on, setOn] = useState('');
  const rates = useTaxRates(on === '' ? null : on);
  const calculate = useCalculateTax();

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { amount: '', currency: 'EUR', supply_type: '' },
  });

  return (
    <div className="max-w-3xl space-y-8">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Rates and regimes</h1>
        <p className="text-sm text-muted">
          Rates are valid for a window rather than forever. A change closes one window and opens
          another, so an invoice issued in the past is still explained by the rate that was in
          force then.
        </p>
      </header>

      <section className="space-y-4">
        <div className="flex flex-wrap items-end gap-3">
          <Field id="on" label="In force on" hint="Leave empty for today.">
            <input
              id="on"
              type="date"
              className={inputClass()}
              value={on}
              onChange={(event) => setOn(event.target.value)}
            />
          </Field>
          {on !== '' && (
            <Button type="button" variant="secondary" onClick={() => setOn('')}>
              Today
            </Button>
          )}
        </div>

        {rates.isPending ? (
          <SkeletonRows rows={5} />
        ) : rates.error !== null ? (
          <ErrorSurface error={rates.error} onRetry={() => void rates.refetch()} />
        ) : rates.data.rates.length === 0 ? (
          <EmptyState
            title="No rates in force on that date"
            description="Either the date is before the rates were recorded, or nothing was in force then."
          />
        ) : (
          <>
            <p data-testid="rates-as-of" className="text-sm text-muted">
              As they stood on {new Date(rates.data.on).toLocaleDateString()}.
            </p>

            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase tracking-wide text-subtle">
                  <tr>
                    <th className="py-1 pr-3">Country</th>
                    <th className="py-1 pr-3">Kind</th>
                    <th className="py-1 pr-3">Rate</th>
                    <th className="py-1 pr-3">In force</th>
                    <th className="py-1">Source</th>
                  </tr>
                </thead>
                <tbody>
                  {rates.data.rates.map((rate) => (
                    <tr
                      key={`${rate.country_code}-${rate.rate_kind}-${rate.valid_from}`}
                      data-rate={`${rate.country_code}:${rate.rate_kind}`}
                      className="border-t border-line"
                    >
                      <td className="py-1.5 pr-3">{rate.country_code}</td>
                      <td className="py-1.5 pr-3">{rate.rate_kind}</td>
                      <td data-testid="rate-value" className="py-1.5 pr-3 font-medium">
                        {formatVatRate(rate.basis_points)}
                      </td>
                      <td className="py-1.5 pr-3 text-xs text-muted">
                        {new Date(rate.valid_from).toLocaleDateString()} —{' '}
                        {rate.valid_until === null
                          ? 'still'
                          : new Date(rate.valid_until).toLocaleDateString()}
                      </td>
                      {/* Where the figure came from — a seed, not a fiscal
                          authority, and the contract is explicit about that. */}
                      <td className="py-1.5 text-xs text-subtle">{rate.source}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </section>

      <section className="space-y-4 border-t border-line pt-6">
        <div className="space-y-1">
          <h2 className="text-xl font-semibold">What would be applied, and why</h2>
          <p className="text-sm text-muted">
            A diagnostic. It charges nothing and creates nothing — it answers which rule your tax
            profile puts a supply under, and shows the reasoning it used to get there.
          </p>
        </div>

        <form
          className="grid gap-4 sm:grid-cols-3"
          onSubmit={(event) => {
            void form.handleSubmit((values) =>
              calculate.mutate({
                amount_minor_units: toMinorUnits(values.amount, values.currency),
                currency: values.currency.toUpperCase(),
                supply_type: values.supply_type.trim() === '' ? null : values.supply_type.trim(),
              }),
            )(event);
          }}
        >
          <Field id="amount" label="Amount" error={form.formState.errors.amount?.message}>
            <input
              id="amount"
              inputMode="decimal"
              className={inputClass(form.formState.errors.amount !== undefined)}
              {...form.register('amount')}
            />
          </Field>

          <Field id="currency" label="Currency" error={form.formState.errors.currency?.message}>
            <input
              id="currency"
              className={inputClass(form.formState.errors.currency !== undefined)}
              {...form.register('currency')}
            />
          </Field>

          <Field
            id="supply_type"
            label="Supply type"
            hint="Optional — what is supplied changes where it is taxed."
          >
            <input id="supply_type" className={inputClass()} {...form.register('supply_type')} />
          </Field>

          <div className="sm:col-span-3">
            <Button type="submit" pending={calculate.isPending}>
              Explain it
            </Button>
          </div>
        </form>

        {calculate.error !== null && <ErrorSurface error={calculate.error} />}

        {calculate.data !== undefined && <Explanation calculation={calculate.data} />}
      </section>
    </div>
  );
}

/**
 * The answer, with its reasoning.
 *
 * Every field the contract carries is rendered, because each answers a question
 * somebody actually asks: *which rule* (quotable in a support thread), *which
 * regime*, *which country taxes this*, *what status did you read me as*, and
 * *what does the invoice have to say* — `legal_mention` is the wording a regime
 * obliges the document to carry, and a screen that dropped it would be hiding
 * the one sentence with legal effect.
 */
function Explanation({ calculation }: { calculation: TaxCalculation }) {
  const base = { minor_units: calculation.taxable_base, currency: calculation.currency };
  const vat = { minor_units: calculation.vat_amount, currency: calculation.currency };

  return (
    <div
      data-testid="calculation"
      data-regime={calculation.regime}
      data-rule={calculation.rule_id}
      className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-baseline gap-3">
        <span data-testid="regime" className="rounded bg-well px-2 py-0.5 text-xs font-medium">
          {calculation.regime.replaceAll('_', ' ')}
        </span>
        <span data-testid="calculated-rate" className="font-medium">
          {formatVatRate(calculation.rate_basis_points)}
        </span>
        <span className="text-muted">
          taxed in {calculation.country_of_taxation}
        </span>
      </div>

      <dl className="grid gap-2 sm:grid-cols-2">
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">Taxable base</dt>
          <dd>
            <Amount money={base} />
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">VAT</dt>
          <dd>
            <Amount money={vat} />
          </dd>
        </div>
      </dl>

      <p data-testid="customer-status" className="text-muted">
        Read as <strong>{calculation.customer_tax_status}</strong>
        {calculation.reverse_charge && ' — you account for the VAT, not the supplier'}.
      </p>

      {/* Not decoration: this is the sentence the invoice is obliged to carry. */}
      {calculation.legal_mention !== null && (
        <p data-testid="legal-mention" className="rounded bg-well p-2 text-xs">
          The invoice must state: “{calculation.legal_mention}”
        </p>
      )}

      {calculation.reasons.length > 0 && (
        <div className="space-y-1">
          <p className="text-xs uppercase tracking-wide text-subtle">Why</p>
          <ul data-testid="reasons" className="list-inside list-disc text-muted">
            {calculation.reasons.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
        </div>
      )}

      <p className="text-xs text-subtle">
        Rule <code data-testid="rule-id">{calculation.rule_id}</code> — quote it if you disagree
        with this answer.
      </p>
    </div>
  );
}
