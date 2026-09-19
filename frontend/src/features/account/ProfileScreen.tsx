import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useUpdateProfile } from '@/queries/account';
import { useSignOut } from '@/queries/auth';
import { useMyProducts } from '@/queries/catalogue';
import { useSession } from '@/queries/session';
import { Button, Field, inputClass } from '@/ui/Field';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { SkeletonRows } from '@/ui/Skeleton';
import { currentLocale, isLocale, LOCALE_NAMES, LOCALES, setLocale, t } from '@/i18n';

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
  // A code among the products this person holds, or none.
  defaultProduct: z.string(),
  // The language they read in (ADR-050): one the platform speaks.
  locale: z.enum(LOCALES),
});

type Values = z.infer<typeof schema>;

export function ProfileScreen() {
  const session = useSession();
  const update = useUpdateProfile();
  const signOut = useSignOut();
  const mine = useMyProducts();

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: {
      displayName: session.data?.displayName ?? '',
      defaultProduct: mine.data?.default ?? '',
      locale: isLocale(session.data?.locale) ? session.data.locale : currentLocale(),
    },
  });

  if (session.isPending) {
    return <SkeletonRows rows={3} />;
  }

  if (session.error !== null) {
    return <ErrorSurface error={session.error} onRetry={() => void session.refetch()} />;
  }

  return (
    <div className="max-w-lg space-y-4">
      <h1 className="text-2xl font-semibold">{t("Your profile")}</h1>

      <p className="text-sm text-muted">
        {session.data?.email ?? t("No email address on this account.")}
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
            update.mutate(
              {
                display_name: values.displayName === '' ? null : values.displayName,
                default_product: values.defaultProduct === '' ? null : values.defaultProduct,
                locale: values.locale,
              },
              // Applied the moment it is saved: the whole page re-renders in
              // the language just chosen.
              { onSuccess: () => void setLocale(values.locale) },
            );
          })(event);
        }}
      >
        <Field
          id="display-name"
          label={t("Display name")}
          hint={t("Shown to other members of your organisation. Leave empty to remove it.")}
          error={form.formState.errors.displayName?.message}
        >
          <input
            id="display-name"
            className={inputClass(form.formState.errors.displayName !== undefined)}
            {...form.register('displayName')}
          />
        </Field>

        {/* Where your screens open. Set to the product signed up for, and only
            ever one you hold — the list is what the server says you have.
            Offered as a choice even with one product, so "where do I land"
            has a visible answer. */}
        <Field
          id="default-product"
          label={t("Default product")}
          hint={t("The product your screens open in when a link does not say which.")}
          error={form.formState.errors.defaultProduct?.message}
        >
          <select
            id="default-product"
            data-testid="default-product"
            className={inputClass(form.formState.errors.defaultProduct !== undefined)}
            disabled={mine.data === undefined}
            {...form.register('defaultProduct')}
          >
            <option value="">{t("Whichever comes first")}</option>
            {(mine.data?.products ?? []).map((product) => (
              <option key={product.code} value={product.code}>
                {product.name}
              </option>
            ))}
          </select>
        </Field>

        <Field
          id="locale"
          label={t("Language")}
          hint={t("The language your screens and the platform's mails use.")}
          error={form.formState.errors.locale?.message}
        >
          <select id="locale" data-testid="locale" className={inputClass(form.formState.errors.locale !== undefined)} {...form.register('locale')}>
            {LOCALES.map((code) => (
              <option key={code} value={code}>
                {LOCALE_NAMES[code]}
              </option>
            ))}
          </select>
        </Field>

        {update.error !== null && <ErrorSurface error={update.error} />}

        <div className="flex items-center gap-3">
          <Button type="submit" pending={update.isPending}>
            {t("Save")}</Button>

          {update.isSuccess && !form.formState.isDirty && (
            <span role="status" className="text-sm text-muted">
              {t("Saved")}</span>
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
          {t("Sign out")}</Button>
      </div>
    </div>
  );
}
