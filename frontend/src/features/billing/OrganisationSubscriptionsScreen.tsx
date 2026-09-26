import { can } from '@/app/access/access';
import { useOrganisation } from '@/queries/organisation';
import { useSession } from '@/queries/session';
import { useOrganisationSubscriptions, type HeldSubscription } from '@/queries/subscription';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Amount } from '@/ui/Money';
import { PageHeader } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { TBody, Table, Td, TR, Th, THead } from '@/ui/Table';
import { When } from '@/ui/When';
import { billingPeriod } from '@/ui/period';
import { t } from '@/i18n';

/**
 * `organisation.subscriptions` — who in this organisation holds what, and how
 * many of the places they bought are taken (2026-09-25).
 *
 * **The screen the model needed.** Since the tenant surface sells seats only
 * (ADR-055), an organisation's subscriptions belong to its people one by one:
 * the administrator does not buy, they administer, and until now nothing
 * showed the set together. `/subscription` answers for whoever is asking; this
 * answers for the organisation.
 *
 * **`tenant.manage`.** Not `subscription.read`, which every member holds —
 * this is a list of what colleagues bought and for how much, ADR-053's rule
 * seen from the other side. And not `subscription.manage`, which reads like
 * the right word and is not: a USER holds it too, because managing a
 * subscription's people is what a seat holder does with their own seat.
 * Gating on it would have shown every member the whole register, which is
 * what opening this screen as a demonstration administrator said out loud.
 *
 * **Nothing here is derived locally**, and since 2026-09-26 that includes the
 * order. `live` and `places_used` are the server's answers: a screen working
 * "live" out of `current_period_end` would disagree with the server a second
 * later, and one counting places itself would be describing a quota the
 * server does not enforce.
 *
 * The living came first from this file for a day, which was fine until the
 * list was paged — and it had to be paged, because every cancelled seat stays
 * for ever and this one grows with the organisation. A page sorted after it
 * arrives puts page two's live seats below page one's dead ones, so the
 * ordering moved into the query where the clock already is.
 */
export function OrganisationSubscriptionsScreen() {
  const { data: session, isPending: askingWho } = useSession();
  const mayManage = can(session, 'tenant.manage');
  const held = useOrganisationSubscriptions(mayManage);
  // Which organisation this register belongs to (2026-09-26). Read, not
  // assumed, and only where it is allowed: `tenant.read` is every member's.
  const organisation = useOrganisation(can(session, 'tenant.read'));

  // Until the session has answered, nobody is told whose screen this is. A
  // permission read as absent while it is merely unread told the
  // demonstration's administrator that the screen was not theirs, for as long
  // as `/me` took — which is a refusal on no evidence.
  if (askingWho) {
    return <SkeletonRows rows={5} />;
  }

  if (!mayManage) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("Subscriptions")} />
        <EmptyState
          title={t("This is the administrator's view")}
          description={t("What everybody in the organisation holds is an administrator's read. Your own subscription is on the Subscription screen.")}
        />
      </div>
    );
  }

  if (held.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (held.error !== null) {
    return <ErrorSurface error={held.error} onRetry={() => void held.refetch()} />;
  }

  // As the server sent them: living first, then newest first. Not re-sorted
  // here — one page sorted locally would put page two's live seats under page
  // one's dead ones.
  const rows = held.data.subscriptions;
  const living = rows.filter((one) => one.live).length;

  if (rows.length === 0) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("Subscriptions")} />
        <EmptyState
          title={t("Nobody here holds anything yet")}
          description={t("A person buys a seat for themselves from the catalogue. What they hold appears here.")}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("Subscriptions")}
        // Which organisation's register this is (2026-09-26). Somebody who
        // belongs to two of them is one switcher click from reading the other
        // one's people with nothing on the page to say so.
        meta={
          <span data-testid="organisation-name">
            {organisation.data?.name !== undefined && `${organisation.data.name} · `}
            {t("{count} live of {total}", { count: living, total: held.data.total })}
          </span>
        }
        description={t("What each person in this organisation holds on this product, and how many of the places their offer sells are taken. Buying is theirs; this is the record of it.")}
      />

      <Table caption={t("Who holds what, with the places taken")}>
        <THead>
          <Th>{t("Holder")}</Th>
          <Th>{t("Offer")}</Th>
          <Th>{t("Billing")}</Th>
          <Th numeric>{t("Price")}</Th>
          <Th numeric>{t("Places")}</Th>
          <Th>{t("Owed until")}</Th>
          <Th>{t("State")}</Th>
        </THead>
        <TBody>
          {rows.map((one) => (
            <HeldRow key={one.id} held={one} />
          ))}
        </TBody>
      </Table>
    </div>
  );
}

function HeldRow({ held }: { held: HeldSubscription }) {
  const holder = held.holder;

  return (
    <TR data-subscription={held.id} data-live={held.live} muted={!held.live}>
      <Td>
        {holder === null || holder === undefined ? (
          // Said, not attributed. A row from before ownership was recorded has
          // no holder, and naming the organisation instead would invent one.
          <span data-testid="no-holder" className="text-subtle">{t("Nobody recorded")}</span>
        ) : (
          <span className="flex flex-col">
            <span>{holder.name ?? holder.email ?? t("A member")}</span>
            {holder.name !== null && holder.name !== undefined && holder.email !== null && holder.email !== undefined && (
              <span className="text-xs text-subtle">{holder.email}</span>
            )}
          </span>
        )}
      </Td>
      <Td>
        <span className="flex flex-col">
          <span>{held.offer_name}</span>
          <span className="text-xs text-subtle">{held.plan_name}</span>
        </span>
      </Td>
      {/* Periodicity, on its own — how often they pay is not how long they
          agreed to stay (non-negotiable #23), and the two never share a cell. */}
      <Td>{billingPeriod(held.billing_period)}</Td>
      <Td numeric>
        <Amount money={held.price} />
      </Td>
      <Td numeric>
        <Places held={held} />
      </Td>
      <Td>
        <When at={held.current_period_end} testId="owed-until" />
      </Td>
      <Td>
        {/* A word in a cell, not a notice box: `notice()` is sized for a
            paragraph and read as a button at the end of a table row. */}
        <span
          data-testid="state"
          className={
            held.live
              ? 'rounded-full bg-well px-2 py-0.5 text-xs'
              : 'text-xs text-subtle'
          }
        >
          {held.live ? t("live") : held.status.toLowerCase()}
        </span>
      </Td>
    </TR>
  );
}

/**
 * Places taken out of places sold — and the two nulls that are not the same
 * null (§13.1). `places_sold` is null only for an offer that covers everybody;
 * an offer selling no `users` feature sends 1, because a subscription is one
 * person's unless it says otherwise.
 */
function Places({ held }: { held: HeldSubscription }) {
  const sold = held.places_sold;
  const full = sold !== null && sold !== undefined && held.places_used >= sold;

  return (
    <span data-testid="places" data-full={full}>
      {held.places_used}
      {' / '}
      {sold === null || sold === undefined ? (
        <span title={t("This offer covers an unlimited number of people.")}>{t("∞")}</span>
      ) : (
        sold
      )}
    </span>
  );
}
