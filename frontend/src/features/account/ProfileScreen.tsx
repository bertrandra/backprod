import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useUpdateProfile } from '@/queries/account';
import { useSignOut } from '@/queries/auth';
import { useSession } from '@/queries/session';
import { Button, Field, inputClass } from '@/ui/Field';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `account.profile` — the person, not the company.
 *
 * The 120-character bound is the contract's `maxLength`, restated here because a
 * form has to validate before it sends. That is the one place a value legitimately
 * appears twice, and the type-level test in this file's neighbour asserts the two
 * still agree.
 */
const schema = z.object({
  displayName: z.string().trim().max(120, 'A display name is at most 120 characters.'),
});

type Values = z.infer<typeof schema>;

export function ProfileScreen() {
  const session = useSession();
  const update = useUpdateProfile();
  const signOut = useSignOut();

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: { displayName: session.data?.displayName ?? '' },
  });

  if (session.isPending) {
    return <SkeletonRows rows={3} />;
  }

  if (session.error !== null) {
    return <ErrorSurface error={session.error} onRetry={() => void session.refetch()} />;
  }

  return (
    <div className="max-w-lg space-y-4">
      <h1 className="text-2xl font-semibold">Your profile</h1>

      <p className="text-sm text-muted">
        {session.data?.email ?? 'No email address on this account.'}
      </p>

      <form
        className="space-y-4"
        onSubmit={(event) => {
          // `void`: handleSubmit returns a promise that React's onSubmit does not
          // consume, and leaving it floating is what the lint rule is about.
          void form.handleSubmit((values) => {
            // Empty means "clear it": the contract distinguishes an absent field,
            // which leaves the name alone, from null, which removes it. Sending
            // an empty string would store a name that is no name.
            update.mutate(values.displayName === '' ? null : values.displayName);
          })(event);
        }}
      >
        <Field
          id="display-name"
          label="Display name"
          hint="Shown to other members of your organisation. Leave empty to remove it."
          error={form.formState.errors.displayName?.message}
        >
          <input
            id="display-name"
            className={inputClass(form.formState.errors.displayName !== undefined)}
            {...form.register('displayName')}
          />
        </Field>

        {update.error !== null && <ErrorSurface error={update.error} />}

        <div className="flex items-center gap-3">
          <Button type="submit" pending={update.isPending}>
            Save
          </Button>

          {update.isSuccess && !form.formState.isDirty && (
            <span role="status" className="text-sm text-muted">
              Saved
            </span>
          )}
        </div>
      </form>

      {/* Sign out lives on the account screen, which is the one place a person
          looks for it, and is a `<form>`-free button rather than a nav entry:
          navigation is where you can go, and this is something you do.

          Region A's "More" sheet has it too, because that sheet is the phone's
          only route to anything the bottom bar could not fit — and an account
          you cannot leave on a phone is a worse defect than one extra control. */}
      <div className="border-t border-line pt-4">
        <Button
          type="button"
          variant="secondary"
          pending={signOut.isPending}
          onClick={() => signOut.mutate()}
        >
          Sign out
        </Button>
      </div>
    </div>
  );
}
