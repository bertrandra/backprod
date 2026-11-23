import { useState } from 'react';

import { useContactRequests, useHandleContactRequest } from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { PageHeader } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { When } from '@/ui/When';
import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * `/console/contact-requests` — what visitors wrote through product pages
 * (2026-11-23).
 *
 * Support's screen, like the conversations beside it: a list, those still
 * to answer first, each with its address as a link that opens a reply in
 * the reader's own mail. Answering is done there — the platform keeps the
 * message and who marked it answered, and does not send mail on support's
 * behalf. The sentence the visitor agreed to is shown with each one, as
 * they read it, because that is what the answer may rely on.
 */
export function ContactRequestsScreen() {
  const [offset, setOffset] = useState(0);
  const requests = useContactRequests(null, offset);
  const handle = useHandleContactRequest();

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("Messages from product pages")}
        description={t("What visitors wrote through the contact form of a product's page. Those still to answer come first.")}
        meta={
          requests.data === undefined ? undefined : (
            <span data-testid="contact-open">{t("{count} to answer", { count: String(requests.data.open) })}</span>
          )
        }
      />

      {handle.error !== null && <ErrorSurface error={handle.error} />}

      {requests.isPending ? (
        <SkeletonRows rows={4} />
      ) : requests.error !== null ? (
        <ErrorSurface error={requests.error} onRetry={() => void requests.refetch()} />
      ) : requests.data.requests.length === 0 ? (
        <EmptyState
          title={t("No messages yet")}
          description={t("A product's page takes messages once its editor has a contact band and the page is published.")}
        />
      ) : (
        <>
          <ul className="space-y-3" data-testid="contact-requests">
            {requests.data.requests.map((request) => (
              <li
                key={request.id}
                data-request={request.id}
                data-handled={request.handled_at === null ? 'false' : 'true'}
                className={cn('space-y-2 rounded-card border border-line bg-surface p-4', request.handled_at !== null && 'opacity-70')}
              >
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                  <span className="font-semibold">{request.name}</span>
                  <a href={`mailto:${request.email}`} className="text-sm text-accent underline underline-offset-2">
                    {request.email}
                  </a>
                  <span className="text-xs uppercase text-muted">{request.product}</span>
                  <span className="ml-auto text-xs text-muted">
                    <When at={request.received_at} />
                  </span>
                </div>
                <p className="whitespace-pre-line text-sm">{request.message}</p>
                <p className="text-xs text-muted">
                  {t("Agreed to:")} {request.consent}
                </p>
                <div className="flex items-center gap-3">
                  {request.handled_at === null ? (
                    <Button
                      type="button"
                      variant="secondary"
                      pending={handle.isPending && handle.variables === request.id}
                      data-testid={`handle-${request.id}`}
                      onClick={() => handle.mutate(request.id)}
                    >
                      {t("Mark as answered")}
                    </Button>
                  ) : (
                    <span className="text-xs text-muted">
                      {t("Answered")} · <When at={request.handled_at} />
                    </span>
                  )}
                </div>
              </li>
            ))}
          </ul>

          <div className="flex gap-2">
            <Button type="button" variant="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - 50))}>
              {t("Previous")}
            </Button>
            <Button type="button" variant="secondary" disabled={offset + 50 >= requests.data.total} onClick={() => setOffset(offset + 50)}>
              {t("Next")}
            </Button>
          </div>
        </>
      )}
    </div>
  );
}
