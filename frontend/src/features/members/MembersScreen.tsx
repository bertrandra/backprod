import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { can } from '@/app/access/access';
import { useAddMember, useMembers, useRemoveMember, useUpdateMemberRoles } from '@/queries/members';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `tenant.members` — who is in the organisation, and with which role.
 *
 * The first screen with a destructive action, which is why it is here rather than
 * later: removing a member needs a confirmation that says what will happen, and
 * getting that pattern right on a reversible action is cheaper than getting it
 * wrong on an irreversible one (U7's period closure, U8's erasure).
 *
 * Roles are free text against the API, which assigns meaning to them. This screen
 * does not enumerate them — a hardcoded list of role names is the branching §13
 * forbids, moved into a dropdown.
 */
const inviteSchema = z.object({
  email: z.string().trim().pipe(z.email('That is not an email address.')),
  roles: z.string().trim().min(1, 'Name at least one role.'),
});

type InviteValues = z.infer<typeof inviteSchema>;

const parseRoles = (value: string): string[] =>
  value
    .split(',')
    .map((role) => role.trim())
    .filter((role) => role !== '');

export function MembersScreen() {
  const session = useSession();
  const members = useMembers();
  const add = useAddMember();
  const updateRoles = useUpdateMemberRoles();
  const remove = useRemoveMember();
  const [confirming, setConfirming] = useState<string | null>(null);

  const mayManage = can(session.data, 'members.manage');

  const form = useForm<InviteValues>({
    resolver: zodResolver(inviteSchema),
    defaultValues: { email: '', roles: 'USER' },
  });

  if (members.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (members.error !== null) {
    return <ErrorSurface error={members.error} onRetry={() => void members.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-6">
      <h1 className="text-2xl font-semibold">Members</h1>

      {members.data.length === 0 ? (
        <EmptyState title="No members yet" description="Add someone by email address below." />
      ) : (
        // Cards on a phone, a table from md. Not horizontal scroll, which is what
        // ui-spec.md §4.2 forbids and what a naive table gives you.
        <ul className="space-y-2">
          {members.data.map((member) => {
            const isSelf = member.user_id === session.data?.userId;

            return (
              <li
                key={member.user_id}
                className="rounded-card border border-line bg-surface p-4 shadow-raise md:flex md:items-center md:gap-4"
              >
                <div className="min-w-0 md:flex-1">
                  <p className="truncate text-sm font-medium">
                    {member.display_name ?? member.email ?? 'Unnamed member'}
                    {isSelf && <span className="ml-2 text-xs text-subtle">(you)</span>}
                  </p>
                  <p className="truncate text-xs text-muted">
                    {member.email ?? 'No email address'}
                  </p>
                </div>

                <p className="mt-2 text-xs md:mt-0 md:w-48">{member.roles.join(', ')}</p>

                {mayManage && (
                  <div className="mt-3 flex flex-wrap gap-2 md:mt-0">
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() => {
                        const next = window.prompt(
                          'Roles, comma separated',
                          member.roles.join(', '),
                        );

                        if (next !== null && parseRoles(next).length > 0) {
                          updateRoles.mutate({
                            userId: member.user_id,
                            roles: parseRoles(next),
                          });
                        }
                      }}
                    >
                      Change roles
                    </Button>

                    {confirming === member.user_id ? (
                      <>
                        {/* Says what will happen rather than "are you sure?" —
                            the pattern U7 and U8 will need for actions that
                            cannot be undone. */}
                        <span role="alert" className="text-xs text-red-800 dark:text-red-300">
                          Remove from this organisation? They keep their account.
                        </span>
                        <Button
                          type="button"
                          variant="danger"
                          pending={remove.isPending}
                          onClick={() => {
                            remove.mutate(member.user_id, {
                              onSettled: () => setConfirming(null),
                            });
                          }}
                        >
                          Remove
                        </Button>
                        <Button
                          type="button"
                          variant="secondary"
                          onClick={() => setConfirming(null)}
                        >
                          Keep
                        </Button>
                      </>
                    ) : (
                      <Button
                        type="button"
                        variant="danger"
                        onClick={() => setConfirming(member.user_id)}
                      >
                        Remove
                      </Button>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {updateRoles.error !== null && <ErrorSurface error={updateRoles.error} />}
      {remove.error !== null && <ErrorSurface error={remove.error} />}

      {mayManage && (
        <form
          className="max-w-lg space-y-4 border-t border-line pt-4"
          onSubmit={(event) => {
            void form.handleSubmit((values) =>
              add.mutate(
                { email: values.email, roles: parseRoles(values.roles) },
                { onSuccess: () => form.reset() },
              ),
            )(event);
          }}
        >
          <h2 className="text-xl font-semibold">Add a member</h2>

          <Field id="invite-email" label="Email" error={form.formState.errors.email?.message}>
            <input
              id="invite-email"
              type="email"
              className={inputClass(form.formState.errors.email !== undefined)}
              {...form.register('email')}
            />
          </Field>

          <Field
            id="invite-roles"
            label="Roles"
            hint="Comma separated. The platform decides what each role grants."
            error={form.formState.errors.roles?.message}
          >
            <input
              id="invite-roles"
              className={inputClass(form.formState.errors.roles !== undefined)}
              {...form.register('roles')}
            />
          </Field>

          {add.error !== null && <ErrorSurface error={add.error} />}

          <Button type="submit" pending={add.isPending}>
            Add member
          </Button>
        </form>
      )}
    </div>
  );
}
