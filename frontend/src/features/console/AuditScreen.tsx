import { useAudit, type AuditEntry } from '@/queries/admin';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';
import { currentLocale, t } from '@/i18n';

/**
 * `console.admin.audit` — the trail.
 *
 * **An act nobody performed and an act whose performer has been forgotten are
 * different facts**, and this screen keeps them apart. `actor` carries both the
 * id and whether it was erased precisely so that it can: the contract says
 * *"collapsing them into a bare null would turn every erasure into a system
 * action"*, and a screen that rendered a missing actor as "system" would do
 * exactly that — quietly reassigning a person's acts to the platform.
 *
 * So there are three renderings, not two: a named actor, an **erased** actor
 * (the act stands, the identity is gone), and a genuine system act.
 */
export function AuditScreen() {
  const audit = useAudit();

  if (audit.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (audit.error !== null) {
    return <ErrorSurface error={audit.error} onRetry={() => void audit.refetch()} />;
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title={t("Audit")}
        description={t("What was done, by whom, and to what. An act whose author has since been erased keeps its place here — the act happened, and forgetting the person does not unmake it.")}
      />

      {audit.data.entries.length === 0 ? (
        <EmptyState title={t("Nothing recorded")} description={t("No audited act has happened yet.")} />
      ) : (
        <>
          <p data-testid="audit-count" className="text-xs text-subtle">
            {t("Showing")}{' '}{audit.data.entries.length} {t("of")}{' '}{audit.data.total}.
          </p>

          <ul className="space-y-2">
            {audit.data.entries.map((entry) => (
              <Entry key={entry.id} entry={entry} />
            ))}
          </ul>
        </>
      )}
    </div>
  );
}

/**
 * Three answers to "who", not two.
 *
 * `actor.user_id` present and `actor.erased` false is a person. Present and
 * erased is a person who has been forgotten — the act keeps its author's *place*
 * without their identity. Absent is the platform itself. Reading `erased` is
 * what separates the second from the third.
 */
function Actor({ actor }: { actor: Record<string, unknown> }) {
  const userId = typeof actor.user_id === 'string' ? actor.user_id : null;
  const erased = actor.erased === true;

  if (erased) {
    return (
      <span data-testid="actor" data-actor="erased" className="text-muted">
        {t("a person since erased")}</span>
    );
  }

  if (userId === null) {
    return (
      <span data-testid="actor" data-actor="system" className="text-muted">
        {t("the platform itself")}</span>
    );
  }

  return (
    <code data-testid="actor" data-actor="user" className="text-xs">
      {userId}
    </code>
  );
}

function Entry({ entry }: { entry: AuditEntry }) {
  return (
    <li
      data-audit-entry={entry.id}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-center gap-2">
        <span data-testid="audit-action" className="font-medium">
          {entry.action}
        </span>
        <span className="text-xs text-muted">
          {entry.subject_type}
          {entry.subject_id !== null && ` ${entry.subject_id}`}
        </span>
        <span className="ml-auto text-xs text-subtle">
          {new Date(entry.occurred_at).toLocaleString(currentLocale())}
        </span>
      </div>

      <p className="mt-1 text-xs text-muted">
        {t("by")}{' '}<Actor actor={entry.actor} />
        {entry.tenant_id !== null && (
          <>
            {' '}
            {t("· tenant")}{' '}<code>{entry.tenant_id}</code>
          </>
        )}
        {entry.request_id !== null && (
          <>
            {' '}
            {t("· request")}{' '}<code className="select-all">{entry.request_id}</code>
          </>
        )}
      </p>
    </li>
  );
}
