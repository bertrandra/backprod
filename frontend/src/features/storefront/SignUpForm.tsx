import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useSignUp } from '@/queries/auth';
import type { PublicOffer } from '@/queries/storefront';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { PageHeader } from '@/ui/Page';

/**
 * Creating an account, in the middle of buying something.
 *
 * **Four fields, two of them optional.** Everything a purchase genuinely
 * needs and nothing a form designer would add: an address to sign in with, a
 * password, a company name when there is one, and a country because VAT
 * depends on it. A consumer buying for themselves leaves the company blank
 * and the organisation takes their own name — the B2C case stays as short as
 * it can be, and nothing records which of the two it was.
 *
 * **The offer is shown while they type.** Somebody filling in a form has
 * stopped looking at the price they chose, and a purchase that turns out to
 * cost something else is the complaint this avoids.
 *
 * **The address is not confirmed first.** They buy now and confirm from an
 * email afterwards: an interrupted purchase is a purchase that does not
 * happen, and a verification link in a spam folder is not something to put
 * between somebody and a subscription.
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
  organisation: z.string().trim().max(200, 'That name is too long.'),
  country: z
    .string()
    .trim()
    .regex(/^([A-Za-z]{2})?$/, 'Use a two-letter country code, such as FR.')
    .max(2),
});

type Values = z.infer<typeof schema>;

export function SignUpForm({
  offer,
  productCode,
  onCreated,
  onBack,
  onSignIn,
}: {
  offer: PublicOffer;
  productCode: string;
  /** The tenant the account now owns, so the caller can start the checkout. */
  onCreated: (tenantId: string) => void;
  onBack: () => void;
  onSignIn: () => void;
}) {
  const signUp = useSignUp();

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '', display_name: '', organisation: '', country: '' },
  });

  return (
    <main className="mx-auto max-w-sm space-y-6 p-4 py-10">
      <PageHeader
        title={'Create your account'}
        description={'You will be able to pay straight after. We will email you a link to confirm your address — your account works in the meantime.'}
      />

      <div
        data-testid="chosen-offer"
        className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
      >
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <span className="font-medium">{offer.name}</span>
          {offer.version !== null && <Amount money={offer.version.price} className="font-medium" />}
        </div>
        <button
          type="button"
          data-testid="choose-another"
          onClick={onBack}
          className="mt-1 text-xs underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
        >
          Choose something else
        </button>
      </div>

      <form
        className="space-y-4"
        noValidate
        onSubmit={(event) => {
          void form.handleSubmit((values) => {
            signUp.mutate(
              {
                email: values.email,
                password: values.password,
                product: productCode,
                // Empty is absent, not an empty name. The API treats a blank
                // string as "not given" too, but sending one would be the
                // screen asserting something it does not mean.
                display_name: values.display_name === '' ? null : values.display_name,
                organisation: values.organisation === '' ? null : values.organisation,
                country: values.country === '' ? null : values.country.toUpperCase(),
              },
              {
                onSuccess: (created) => onCreated(created.tenant_id),
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

        <Field
          id="signup-organisation"
          label="Company (optional)"
          hint="Leave it empty if you are buying for yourself — your invoice will be in your own name."
          error={form.formState.errors.organisation?.message}
        >
          <input
            id="signup-organisation"
            type="text"
            autoComplete="organization"
            className={inputClass(form.formState.errors.organisation !== undefined)}
            {...form.register('organisation')}
          />
        </Field>

        <Field
          id="signup-country"
          label="Country (optional)"
          hint="Two letters, such as FR. It decides the VAT on your invoice."
          error={form.formState.errors.country?.message}
        >
          <input
            id="signup-country"
            type="text"
            autoComplete="country"
            maxLength={2}
            className={inputClass(form.formState.errors.country !== undefined)}
            {...form.register('country')}
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
          Create account and continue
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
      </p>
    </main>
  );
}
