import { useState } from 'react';

import { can } from '@/app/access/access';
import {
  useCancelInvoice,
  useInvoice,
  useInvoicePdf,
  useIssueCreditNote,
  useMarkInvoicePaid,
} from '@/queries/billing';
import { progressOf, TRANSMISSION_STATES, useSubmitInvoice, useTransmissions } from '@/queries/einvoicing';
import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';
import { useStartPayment, type StartedPayment } from '@/queries/payments';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { LineOfferSummary } from '@/ui/LineOffer';
import { Amount, formatVatRate } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { pill, type Tone } from '@/ui/tone';
import { Table, TBody, Td, Th, THead, TR } from '@/ui/Table';
import { partyName, partyOrganisation, partyPerson } from '@/ui/party';
import { Whose } from '@/ui/Whose';

import { InvoiceNumber, statusTone } from './InvoicesScreen';
import { currentLocale, t } from '@/i18n';

/**
 * One invoice: the document, its money, and everything that can happen to it.
 *
 * **Every amount and every rate is rendered from the document**, which is the
 * same snapshot the stored PDF was rendered from (ADR-035) — so the screen and
 * the paper agree by construction rather than by coincidence. Line nets, the
 * per-jurisdiction tax rows and the totals are all the server's numbers; nothing
 * here adds two of them together.
 *
 * **A final invoice is corrected, never edited.** Crediting produces its own
 * document with its own number; cancelling is offered only while the invoice can
 * still change. There is no edit affordance anywhere on this screen, because the
 * contract has no operation that would change an issued invoice's terms.
 *
 * The parties are shown as they were **at issue** — copied onto the document,
 * not referenced — so a later change of address does not rewrite an invoice
 * already sent.
 */
