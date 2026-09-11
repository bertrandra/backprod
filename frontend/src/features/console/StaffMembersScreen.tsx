import { useState } from 'react';

import {
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
import { SkeletonRows } from '@/ui/Skeleton';

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
 */
export function StaffMembersScreen() {
  const roster = useStaffRoster();
  const me = useStaffIdentity();
  const grant = useGrantPlatformRole();
  const revoke = useRevokePlatformRole();

  const [userId, setUserId] = useState('');
  const [role, setRole] = useState('');

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
      <header className="space-y-1">
        <h1 className="text-lg font-semibold">Staff</h1>
        <p className="text-sm text-neutral-600 dark:text-neutral-400">
          Who may act across tenants, and under which role. A platform role never grants membership
          of anybody&rsquo;s tenant — it grants the console, and every crossing it allows is
          recorded in the access log.
        </p>
      </header>

      {grant.error !== null && <ErrorSurface error={grant.error} />}
      {revoke.error !== null && <ErrorSurface error={revoke.error} />}

      <section className="space-y-3">
        <h2 className="text-base font-semibold">Current staff</h2>

        {members.length === 0 ? (
          <EmptyState
            title="Nobody holds a platform role"
            description="That should be impossible — the platform keeps at least one administrator."
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

      <section className="space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
        <h2 className="text-base font-semibold">Appoint somebody</h2>

        <p className="hint text-sm text-neutral-600 dark:text-neutral-400">
          By user id, which the <strong>Directory</strong> screen shows. An email address is not
          accepted here on purpose: resolving one would make this a way to ask whether an account
          exists for any address somebody tried.
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
                    setRole('');
                  },
                },
              );
            }
          }}
        >
          <Field id="staff-user-id" label="User id" hint="A uuid, copied from the Directory.">
            <input
              id="staff-user-id"
              className={inputClass()}
              placeholder="00000000-0000-0000-0000-000000000000"
              value={userId}
              onChange={(event) => setUserId(event.target.value)}
            />
          </Field>

          <Field id="staff-role" label="Role">
            <select
              id="staff-role"
              className={inputClass()}
              value={role}
              onChange={(event) => setRole(event.target.value)}
            >
              <option value="">Choose a role…</option>
              {roles.map((platformRole: PlatformRole) => (
                <option key={platformRole.code} value={platformRole.code}>
                  {platformRole.name} ({platformRole.code})
                </option>
              ))}
            </select>
          </Field>

          <Button type="submit" pending={grant.isPending} disabled={userId.trim() === '' || role === ''}>
            Grant role
          </Button>
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
      className="rounded border border-neutral-200 p-3 text-sm dark:border-neutral-800"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-medium">
          {member.display_name ?? member.email ?? 'Erased account'}
        </span>

        {member.email !== null && member.display_name !== null && (
          <span className="text-xs text-neutral-500">{member.email}</span>
        )}

        {isSelf && (
          <span
            data-testid="is-self"
            className="rounded bg-neutral-200 px-1.5 py-0.5 text-xs dark:bg-neutral-800"
          >
            you
          </span>
        )}

        <span className="ml-auto text-xs text-neutral-500">
          since {new Date(member.granted_at).toLocaleDateString()}
        </span>
      </div>

      <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
        <code>{member.user_id}</code>
      </p>

      <ul className="mt-2 flex flex-wrap gap-2">
        {member.roles.map((role) => {
          const protectedRole = role === 'PLATFORM_ADMIN' && (isOnlyAdmin || isSelf);

          return (
            <li key={role} className="flex items-center gap-1.5">
              <span
                data-testid="staff-role"
                className="rounded bg-neutral-200 px-1.5 py-0.5 text-xs dark:bg-neutral-800"
              >
                {role}
              </span>

              {protectedRole ? (
                <span data-testid="role-protected" className="text-xs text-neutral-500">
                  {isOnlyAdmin
                    ? 'the last administrator — appoint somebody else first'
                    : 'your own — another administrator can remove it'}
                </span>
              ) : (
                <Button
                  type="button"
                  variant="danger"
                  pending={pending}
                  onClick={() => onRevoke(role)}
                >
                  Revoke
                </Button>
              )}
            </li>
          );
        })}
      </ul>
    </li>
  );
}
