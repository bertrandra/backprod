import type { ReactNode } from 'react';

import {
  type AccessMotive,
  useStaffTenantConversations,
  useStaffTenantJobs,
  useStaffTenantOrders,
  useStaffTenantPayments,
  useStaffTenantProjects,
  useStaffTenantQuotes,
  useStaffTenantTaxProfile,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * The rest of the customer workspace's tabs, one component each — payments,
 * sales, tax, conversations, workspace, jobs.
 *
 * Each is the customer's own screen with the buttons taken away: the same
 * rows, the same words, and nothing that acts. A refund is *shown* here and
 * *made* on the customer's payments screen by the customer's administrator;
 * a quote is shown and neither accepted nor rejected. That is the whole
 * point of the console reading a customer rather than becoming one (#22).
 *
 * Every list is the product picker's: the props carry the code the bar
 * narrowed to, or null for every product the customer holds.
 */
type TabProps = { tenantId: string; productCode: string | null; motive: AccessMotive };

function Row({ id, children, note, testId }: { id: string; children: ReactNode; note?: ReactNode; testId: string }) {
  return (
    <li data-testid={testId} data-row={id} className="rounded-card border border-line bg-surface p-4 text-sm shadow-raise">
      <div className="flex flex-wrap items-baseline gap-2">{children}</div>
      {note !== undefined && <p className="mt-1 text-xs text-muted">{note}</p>}
    </li>
  );
}

function Status({ children }: { children: ReactNode }) {
  return <span className="rounded bg-well px-1.5 py-0.5 text-xs">{children}</span>;
}

function day(value: string | null | undefined): string {
  return value === null || value === undefined ? '—' : new Date(value).toLocaleDateString();
}

export function PaymentsTab({ tenantId, productCode, motive }: TabProps) {
  const payments = useStaffTenantPayments(tenantId, productCode, motive);

  if (payments.isPending) return <SkeletonRows rows={4} />;
  if (payments.error !== null) return <ErrorSurface error={payments.error} onRetry={() => void payments.refetch()} />;
  if (payments.data.length === 0) {
    return <EmptyState title="No payment" description="Nothing has been collected from this customer here." />;
  }

  return (
    <ul className="space-y-2" data-testid="tab-payments">
      {payments.data.map((payment) => (
        <Row
          key={payment.id}
          id={payment.id}
          testId="payment-row"
          note={
            <>
              {payment.provider} · {payment.method ?? 'method not known'}
              {payment.failure_code !== null && payment.failure_code !== undefined && ` · ${payment.failure_code}`}
              {' · '}
              {day(payment.succeeded_at ?? payment.failed_at ?? payment.created_at)}
            </>
          }
        >
          <Status>{payment.status}</Status>
          <span className="ml-auto">
            <Amount money={payment.amount} />
          </span>
        </Row>
      ))}
    </ul>
  );
}

export function SalesTab({ tenantId, productCode, motive }: TabProps) {
  const orders = useStaffTenantOrders(tenantId, productCode, motive);
  const quotes = useStaffTenantQuotes(tenantId, productCode, motive);

  if (orders.isPending || quotes.isPending) return <SkeletonRows rows={4} />;
  if (orders.error !== null) return <ErrorSurface error={orders.error} onRetry={() => void orders.refetch()} />;
  if (quotes.error !== null) return <ErrorSurface error={quotes.error} onRetry={() => void quotes.refetch()} />;

  return (
    <div className="space-y-6" data-testid="tab-sales">
      <section className="space-y-2">
        <h3 className="text-sm font-semibold">Orders</h3>
        {orders.data.length === 0 ? (
          <p className="text-sm text-muted">No order.</p>
        ) : (
          <ul className="space-y-2">
            {orders.data.map((order) => (
              <Row key={order.id} id={order.id} testId="order-row" note={<>placed {day(order.created_at)}{order.completed_at !== null && order.completed_at !== undefined && ` · completed ${day(order.completed_at)}`}</>}>
                <Status>{order.status}</Status>
                {order.quote_id !== null && order.quote_id !== undefined && <span className="text-xs text-subtle">from a quote</span>}
                <span className="ml-auto">
                  <Amount money={order.gross} />
                </span>
              </Row>
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-2">
        <h3 className="text-sm font-semibold">Quotes</h3>
        {quotes.data.length === 0 ? (
          <p className="text-sm text-muted">No quote.</p>
        ) : (
          <ul className="space-y-2">
            {quotes.data.map((quote) => (
              <Row key={quote.id} id={quote.id} testId="quote-row" note={<>valid until {day(quote.valid_until)}{quote.open ? ' · open' : ' · no longer open'}</>}>
                <Status>{quote.status}</Status>
                <span className="ml-auto">
                  <Amount money={quote.gross} />
                </span>
              </Row>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

export function TaxTab({ tenantId, motive }: { tenantId: string; motive: AccessMotive }) {
  const profile = useStaffTenantTaxProfile(tenantId, motive);

  if (profile.isPending) return <SkeletonRows rows={3} />;
  if (profile.error !== null) return <ErrorSurface error={profile.error} onRetry={() => void profile.refetch()} />;

  const p = profile.data;

  return (
    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]" data-testid="tab-tax">
      <dt className="text-muted">Customer kind</dt>
      <dd>{p.customer_kind === 'B2B' ? 'Business (B2B)' : 'Private person (B2C)'}</dd>
      <dt className="text-muted">Country</dt>
      <dd>{p.country_code ?? 'not declared'}</dd>
      <dt className="text-muted">Taxable person</dt>
      <dd>{p.taxable_person ? 'Yes' : 'No'}</dd>
      <dt className="text-muted">VAT number</dt>
      <dd>
        {p.vat_number === null || p.vat_number === undefined ? (
          'none'
        ) : (
          <>
            <code>{p.vat_number}</code>
            {' · '}
            {/* Verification is a fact with a date, never assumed (§25.3). */}
            {p.vat_number_status ?? 'not checked'}
            {p.vat_number_verified_at !== null && p.vat_number_verified_at !== undefined && ` on ${day(p.vat_number_verified_at)}`}
          </>
        )}
      </dd>
      <dt className="text-muted">Reverse charge</dt>
      <dd>{p.reverse_charge_available ? 'Available — verified intra-EU business' : 'Not available'}</dd>
    </dl>
  );
}

export function ConversationsTab({ tenantId }: { tenantId: string }) {
  const threads = useStaffTenantConversations(tenantId);

  if (threads.isPending) return <SkeletonRows rows={3} />;
  if (threads.error !== null) return <ErrorSurface error={threads.error} onRetry={() => void threads.refetch()} />;
  if (threads.data.length === 0) {
    return <EmptyState title="No support thread" description="This customer has not opened one. Internal threads are theirs alone and are never listed here." />;
  }

  return (
    <ul className="space-y-2" data-testid="tab-conversations">
      {threads.data.map((thread) => (
        <Row key={thread.id} id={thread.id} testId="thread-row" note={<>opened {day(thread.created_at)}{thread.closed_at !== null && thread.closed_at !== undefined && ` · closed ${day(thread.closed_at)}`}</>}>
          <span className="font-medium">{thread.subject}</span>
          <Status>{thread.status}</Status>
        </Row>
      ))}
    </ul>
  );
}

export function WorkspaceTab({ tenantId, productCode, motive }: TabProps) {
  const projects = useStaffTenantProjects(tenantId, productCode, motive);

  if (projects.isPending) return <SkeletonRows rows={4} />;
  if (projects.error !== null) return <ErrorSurface error={projects.error} onRetry={() => void projects.refetch()} />;
  if (projects.data.length === 0) {
    return <EmptyState title="No project" description="This customer has made nothing here yet." />;
  }

  return (
    <ul className="space-y-2" data-testid="tab-workspace">
      {projects.data.map((project) => (
        <Row key={project.id} id={project.id} testId="project-row" note={<>schema v{project.schema_version} · updated {day(project.updated_at)}{project.deleted_at !== null && project.deleted_at !== undefined && ' · in the bin'}</>}>
          <span className="font-medium">{project.name}</span>
          {project.description !== null && project.description !== undefined && project.description !== '' && (
            <span className="text-xs text-muted">{project.description}</span>
          )}
        </Row>
      ))}
    </ul>
  );
}

export function JobsTab({ tenantId, productCode, motive }: TabProps) {
  const jobs = useStaffTenantJobs(tenantId, productCode, motive);

  if (jobs.isPending) return <SkeletonRows rows={4} />;
  if (jobs.error !== null) return <ErrorSurface error={jobs.error} onRetry={() => void jobs.refetch()} />;
  if (jobs.data.length === 0) {
    return <EmptyState title="No job" description="Nothing has been queued for this customer." />;
  }

  return (
    <ul className="space-y-2" data-testid="tab-jobs">
      {jobs.data.map((job) => (
        <Row
          key={job.id}
          id={job.id}
          testId="job-row"
          note={
            <>
              attempt {job.attempts} of {job.max_attempts} · queued {day(job.created_at)}
              {job.finished_at !== null && job.finished_at !== undefined && ` · finished ${day(job.finished_at)}`}
              {job.failure_reason !== null && job.failure_reason !== undefined && ` · ${job.failure_reason}`}
            </>
          }
        >
          <code className="text-xs">{job.type}</code>
          <Status>{job.status}</Status>
        </Row>
      ))}
    </ul>
  );
}