export function InvoiceScreen({ invoiceId }: { invoiceId: string }) {
  const { data: session } = useSession();
  const invoice = useInvoice(invoiceId);
  const cancel = useCancelInvoice();
  const markPaid = useMarkInvoicePaid();
  const credit = useIssueCreditNote(invoiceId);
  const startPayment = useStartPayment(invoiceId);

  // Held for exactly as long as this render: the card form is offered where
  // the secret was born and nowhere else (ADR-048).
  const [started, setStarted] = useState<StartedPayment | null>(null);
  const [reason, setReason] = useState('');
  const [confirming, setConfirming] = useState<'cancel' | 'credit' | null>(null);

  const mayManage = can(session, 'billing.manage');
  // Paying is its own permission (2026-09-18): a USER pays the
  // organisation's invoice; marking it paid by hand, cancelling and
  // crediting stay the administrator's.
  const mayPay = can(session, 'billing.pay');

  if (invoice.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (invoice.error !== null) {
    return <ErrorSurface error={invoice.error} onRetry={() => void invoice.refetch()} />;
  }

  const current = invoice.data;
  const issued = current.number !== null;

  return (
    <div className="max-w-3xl space-y-8">
      <header className="space-y-2">
        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-2xl font-semibold">{t("Invoice")}</h1>
          <span
            data-testid="invoice-status"
            className={pill(statusTone(current.status))}
          >
            {current.status}
          </span>
          <InvoiceNumber invoice={current} />
          {!current.final && (
            <span data-testid="not-final" className="text-xs text-subtle">
              {t("can still change")}</span>
          )}
        </div>

        {current.period_start !== null && current.period_end !== null && (
          <p className="text-xs text-subtle">
            {t("covers")}{' '}{new Date(current.period_start).toLocaleDateString(currentLocale())} —{' '}
            {new Date(current.period_end).toLocaleDateString(currentLocale())}
          </p>
        )}
      </header>

      <Parties invoice={current} />

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">{t("Lines")}</h2>

        <Table caption={t("Invoice lines: description, quantity, unit price, net, VAT and gross")}>
          <THead>
            <Th>#</Th>
            <Th>{t("Description")}</Th>
            <Th numeric>{t("Qty")}</Th>
            <Th numeric>{t("Unit")}</Th>
            <Th numeric>{t("Net")}</Th>
            <Th numeric>{t("VAT")}</Th>
            <Th numeric>{t("Gross")}</Th>
          </THead>

          <TBody>
            {current.lines.map((line) => (
              <TR key={line.position} data-line={line.position}>
                <Td className="text-xs text-subtle">{line.position}</Td>
                <Td>
                  <LineOfferSummary line={line} />
                </Td>
                <Td numeric>{line.quantity}</Td>
                <Td numeric>
                  <Amount money={line.unit_price} />
                </Td>
                <Td numeric>
                  <Amount money={line.net} />
                </Td>
                <Td numeric>
                  {/* The rate as basis points, rendered — and the amount the
                      server computed from it, not a multiplication here. */}
                  <span data-testid={`rate-${String(line.position)}`} className="text-muted">
                    {formatVatRate(line.vat_rate_basis_points)}
                  </span>{' '}
                  <Amount money={line.vat} />
                </Td>
                <Td numeric className="font-medium">
                  <Amount money={line.gross} />
                </Td>
              </TR>
            ))}
          </TBody>
        </Table>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">{t("Tax")}</h2>

        {/* One row per jurisdiction and rate. They sum to the invoice's VAT —
            the backend asserts it, and this renders both rather than checking. */}
        <ul className="space-y-1 text-sm">
          {current.taxes.map((tax, index) => (
            <li key={`${tax.jurisdiction}-${String(tax.rate_basis_points)}-${String(index)}`}
                data-tax={tax.jurisdiction}
                className="flex flex-wrap gap-2">
              <span className="min-w-0 flex-1">
                {tax.jurisdiction} {t("at")}{' '}{formatVatRate(tax.rate_basis_points)}
              </span>
              <span className="text-muted">
                {t("on")}{' '}<Amount money={tax.taxable} />
              </span>
              <Amount money={tax.tax} />
            </li>
          ))}
        </ul>

        <dl className="grid grid-cols-3 gap-3 border-t border-line pt-3 text-sm">
          <div>
            <dt className="text-xs text-subtle">{t("Net")}</dt>
            <dd>
              <Amount money={current.net} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-subtle">{t("VAT")}</dt>
            <dd>
              <Amount money={current.vat} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-subtle">{t("Total")}</dt>
            <dd className="font-medium">
              <Amount money={current.gross} />
            </dd>
          </div>
        </dl>
      </section>

      <InvoiceDocument invoiceId={invoiceId} issued={issued} />

      {(mayManage || mayPay) && (
        <section className="space-y-3 border-t border-line pt-6">
          <h2 className="text-xl font-semibold">{t("Actions")}</h2>

          {cancel.error !== null && <ErrorSurface error={cancel.error} />}
          {markPaid.error !== null && <ErrorSurface error={markPaid.error} />}
          {credit.error !== null && <ErrorSurface error={credit.error} />}
          {startPayment.error !== null && <ErrorSurface error={startPayment.error} />}

          <div className="flex flex-wrap gap-2">
            {current.status === 'ISSUED' && mayManage && (
              <Button
                type="button"
                variant="secondary"
                pending={markPaid.isPending}
                onClick={() => markPaid.mutate(invoiceId)}
              >
                {t("Mark paid")}</Button>
            )}

            {current.status === 'ISSUED' && mayPay && started === null && (
              <Button
                type="button"
                pending={startPayment.isPending}
                onClick={() => startPayment.mutate(undefined, { onSuccess: setStarted })}
              >
                {t("Take a payment")}</Button>
            )}

            {/* Offered only while the document can still change. Once final it
                is corrected by a credit note instead. */}
            {!current.final && mayManage && confirming !== 'cancel' && (
              <Button type="button" variant="danger" onClick={() => setConfirming('cancel')}>
                {t("Cancel the invoice…")}</Button>
            )}

            {current.final && mayManage && confirming !== 'credit' && (
              <Button type="button" variant="secondary" onClick={() => setConfirming('credit')}>
                {t("Issue a credit note…")}</Button>
            )}
          </div>

          {started !== null && (
            <PaymentElementPanel
              provider={started.payment_provider}
              clientSecret={started.client_secret}
              amount={current.gross}
              returnUrl={window.location.href}
              // The invoice and the payments re-read; whether it succeeded is
              // the webhook's to say.
              onSettled={() => {
                setStarted(null);
                void invoice.refetch();
              }}
            />
          )}

          {confirming === 'cancel' && (
            <div className="flex flex-wrap gap-2">
              <span className="w-full text-xs text-muted">
                {t("Cancelling cannot be undone.")}</span>
              <Button
                type="button"
                variant="danger"
                pending={cancel.isPending}
                onClick={() =>
                  cancel.mutate(invoiceId, { onSettled: () => setConfirming(null) })
                }
              >
                {t("Cancel it")}</Button>
              <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                {t("Keep it")}</Button>
            </div>
          )}

          {confirming === 'credit' && (
            <div className="max-w-md space-y-3">
              <p className="text-xs text-muted">
                {t("A credit note is its own document, with its own number. The invoice keeps its number and its totals.")}</p>
              <Field id="reason" label={t("Reason")} hint={t("Optional, and kept on the document.")}>
                <input
                  id="reason"
                  className={inputClass()}
                  value={reason}
                  onChange={(event) => setReason(event.target.value)}
                />
              </Field>
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  pending={credit.isPending}
                  onClick={() =>
                    credit.mutate(reason === '' ? undefined : reason, {
                      onSuccess: () => setReason(''),
                      onSettled: () => setConfirming(null),
                    })
                  }
                >
                  {t("Issue the credit note")}</Button>
                <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                  {t("Not now")}</Button>
              </div>
            </div>
          )}
        </section>
      )}

      <Transmissions invoiceId={invoiceId} issued={issued} mayManage={mayManage} />
    </div>
  );
}

/**
 * The parties, as they were at issue.
 *
 * `PartySnapshot` is `additionalProperties: true` — a copy of whatever the party
 * was, not a typed reference — so this reads the fields it knows and shows
 * nothing where there is nothing, rather than asserting a shape the contract
 * deliberately does not fix.
 */
function Parties({ invoice }: { invoice: { supplier: Record<string, unknown>; customer: Record<string, unknown> } }) {
  return (
    <section className="grid gap-4 text-sm sm:grid-cols-2">
      <Party label={t("From")} party={invoice.supplier} testId="supplier" />
      <Party label={t("To")} party={invoice.customer} testId="customer" />
    </section>
  );
}

function Party({
  label,
  party,
  testId,
}: {
  label: string;
  party: Record<string, unknown>;
  testId: string;
}) {
  const text = (key: string): string | null => {
    const value = party[key];

    return typeof value === 'string' && value !== '' ? value : null;
  };

  return (
    <div data-testid={testId}>
      <p className="text-xs uppercase tracking-wide text-subtle">{label}</p>
      <p className="font-medium">{partyName(party) ?? '—'}</p>
      {/* A seat's invoice is the organisation's, for one of its people
          (§13.1): the person it is for, as recorded when it was issued. */}
      <Person party={party} />
      {text('vat_number') !== null && (
        <p className="text-xs text-muted">
          {t("VAT")}{' '}{text('vat_number')}
        </p>
      )}
      {text('address_line1') !== null && (
        <p className="text-xs text-muted">
          {text('address_line1')}
          {text('postal_code') !== null && `, ${text('postal_code') ?? ''}`}
          {text('city') !== null && ` ${text('city') ?? ''}`}
          {text('country_code') !== null && ` (${text('country_code') ?? ''})`}
        </p>
      )}
      <p className="text-xs text-subtle">{t("as recorded when this was issued")}</p>
    </div>
  );
}

function Person({ party }: { party: Record<string, unknown> }) {
  const person = partyPerson(party);
  const organisation = partyOrganisation(party);

  // No person, no line: `organisation` only ever travels beside one, because
  // it is the seller of a seat and a seat is sold to somebody.
  if (person === null) {
    return null;
  }

  // Since 2026-09-19 the person *is* the customer of a seat's invoice —
  // their name stands as `legal_name` above, so only the address goes here —
  // and the organisation they belong to is named beside them, sold to by
  // that organisation.
  return (
    <Whose
      name={null}
      email={person?.email ?? null}
      organisation={organisation}
      testId="customer-person"
    />
  );
}

/**
 * The stored PDF.
 *
 * Fetched through the generated client and turned into an object URL, because
 * the document is private to the tenant and the API authorises every read — there
 * is no signed link the way there is for an asset. It is cached forever: the
 * bytes are rendered once at issue and their ETag never changes (ADR-035).
 *
 * The URL is revoked when this unmounts. An object URL that is never revoked
 * keeps the whole blob in memory for the life of the tab.
 */
function InvoiceDocument({ invoiceId, issued }: { invoiceId: string; issued: boolean }) {
  const pdf = useInvoicePdf(invoiceId, issued);

  if (!issued) {
    return (
      <section className="space-y-2">
        <h2 className="text-xl font-semibold">{t("Document")}</h2>
        {/* Not an error: an invoice with no number has no document, and asking
            for one answers 409 INVOICE_NOT_RENDERABLE. */}
        <p data-testid="no-document" className="text-sm text-muted">
          {t("There is no document yet. One is rendered when the invoice is issued, and then never again — the stored file is the invoice.")}</p>
      </section>
    );
  }

  const blob = pdf.data;

  return (
    <section className="space-y-2">
      <h2 className="text-xl font-semibold">{t("Document")}</h2>

      {pdf.isPending ? (
        <SkeletonRows rows={1} />
      ) : pdf.error !== null ? (
        <ErrorSurface error={pdf.error} onRetry={() => void pdf.refetch()} />
      ) : blob === undefined ? (
        <SkeletonRows rows={1} />
      ) : (
        <Button
          type="button"
          variant="secondary"
          data-testid="pdf-download"
          onClick={() => saveBlob(blob, `invoice-${invoiceId}.pdf`)}
        >
          {t("Download the PDF")}</Button>
      )}

      <p className="text-xs text-subtle">
        {t("Rendered once when the invoice was issued and stored — these are the same bytes every time, which is why they are fetched once and kept.")}</p>
    </section>
  );
}

/**
 * Hands a blob to the browser as a download.
 *
 * The object URL is created at the moment somebody asks and revoked immediately
 * afterwards. Creating it during render would leak one per render under
 * StrictMode, and holding it in state for the life of the page keeps the whole
 * document in memory for a button that may never be pressed.
 */
function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');

  anchor.href = url;
  anchor.download = filename;
  anchor.click();

  URL.revokeObjectURL(url);
}

