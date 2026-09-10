import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { AuthFailure, authConfig, signInWithPassword } from '@/api/auth';
import { useSessionStore } from '@/state/session';
import { Button, Field, inputClass } from '@/ui/Field';

/**
 * How a person gets a token.
 *
 * Until this existed, `signIn` was exported and **called by nothing**: the
 * platform verified Supabase JWTs, the store had somewhere to put one, and no
 * screen ever obtained one. A deployment rendered every shell correctly and left
 * every visitor anonymous forever — which no gate caught, because a token is not
 * an API operation and `ui-spec.md` never named a sign-in screen to cover.
 *
 * **No route of its own.** This renders *instead of* the shell for whatever URL
 * was asked for, the way `AccessMotiveGate` renders instead of a tenant detail.
 * Redirecting to `/sign-in` would drop the deep link the person followed, and
 * then getting them back to it means remembering where they were going — state
 * that only exists because of the redirect. Signing in from here leaves the
 * router exactly where it already is.
 */
const schema = z.object({
  // Validated here because the form has to say something before it sends, and
  // deliberately shallow: the provider is the authority on whether an address
  // exists, and a stricter pattern would reject valid addresses to no end.
  email: z.string().trim().min(1, 'Enter your email address.').email('That is not an email address.'),
  password: z.string().min(1, 'Enter your password.'),
});

type Values = z.infer<typeof schema>;

export function SignInScreen() {
  const configured = authConfig() !== null;
  const signIn = useSessionStore((state) => state.signIn);
  const [failure, setFailure] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '' },
  });

  return (
    <main className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-4">
      <div className="space-y-1">
        <h1 className="text-lg font-semibold">Sign in</h1>
        <p className="text-sm text-neutral-600 dark:text-neutral-400">
          {configured
            ? 'Use the email address your organisation was invited with.'
            : // The same thing the backend says with an empty SUPABASE_JWKS, said
              // to the person in front of it rather than only in a log: an
              // unconfigured deployment authenticates nobody, and that is a
              // deployment fault rather than a wrong password.
              'This deployment has no identity provider configured yet, so there is nothing to sign in to. Whoever set it up needs to finish that first.'}
        </p>
      </div>

      {configured && (
        <form
          className="space-y-4"
          noValidate
          onSubmit={(event) => {
            void form.handleSubmit(async (values) => {
              setFailure(null);
              setPending(true);

              try {
                signIn(await signInWithPassword(values.email, values.password));
              } catch (error) {
                // Only this module's own message is shown. An unexpected throw
                // gets the generic sentence rather than its own text, because
                // anything that is not an AuthFailure is a bug here and its
                // message is written for whoever is fixing it.
                setFailure(
                  error instanceof AuthFailure
                    ? error.message
                    : 'Something went wrong signing in. Try again.',
                );
                // Cleared on failure, deliberately: a wrong password should not
                // be resubmitted by pressing Enter on a form that looks ready.
                form.resetField('password');
              } finally {
                setPending(false);
              }
            })(event);
          }}
        >
          <Field id="email" label="Email" error={form.formState.errors.email?.message}>
            <input
              id="email"
              type="email"
              autoComplete="username"
              autoFocus
              className={inputClass(form.formState.errors.email !== undefined)}
              {...form.register('email')}
            />
          </Field>

          <Field id="password" label="Password" error={form.formState.errors.password?.message}>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              className={inputClass(form.formState.errors.password !== undefined)}
              {...form.register('password')}
            />
          </Field>

          {/* Announced, and outside the fields: this is about the attempt rather
              than about one input, so it belongs to the form. */}
          {failure !== null && (
            <p role="alert" className="text-sm text-red-700 dark:text-red-400">
              {failure}
            </p>
          )}

          <Button type="submit" pending={pending}>
            Sign in
          </Button>
        </form>
      )}
    </main>
  );
}
