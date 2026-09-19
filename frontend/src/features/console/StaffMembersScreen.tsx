import { useDeferredValue, useState } from 'react';

import { can } from '@/app/access/access';
import { useAdminUsers } from '@/queries/admin';
import {
  staffAccess,
  useGrantPlatformRole,
  useRevokePlatformRole,
  useStaffIdentity,
  useStaffRoster,
  type PlatformRole,
  type StaffMember,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SearchPicker } from '@/ui/pickers/SearchPicker';
import { type Person } from '@/ui/pickers/Select';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';
import { currentLocale, t } from '@/i18n';

/**
 * `console.admin.staff` — who holds platform authority.
 *
 * The screen that stops a fresh installation being a dead end. Until it
 * existed the only way to appoint anybody was an INSERT against production,
 * which is a thing an installer should never require and a thing a person who
 * has just installed this cannot do.
 *
 * **The last administrator is shown as protected rather than simply refused.**
 * The database enforces that one always remains, and a button that looked
 * available and then answered 409 would teach people the console is unreliable.
 * So the row says why, before anybody clicks. The server still refuses — this
 * is the explanation, not the enforcement.
 *
 * **Somebody is appointed by finding them, where the directory may be read.**
 * The API takes a user id and nothing else, on purpose: resolving an email
 * here would make appointment a way to ask whether an account exists for
 * any address. The directory (`admin.directory.read`) already answers that
 * question for the people allowed to ask it, so for them this searches it
 * and shows who was found — name, address, and the id that will be sent.
 * Without that permission the id field stays, copied from wherever they
 * were given it.
 */