/**
 * The four transmission states, as a progression.
 *
 * `REJECTED` is deliberately not drawn as a fourth step: it is where the journey
 * stopped, not how far it got. A rejection is a **settled outcome** — the
 * platform read the document and said no, with a code — so resubmitting the same
 * bytes would get the same answer, and the screen says that rather than offering
 * a retry that looks like a fix.
 */
function Transmissions({
  invoiceId,
  issued,
  mayManage,
}: {
  invoiceId: string;
  issued: boolean;
  mayManage: boolean;
}) {
  const transmissions = useTransmissions(issued ? invoiceId : null);
  const submit = useSubmitInvoice(invoiceId);

  if (!issued) {
    return null;
  }

  return (
    <section className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">{t("E-invoicing")}</h2>

      {submit.error !== null && <ErrorSurface error={submit.error} />}

      {transmissions.isPending ? (
        <SkeletonRows rows={2} />
      ) : transmissions.error !== null ? (
        <ErrorSurface error={transmissions.error} onRetry={() => void transmissions.refetch()} />
      ) : transmissions.data.length === 0 ? (
        <EmptyState
          title={t("Not transmitted")}
          description={t("This invoice has not been sent to a certified platform.")}
        />
      ) : (
        <ul className="space-y-3">
          {transmissions.data.map((transmission) => (
            <li
              key={transmission.id}
              data-transmission={transmission.id}
              data-status={transmission.status}
              className="space-y-2 rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
            >
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{transmission.provider}</span>
                <span
                  data-testid="transmission-state"
                  className={pill(transmissionTone(transmission.status))}
                >
                  {transmission.status}
                </span>
                {!transmission.settled && (
                  <span className="text-xs text-subtle">{t("still in flight")}</span>
                )}
              </div>

              {transmission.status === 'REJECTED' ? (
                <p data-testid="rejection" className="text-xs text-danger">
                  {t("Rejected")}{transmission.rejection_code !== null && ` (${transmission.rejection_code})`}
                  {transmission.rejection_reason !== null && `: ${transmission.rejection_reason}`}
                  {t(" — the platform read the document and refused it. Sending the same document again would be refused the same way.")}
                </p>
              ) : (
                <ol className="flex flex-wrap gap-2 text-xs" data-testid="progression">
                  {TRANSMISSION_STATES.map((state, index) => (
                    <li
                      key={state}
                      data-step={state}
                      data-reached={index <= progressOf(transmission.status) ? 'true' : 'false'}
                      className={
                        index <= progressOf(transmission.status)
                          ? 'font-medium text-ink'
                          : 'text-subtle'
                      }
                    >
                      {state}
                      {index < TRANSMISSION_STATES.length - 1 && ' →'}
                    </li>
                  ))}
                </ol>
              )}

              {transmission.provider_document_id !== null && (
                <p className="text-xs text-subtle">
                  {t("platform reference")}{' '}<code>{transmission.provider_document_id}</code>
                </p>
              )}
            </li>
          ))}
        </ul>
      )}

      {mayManage && (
        <Button type="button" pending={submit.isPending} onClick={() => submit.mutate()}>
          {t("Transmit")}</Button>
      )}
    </section>
  );
}

function transmissionTone(status: string): Tone {
  switch (status) {
    case 'ACCEPTED':
      return 'success';
    case 'REJECTED':
      return 'danger';
    case 'SUBMITTED':
      return 'info';
    default:
      return 'warning';
  }
}
