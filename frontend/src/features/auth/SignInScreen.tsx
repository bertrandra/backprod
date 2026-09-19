import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { withRoot } from '@/app/root';
import { useForgotPassword, useResetPassword, useSignIn, useVerifyEmail } from '@/queries/auth';
import { useSessionStore } from '@/state/session';
import { Button, Field, inputClass } from '@/ui/Field';
import { PageHeader } from '@/ui/Page';

/**
 * How a person gets a token.
 *
 * Until this existed, `signIn` was exported and **called by nothing**: the
 * platform verified Supabase JWTs, the store had somewhere to put one, and no
 * screen ever obtained one. A deployment rendered every shell correctly and left
 * every visitor anonymous forever — which no gate caught, because a token is not
 * an API operation and `ui-spec.md` never named a sign-in screen to cover.
 *
 * **A route exists, and nothing links to it.** `SignInGate` renders this *instead
 * of* the shell for whatever URL was asked for, the way `AccessMotiveGate` renders
 * instead of a tenant detail — so a deep link survives signing in with nothing to
 * remember and nothing to restore. `/sign-in` exists so the screen is addressable
 * and so its coverage area can declare a route, not because anybody is sent there.
 *
 * Since U12 the token comes from this platform's own `POST /api/v1/auth/token`,
 * through the generated client like every other call. There is no second HTTP
 * door any more.
 *
 * **It also answers the confirmation link.** Somebody following
 * `/sign-in?verify=…` from an email is on a device that may never have signed
 * in — which is half the point of confirming an address — so the confirmation
 * belongs on the one screen that works without a session. It is deliberately
 * *not* a sign-in: the endpoint issues no token, because a link that did would
 * be a credential living in an inbox. The address is confirmed and the form
 * below is how they carry on.
 */
const schema = z.object({
  // Validated here because the form has to say something before it sends, and
  // deliberately shallow: the server is the authority on whether an address
  // exists, and a stricter pattern would reject valid addresses to no end.
  email: z.string().trim().min(1, 'Enter your email address.').email('That is not an email address.'),
  // The bounds are the contract's, restated because a form has to check before it
  // sends. 72 is bcrypt's ceiling, not a preference — see JsonBody::requiredSecret.
  // Deliberately not trimmed: a password may begin or end with a space.
  password: z
    .string()
    .min(12, 'A password is at least 12 characters.')
    .max(72, 'A password is at most 72 characters.'),
});

type Values = z.infer<typeof schema>;

/**
 * The two other things this screen does since 2026-09-19, both for a
 * person who cannot sign in: **forgetting** — an address, always answered
 * "if there is an account, a link has been sent", because the server says
 * no more and neither may this — and **the link** (`?reset=`), which sets a
 * new password for a reset somebody asked for or an invitation somebody
 * never had a password for, then sends them to the form to sign in as
 * themselves. Neither signs anybody in.
 */
const forgotSchema = z.object({
  email: z.string().trim().min(1, 'Enter your email address.').email('That is not an email address.'),
});

const resetSchema = z.object({
  password: z
    .string()
    .min(12, 'A password is at least 12 characters.')
    .max(72, 'A password is at most 72 characters.'),
});

