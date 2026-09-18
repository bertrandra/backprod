import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useSignUp, type CreatedAccount } from '@/queries/auth';
import type { PublicOffer, PublicTenant } from '@/queries/storefront';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { PageHeader } from '@/ui/Page';

/**
 * Asking to join the organisation whose root this is.
 *
 * **Three fields, one of them optional.** Since 2026-09-17 a sign-up makes
 * no organisation and no administrator: the person becomes a USER of the
 * organisation at this address (docs/tenant-roots.md §2.4), so there is no
 * company to name and no country to ask for — the organisation has both.
 * An address to sign in with, a password, and a name to be known by.
 *
 * **The offer, when they came from one, stays in front of them** — and is
 * bought there and then (2026-09-18): a USER holds `billing.pay`, so the
 * checkout that follows is the ordinary authenticated one, on the session
 * the sign-up issued.
 *
 * **Whether they are in at once or waiting is the organisation's policy**,
 * and the public tenant says which before they type: under `APPROVAL` the
 * form says an administrator accepts first and the page goes to the root
 * to wait; otherwise it says they can pay straight after, and does.
 *
 * **The address is not confirmed first.** They are in (or waiting) now and
 * confirm from an email afterwards.
 */
const schema = z.object({
  email: z
    .string()
    .trim()
    .min(1, 'Enter your email address.')
    .email('That is not an email address.')
    .max(320, 'That address is too long.'),
  // The contract's bounds, restated because a form has to check before it
  // sends. 72 is bcrypt's ceiling, not a preference. Not trimmed: a password
  // may legitimately begin or end with a space.
  password: z
    .string()
    .min(12, 'A password is at least 12 characters.')
    .max(72, 'A password is at most 72 characters.'),
  display_name: z.string().trim().max(200, 'That name is too long.'),
});

type Values = z.infer<typeof schema>;

export function SignUpForm({
  tenant,
  offer,
  productCode,
  onCreated,
  onBack,
  onSignIn,
}: {
  /** The organisation at this root — the one being asked. */
  tenant: PublicTenant;
  /** The offer they came from, if they came from one; shown, not bought. */
  offer: PublicOffer | null;
  productCode: string | null;
  onCreated: (created: CreatedAccount) => void;
  onBack: () => void;
  onSignIn: () => void;
}) {
  const signUp = useSignUp();
  // Under APPROVAL a newcomer waits; under anything else that admits them
  // they are in at once. INVITATION refuses, and the API says so on submit.
  const waits = tenant.join_policy === 'APPROVAL';

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '', display_name: '' },
  });

  return (
    <main className="mx-auto max-w-sm space-y-6 p-4 py-10">
      <PageHeader
        title={`Join ${tenant.name}`}
        description={
          waits
            ? 'An administrator of the organisation accepts new members; you will be told by email. We will also send you a link to confirm your address.'
            : offer === null
              ? 'You will be a member straight away. We will email you a link to confirm your address — your account works in the meantime.'
              : 'You will be able to pay straight after. We will email you a link to confirm your address — your account works in the meantime.'
        }
      />

      {offer !== null && (
        <div
          data-testid="chosen-offer"
          className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
        >
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="font-medium">{offer.name}</span>
            {offer.version !== null && <Amount money={offer.version.price} className="font-medium" />}
          </div>
          <p className="mt-1 text-xs text-muted">
            {waits
              ? `What you came for. Once an administrator of ${tenant.name} has accepted you, it is one click away in the catalogue.`
              : `What you came for, taken out for ${tenant.name}.`}
          </p>
          <button
            type="button"
            data-testid="choose-another"
            onClick={onBack}
            className="mt-1 text-xs underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            Back to the catalogue
          </button>
        </div>
      )}

      <form
        className="space-y-4"
        noValidate
        onSubmit={(event) => {
          void form.handleSubmit((values) => {
            signUp.mutate(
              {
                email: values.email,
                password: values.password,
                tenant: tenant.slug,
                product: productCode,
                // Empty is absent, not an empty name. The API treats a blank
                // string as "not given" too, but sending one would be the
                // screen asserting something it does not mean.
                display_name: values.display_name === '' ? null : values.display_name,
              },
              {
                onSuccess: onCreated,
                onError: () => form.resetField('password'),
              },
            );
          })(event);
        }}
      >
        <Field id="signup-email" label="Email" error={form.formState.errors.email?.message}>
          <input
            id="signup-email"
            type="email"
            autoComplete="username"
            autoFocus
            className={inputClass(form.formState.errors.email !== undefined)}
            {...form.register('email')}
          />
        </Field>

        <Field
          id="signup-password"
          label="Password"
          hint="At least 12 characters."
          error={form.formState.errors.password?.message}
        >
          <input
            id="signup-password"
            type="password"
            autoComplete="new-password"
            className={inputClass(form.formState.errors.password !== undefined)}
            {...form.register('password')}
          />
        </Field>

        <Field id="signup-name" label="Your name" error={form.formState.errors.display_name?.message}>
          <input
            id="signup-name"
            type="text"
            autoComplete="name"
            className={inputClass(form.formState.errors.display_name !== undefined)}
            {...form.register('display_name')}
          />
        </Field>

        {/* Announced, and about the attempt rather than one field. The
            sentence comes from `queries/auth.ts`, which is the one place that
            turns a status into words. */}
        {signUp.error !== null && (
          <p role="alert" className="text-sm text-danger">
            {signUp.error.message}
          </p>
        )}

        <Button type="submit" pending={signUp.isPending}>
          {offer !== null && !waits ? 'Create account and continue' : 'Create account and join'}
        </Button>
      </form>

      <p className="text-sm text-muted">
        Already have an account?{' '}
        <button
          type="button"
          data-testid="sign-in-link"
          onClick={onSignIn}
          className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
        >
          Sign in
        </button>
        {offer === null && (
          <>
            {' · '}
            <button
              type="button"
              data-testid="back-to-catalogue"
              onClick={onBack}
              className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
            >
              Back to the catalogue
            </button>
          </>
        )}
      </p>
    </main>
  );
}
