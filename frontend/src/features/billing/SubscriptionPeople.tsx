import { useState } from 'react';

import { can } from '@/app/access/access';
import { useMembers } from '@/queries/members';
import { useSession } from '@/queries/session';
import { useAddPerson, usePeople, useRemovePerson } from '@/queries/subscription';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

/**
 * The people a subscription covers (2026-09-19), managed by its owner.
 *
 * An offer may sell a number of users; whoever activated the subscription —
 * the person a seat is for, the administrator who bought the organisation's
 * — adds and removes people within that quota, from the organisation's
 * members or by address. Somebody added by address who has no account gets
 * one and a link to set their password; that is said when it happens,
 * because the person will not otherwise know why a mail arrived.
 *
 * Read by anybody who may read the subscription; the controls are the
 * owner's, and the server refuses everybody else (`NOT_THE_OWNER`), so the
 * hiding here is the usual courtesy. Nothing optimistic: adding may create
 * an account.
 */
export function SubscriptionPeople({ seat }: { seat: boolean }) {
  const { data: session } = useSession();
  const people = usePeople(seat);
  const add = useAddPerson(seat);
  const remove = useRemovePerson(seat);
  // The organisation's members, for the picker — read where it may be.
  const members = useMembers(can(session, 'members.read'));

  const [choice, setChoice] = useState('');
  const [email, setEmail] = useState('');
  const [lastInvited, setLastInvited] = useState<string | null>(null);

  if (people.isPending) {
    return <SkeletonRows rows={3} />;
  }

  if (people.error !== null) {
    // No live subscription to manage is nothing to show, not an error.
    return null;
  }

  const current = people.data;
  const taken = current.members.length + 1;
  const quota = current.quota;
  const full = quota !== null && taken >= quota;
  const mayManage = current.owner && can(session, 'subscription.manage');
  const ownerId = current.owner_user_id;
  const candidates = (members.data ?? []).filter(
    (member) =>
      member.status === 'ACTIVE' &&
      member.user_id !== ownerId &&
      !current.members.some((covered) => covered.user_id === member.user_id),
  );

  return (
    <section data-testid="subscription-people" data-quota={quota ?? t("unlimited")} className="space-y-3 border-t border-line pt-6">
      <div className="flex flex-wrap items-baseline gap-2">
        <h2 className="text-xl font-semibold">{t("People")}</h2>
        <span className="text-sm text-muted" data-testid="people-count">
          {quota === null ? t("{value} covered, no limit", { value: String(taken) }) : t("{value} of {value_} covered", { value: String(taken), value_: String(quota) })}
        </span>
      </div>

      <p className="text-xs text-muted">
        {current.owner
          ? t("You activated this subscription, so you decide who it covers — the offer says how many.")
          : t("Whoever activated this subscription decides who it covers.")}
      </p>

      <ul className="space-y-1 text-sm" data-testid="people-list">
        <li className="flex flex-wrap items-center gap-2">
          <span className="min-w-0 flex-1">{current.owner ? t("You") : t("The owner")}</span>
          <span className="text-xs text-subtle">{t("owner")}</span>
        </li>
        {current.members.map((member) => (
          <li key={member.user_id} data-person={member.user_id} className="flex flex-wrap items-center gap-2">
            <span className="min-w-0 flex-1">
              {member.display_name ?? member.email ?? member.user_id}
              {member.display_name !== null && member.email !== null && (
                <span className="text-xs text-subtle"> · {member.email}</span>
              )}
            </span>
            {mayManage && (
              <Button
                type="button"
                variant="secondary"
                pending={remove.isPending}
                onClick={() => remove.mutate(member.user_id)}
              >
                {t("Remove")}</Button>
            )}
          </li>
        ))}
      </ul>

      {lastInvited !== null && (
        <p data-testid="person-invited" role="status" className="text-xs text-muted">
          {lastInvited} {t("had no account: one was made, and a link to choose a password has been sent to that address. It is good for seven days.")}</p>
      )}

      {remove.error !== null && <ErrorSurface error={remove.error} />}

      {mayManage && !full && (
        <div className="max-w-md space-y-3" data-testid="add-person">
          {add.error !== null && <ErrorSurface error={add.error} />}

          {candidates.length > 0 && (
            <div className="flex flex-wrap items-end gap-2">
              <Field id="person-member" label={t("A member of the organisation")}>
                <select id="person-member" className={inputClass()} value={choice} onChange={(event) => setChoice(event.target.value)}>
                  <option value="">{t("Choose a member")}</option>
                  {candidates.map((member) => (
                    <option key={member.user_id} value={member.user_id}>
                      {member.display_name ?? member.email}
                    </option>
                  ))}
                </select>
              </Field>
              <Button
                type="button"
                variant="secondary"
                pending={add.isPending}
                disabled={choice === ''}
                onClick={() =>
                  add.mutate(
                    { user_id: choice },
                    {
                      onSuccess: () => {
                        setChoice('');
                        setLastInvited(null);
                      },
                    },
                  )
                }
              >
                {t("Add")}</Button>
            </div>
          )}

          <div className="flex flex-wrap items-end gap-2">
            <Field id="person-email" label={t("Or anybody, by email")} hint={t("Somebody with no account gets one, and a link to choose a password.")}>
              <input
                id="person-email"
                type="email"
                className={inputClass()}
                value={email}
                onChange={(event) => setEmail(event.target.value)}
              />
            </Field>
            <Button
              type="button"
              pending={add.isPending}
              disabled={email.trim() === ''}
              data-testid="invite-person"
              onClick={() =>
                add.mutate(
                  { email: email.trim() },
                  {
                    onSuccess: (added) => {
                      setLastInvited(added.invited ? email.trim() : null);
                      setEmail('');
                    },
                  },
                )
              }
            >
              {t("Add by email")}</Button>
          </div>
        </div>
      )}

      {mayManage && full && (
        <p data-testid="people-full" className="text-xs text-muted">
          {t("Every place this offer sold is taken. Remove somebody to add another, or change the offer.")}</p>
      )}
    </section>
  );
}