export function SignInScreen() {
  const signIn = useSignIn();
  const verification = useVerifyEmail();
  const root = useSessionStore((state) => state.root);

  // Read from the address bar rather than from the router: this screen renders
  // *instead of* the router when there is no session, so there are no route
  // params to read.
  const token = new URLSearchParams(window.location.search).get('verify');
  const resetToken = new URLSearchParams(window.location.search).get('reset');
  const [forgetting, setForgetting] = useState(false);
  const [resetDone, setResetDone] = useState(false);

  // Guarded, because React runs effects twice in development and a
  // confirmation token is single-use — the second call would answer "no longer
  // valid" about a link that had just worked.
  const attempted = useRef(false);

  useEffect(() => {
    if (token !== null && token !== '' && !attempted.current) {
      attempted.current = true;
      verification.mutate(token);
    }
  }, [token, verification]);

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '' },
  });

  if (resetToken !== null && resetToken !== '' && !resetDone) {
    return <ResetPasswordForm token={resetToken} onDone={() => setResetDone(true)} />;
  }

  if (forgetting) {
    return <ForgotPasswordForm onBack={() => setForgetting(false)} />;
  }

  return (
    <main className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-4">
      <PageHeader
        title={'Sign in'}
        description={'Use the email address your organisation was invited with.'}
      />

      {token !== null && token !== '' && (
        <p
          data-testid="verification"
          role="status"
          className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
        >
          {verification.isSuccess
            ? 'Your email address is confirmed. Sign in below.'
            : verification.isError
              ? 'That confirmation link is no longer valid — it may have been used already or expired. You can still sign in; ask for a new link from your profile.'
              : 'Confirming your email address…'}
        </p>
      )}

      <form
        className="space-y-4"
        noValidate
        onSubmit={(event) => {
          void form.handleSubmit((values) => {
            signIn.mutate(values, {
              onError: () => {
                // Cleared on failure, deliberately: a wrong password should not be
                // resubmitted by pressing Enter on a form that still looks ready,
                // and the third attempt costs a minute to the rate limiter.
                form.resetField('password');
              },
            });
          })(event);
        }}
      >
        <Field id="email" label="Email" error={form.formState.errors.email?.message}>
          {/* No autofocus, on purpose. A focused email field opens the
              browser's saved-credentials picker over the form the moment the
              page lands, and the first tap on "Sign in" then only closes the
              picker — the operator had to click a field and back before the
              button would take. Focus is the person's to give. */}
          <input
            id="email"
            type="email"
            autoComplete="username"
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
            than about one input, so it belongs to the form. The message comes
            from `queries/auth.ts`, which maps a status to one sentence — the
            server refuses to say whether it was the address or the password, and
            the screen does not invent that distinction. */}
        {signIn.error !== null && (
          <p role="alert" className="text-sm text-danger">
            {signIn.error.message}
          </p>
        )}

        <Button type="submit" pending={signIn.isPending}>
          Sign in
        </Button>
      </form>

      {resetDone && (
        <p data-testid="reset-done" role="status" className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm">
          Your password is set. Sign in with it below — every earlier session was signed out.
        </p>
      )}

      <p className="text-sm text-muted">
        <button
          type="button"
          data-testid="forgot-password"
          onClick={() => setForgetting(true)}
          className="underline underline-offset-2"
        >
          Forgot your password?
        </button>
      </p>

      {/* A plain link rather than a router one: this screen renders instead
          of the router, and the home page is the storefront the gate shows
          at the landing address. */}
      <p className="text-sm text-muted">
        <a href={withRoot(root, '/')} data-testid="sign-in-home" className="underline underline-offset-2">
          Back to the home page
        </a>
      </p>
    </main>
  );
}

function ForgotPasswordForm({ onBack }: { onBack: () => void }) {
  const forgot = useForgotPassword();
  const form = useForm<z.infer<typeof forgotSchema>>({ resolver: zodResolver(forgotSchema), defaultValues: { email: '' } });

  return (
    <main className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-4">
      <PageHeader title={'Forgot your password?'} description={'Enter your address and, if it has an account, a link to set a new one will be sent to it.'} />

      {forgot.isSuccess ? (
        // The same sentence whether or not the address exists: the server
        // says no more, and neither may this.
        <p data-testid="forgot-sent" role="status" className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm">
          If that address has an account, a link has been sent to it. It is good for thirty minutes.
        </p>
      ) : (
        <form
          className="space-y-4"
          noValidate
          onSubmit={(event) => {
            void form.handleSubmit((values) => forgot.mutate(values.email))(event);
          }}
        >
          <Field id="forgot-email" label="Email" error={form.formState.errors.email?.message}>
            <input
              id="forgot-email"
              type="email"
              autoComplete="username"
              className={inputClass(form.formState.errors.email !== undefined)}
              {...form.register('email')}
            />
          </Field>

          {forgot.error !== null && (
            <p role="alert" className="text-sm text-danger">
              {forgot.error.message}
            </p>
          )}

          <Button type="submit" pending={forgot.isPending}>
            Send the link
          </Button>
        </form>
      )}

      <p className="text-sm text-muted">
        <button type="button" onClick={onBack} className="underline underline-offset-2">
          Back to sign in
        </button>
      </p>
    </main>
  );
}

function ResetPasswordForm({ token, onDone }: { token: string; onDone: () => void }) {
  const reset = useResetPassword();
  const form = useForm<z.infer<typeof resetSchema>>({ resolver: zodResolver(resetSchema), defaultValues: { password: '' } });

  return (
    <main className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-4">
      <PageHeader title={'Choose a password'} description={'The link you followed lets you set a new password for your account. Then sign in with it.'} />

      <form
        className="space-y-4"
        noValidate
        onSubmit={(event) => {
          void form.handleSubmit((values) => reset.mutate({ token, password: values.password }, { onSuccess: onDone }))(event);
        }}
      >
        <Field id="new-password" label="New password" hint="At least 12 characters." error={form.formState.errors.password?.message}>
          <input
            id="new-password"
            type="password"
            autoComplete="new-password"
            className={inputClass(form.formState.errors.password !== undefined)}
            {...form.register('password')}
          />
        </Field>

        {reset.error !== null && (
          <p role="alert" data-testid="reset-failed" className="text-sm text-danger">
            {reset.error.message}
          </p>
        )}

        <Button type="submit" pending={reset.isPending}>
          Set the password
        </Button>
      </form>
    </main>
  );
}