export function StaffMembersScreen() {
  const roster = useStaffRoster();
  const me = useStaffIdentity();
  const grant = useGrantPlatformRole();
  const revoke = useRevokePlatformRole();

  const [userId, setUserId] = useState('');
  const [role, setRole] = useState('');
  const [picked, setPicked] = useState<Person | null>(null);
  const [query, setQuery] = useState('');
  const search = useDeferredValue(query.trim());
  const maySearch = can(staffAccess(me.data), 'admin.directory.read');
  const found = useAdminUsers(search, 8, 0, maySearch && search !== '');

  if (roster.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (roster.error !== null) {
    return <ErrorSurface error={roster.error} onRetry={() => void roster.refetch()} />;
  }

  const members = roster.data.members;
  const roles = roster.data.roles;
  const admins = members.filter((m) => m.roles.includes('PLATFORM_ADMIN'));

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title={t("Staff")}
        description={t("Who may act across tenants, and under which role. A platform role never grants membership of anybody&rsquo;s tenant — it grants the console, and every crossing it allows is recorded in the access log.")}
      />

      {grant.error !== null && <ErrorSurface error={grant.error} />}
      {revoke.error !== null && <ErrorSurface error={revoke.error} />}

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">{t("Current staff")}</h2>

        {members.length === 0 ? (
          <EmptyState
            title={t("Nobody holds a platform role")}
            description={t("That should be impossible — the platform keeps at least one administrator.")}
          />
        ) : (
          <ul className="space-y-2" data-testid="staff-list">
            {members.map((member) => (
              <Member
                key={member.user_id}
                member={member}
                isOnlyAdmin={admins.length === 1 && admins[0]?.user_id === member.user_id}
                isSelf={member.user_id === me.data?.userId}
                pending={revoke.isPending}
                onRevoke={(revokedRole) =>
                  revoke.mutate({ userId: member.user_id, role: revokedRole })
                }
              />
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-3 border-t border-line pt-4">
        <h2 className="text-xl font-semibold">{t("Appoint somebody")}</h2>

        <p className="hint text-sm text-muted">
          {maySearch
            ? t("Find the person in the directory, then choose the role. What is sent is their user id, shown beside the name.")
            : t("By user id, which the Directory screen shows. An email address is not accepted here on purpose: resolving one would make this a way to ask whether an account exists for any address somebody tried.")}
        </p>

        <form
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault();

            if (userId.trim() !== '' && role !== '') {
              grant.mutate(
                { userId: userId.trim(), role },
                {
                  onSuccess: () => {
                    setUserId('');
                    setPicked(null);
                    setQuery('');
                    setRole('');
                  },
                },
              );
            }
          }}
        >
          {maySearch ? (
            <Field id="staff-user-id" label={t("Who")} hint={t("Search the directory by name or email.")}>
              <SearchPicker
                id="staff-user-id"
                query={query}
                onQueryChange={setQuery}
                pending={found.isPending && search !== ''}
                results={(found.data?.users ?? [])
                  .filter((user) => user.erased_at === null && typeof user.id === 'string')
                  .map((user) => ({ id: String(user.id), name: user.display_name ?? null, email: user.email ?? null }))}
                value={picked}
                onPick={(person) => {
                  setPicked(person);
                  setUserId(person?.id ?? '');
                }}
                placeholder={t("ada@example.test")}
              />
            </Field>
          ) : (
            <Field id="staff-user-id" label={t("User id")} hint={t("A uuid, copied from the Directory.")}>
              <input
                id="staff-user-id"
                className={inputClass()}
                placeholder="00000000-0000-0000-0000-000000000000"
                value={userId}
                onChange={(event) => setUserId(event.target.value)}
              />
            </Field>
          )}

          <Field id="staff-role" label={t("Role")}>
            <select
              id="staff-role"
              className={inputClass()}
              value={role}
              onChange={(event) => setRole(event.target.value)}
            >
              <option value="">{t("Choose a role…")}</option>
              {roles.map((platformRole: PlatformRole) => (
                <option key={platformRole.code} value={platformRole.code}>
                  {platformRole.name} ({platformRole.code})
                </option>
              ))}
            </select>
          </Field>

          <Button type="submit" pending={grant.isPending} disabled={userId.trim() === '' || role === ''}>
            {t("Grant role")}</Button>
        </form>
      </section>
    </div>
  );
}

function Member({
  member,
  isOnlyAdmin,
  isSelf,
  pending,
  onRevoke,
}: {
  member: StaffMember;
  isOnlyAdmin: boolean;
  isSelf: boolean;
  pending: boolean;
  onRevoke: (role: string) => void;
}) {
  return (
    <li
      data-staff-member={member.user_id}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-medium">
          {member.display_name ?? member.email ?? t("Erased account")}
        </span>

        {member.email !== null && member.display_name !== null && (
          <span className="text-xs text-subtle">{member.email}</span>
        )}

        {isSelf && (
          <span
            data-testid="is-self"
            className="rounded bg-well px-1.5 py-0.5 text-xs"
          >
            {t("you")}</span>
        )}

        <span className="ml-auto text-xs text-subtle">
          {t("since")}{' '}{new Date(member.granted_at).toLocaleDateString(currentLocale())}
        </span>
      </div>

      <p className="mt-1 text-xs text-muted">
        <code>{member.user_id}</code>
      </p>

      <ul className="mt-2 flex flex-wrap gap-2">
        {member.roles.map((role) => {
          const protectedRole = role === 'PLATFORM_ADMIN' && (isOnlyAdmin || isSelf);

          return (
            <li key={role} className="flex items-center gap-1.5">
              <span
                data-testid="staff-role"
                className="rounded bg-well px-1.5 py-0.5 text-xs"
              >
                {role}
              </span>

              {protectedRole ? (
                <span data-testid="role-protected" className="text-xs text-subtle">
                  {isOnlyAdmin
                    ? t("the last administrator — appoint somebody else first")
                    : t("your own — another administrator can remove it")}
                </span>
              ) : (
                <Button
                  type="button"
                  variant="danger"
                  pending={pending}
                  onClick={() => onRevoke(role)}
                >
                  {t("Revoke")}</Button>
              )}
            </li>
          );
        })}
      </ul>
    </li>
  );
}
