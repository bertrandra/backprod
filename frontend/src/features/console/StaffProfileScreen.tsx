import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useStaffIdentity, useUpdateStaffProfile } from '@/queries/staff';
import { Button, Field, inputClass } from '@/ui/Field';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';
import { currentLocale, isLocale, LOCALE_NAMES, LOCALES, setLocale, t } from '@/i18n';

import { NewPasswordByMail } from '../account/NewPasswordByMail';

/**
 * `console.self` — a platform staff member's own profile (2026-09-22).
 *
 * `account.profile` is a tenant screen: it reads `/me`, which resolves a
 * product and a membership, and platform staff with no membership are
 * refused there. So the platform administrator — the one person who set the
 * platform up — had no way to their own name or language. This is the same
 * two fields, read from and written to `/staff/me`, which needs nothing but
 * being that person.
 *
 * No default product: that is a choice among memberships, and somebody
 * with none has nothing to choose from. A person who is both a member and
 * staff has `/profile` for it.
 *
 * The 120-character bound is the contract's `maxLength`, restated because
 * a form validates before it sends.
 */
const schema = z.object({
  displayName: z.string().trim().max(120, 'A display name is at most 120 characters.'),
  locale: z.enum(LOCALES),
});

type Values = z.infer<typeof schema>;

export function StaffProfileScreen() {
  const identity = useStaffIdentity();
  const update = useUpdateStaffProfile();

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: {
      displayName: identity.data?.displayName ?? '',
      locale: isLocale(identity.data?.locale) ? identity.data.locale : currentLocale(),
    },
  });

  if (identity.isPending) {
    return <SkeletonRows rows={3} />;
  }

  if (identity.error !== null) {
    return <ErrorSurface error={identity.error} onRetry={() => void identity.refetch()} />;
  }

  return (
    <div className="max-w-lg space-y-4">
      <PageHeader
        title={t("Your profile")}
        description={identity.data.email ?? t("No email address on this account.")}
      />

      <form
        className="space-y-4"
        onSubmit={(event) => {
          void form.handleSubmit((values) => {
            // Empty means "clear it": an absent field leaves the name alone, null
            // removes it, and an empty string would store a name that is no name.
            update.mutate(
              {
                display_name: values.displayName === '' ? null : values.displayName,
                locale: values.locale,
              },
              // Applied the moment it is saved: the console re-renders in the
              // language just chosen.
              { onSuccess: () => void setLocale(values.locale) },
            );
          })(event);
        }}
      >
        <Field
          id="staff-display-name"
          label={t("Display name")}
          hint={t("Shown beside your initial in the top bar, and in the access log against what you read. Leave empty to remove it.")}
          error={form.formState.errors.displayName?.message}
        >
          <input
            id="staff-display-name"
            className={inputClass(form.formState.errors.displayName !== undefined)}
            {...form.register('displayName')}
          />
        </Field>

        <Field
          id="staff-locale"
          label={t("Language")}
          hint={t("The language your screens and the platform's mails use.")}
          error={form.formState.errors.locale?.message}
        >
          <select id="staff-locale" data-testid="locale" className={inputClass(form.formState.errors.locale !== undefined)} {...form.register('locale')}>
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
            {t("Save")}
          </Button>

          {update.isSuccess && !form.formState.isDirty && (
            <span role="status" className="text-sm text-muted">
              {t("Saved")}
            </span>
          )}
        </div>
      </form>

      {/* The same section as the tenant profile, and deliberately the same
          mechanism: staff sign in with an address and a password like
          anybody else, and the link that replaces one is the account's, not
          the authority's. */}
      <NewPasswordByMail email={identity.data.email ?? null} />

      <p className="text-xs text-subtle">
        {t("Your platform roles:")}{' '}{identity.data.roles.join(', ')}
      </p>
    </div>
  );
}
