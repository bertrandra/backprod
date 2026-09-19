import { zodResolver } from '@hookform/resolvers/zod';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';

import { can } from '@/app/access/access';
import {
  useOrganisation,
  useRenameOrganisation,
  useTenantUsage,
  useUpdateOrganisation,
  type JoinPolicy,
} from '@/queries/organisation';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

/** `tenant.organisation` — the company, and its usage against quota. */
const schema = z.object({
  name: z.string().trim().min(1, 'An organisation needs a name.').max(200),
});

type Values = z.infer<typeof schema>;

const joinSchema = z.object({
  policy: z.enum(['OPEN', 'INVITATION', 'DOMAIN', 'APPROVAL']),
  domains: z.string().trim(),
});

type JoinValues = z.infer<typeof joinSchema>;

/** "acme.test, Acme.example" → the list the API takes; it lower-cases and validates. */
function splitDomains(typed: string): string[] {
  return typed
    .split(',')
    .map((domain) => domain.trim())
    .filter((domain) => domain !== '');
}

const POLICIES: readonly { value: JoinPolicy; label: string; hint: string }[] = [
  {
    value: 'OPEN',
    label: 'Anybody',
    hint: 'Whoever signs up at this address is a member at once, and can buy. The default.',
  },
  {
    value: 'APPROVAL',
    label: 'Ask an administrator',
    hint: 'Anybody may ask; an administrator accepts or declines from the Members screen.',
  },
  {
    value: 'DOMAIN',
    label: 'By email domain',
    hint: 'An address on a listed domain is in at once; any other is refused.',
  },
  {
    value: 'INVITATION',
    label: 'By invitation only',
    hint: 'Nobody arrives by themselves; administrators add people.',
  },
];

export function OrganisationScreen() {
  const session = useSession();
  const organisation = useOrganisation();
  const rename = useRenameOrganisation();
  const joining = useUpdateOrganisation();
  const usage = useTenantUsage();

  const mayManage = can(session.data, 'tenant.manage');

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: { name: organisation.data?.name ?? '' },
  });

  const joinForm = useForm<JoinValues>({
    resolver: zodResolver(joinSchema),
    values: {
      policy: organisation.data?.join_policy ?? 'OPEN',
      domains: (organisation.data?.join_domains ?? []).join(', '),
    },
  });
  const chosenPolicy = useWatch({ control: joinForm.control, name: 'policy' });

  if (organisation.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (organisation.error !== null) {
    return <ErrorSurface error={organisation.error} onRetry={() => void organisation.refetch()} />;
  }

  return (
    <div className="max-w-2xl space-y-8">
      <section className="space-y-4">
        <h1 className="text-2xl font-semibold">{t("Organisation")}</h1>

        <form
          className="space-y-4"
          onSubmit={(event) => {
            void form.handleSubmit((values) => rename.mutate(values.name))(event);
          }}
        >
          <Field id="org-name" label={t("Name")} error={form.formState.errors.name?.message}>
            <input
              id="org-name"
              className={inputClass(form.formState.errors.name !== undefined)}
              // Read-only rather than hidden for someone who may not rename it:
              // the name is worth seeing even when it is not yours to change.
              readOnly={!mayManage}
              {...form.register('name')}
            />
          </Field>

          <p className="text-xs text-muted">
            {t("Identifier")}{' '}<code className="select-all">{organisation.data?.slug}</code> {t("— set when the organisation was created and not editable.")}</p>

          {rename.error !== null && <ErrorSurface error={rename.error} />}

          {mayManage && (
            <Button type="submit" pending={rename.isPending}>
              {t("Save")}</Button>
          )}
        </form>
      </section>

      {/* How people arrive by themselves (2026-09-17). Somebody who signs
          up at this organisation's address becomes a USER of it — or asks
          to, or is refused — and this is the administrator's say in which. */}
      <section className="space-y-4" data-testid="join-policy">
        <h2 className="text-xl font-semibold">{t("Who may join")}</h2>
        <p className="text-sm text-muted">
          {t("Anybody can create an account at this organisation’s address")}{organisation.data?.slug !== undefined && (
            <>
              {' '}
              (<code>/{organisation.data.slug}/</code>)
            </>
          )}
          {t(". This decides what happens when they do.")}</p>

        <form
          className="space-y-4"
          onSubmit={(event) => {
            void joinForm.handleSubmit((values) =>
              joining.mutate({
                join_policy: values.policy,
                join_domains: values.policy === 'DOMAIN' ? splitDomains(values.domains) : [],
              }),
            )(event);
          }}
        >
          <fieldset className="space-y-2" disabled={!mayManage}>
            <legend className="text-sm font-medium">{t("Join policy")}</legend>
            {POLICIES.map((option) => (
              <label key={option.value} className="flex items-start gap-2 text-sm">
                <input
                  type="radio"
                  value={option.value}
                  className="mt-1"
                  {...joinForm.register('policy')}
                />
                <span>
                  <span className="font-medium">{t(option.label)}</span>
                  <span className="block text-xs text-muted">{t(option.hint)}</span>
                </span>
              </label>
            ))}
          </fieldset>

          {chosenPolicy === 'DOMAIN' && (
            <Field
              id="join-domains"
              label={t("Email domains")}
              hint={t("Comma separated, such as acme.example. An address on one of these is in at once; any other is refused.")}
              error={joinForm.formState.errors.domains?.message}
            >
              <input
                id="join-domains"
                className={inputClass(joinForm.formState.errors.domains !== undefined)}
                readOnly={!mayManage}
                {...joinForm.register('domains')}
              />
            </Field>
          )}

          {joining.error !== null && <ErrorSurface error={joining.error} />}

          {mayManage && (
            <Button type="submit" pending={joining.isPending}>
              {t("Save")}</Button>
          )}
        </form>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">{t("Usage")}</h2>

        {usage.isPending ? (
          <SkeletonRows rows={3} />
        ) : usage.error !== null ? (
          <ErrorSurface error={usage.error} onRetry={() => void usage.refetch()} />
        ) : usage.data.length === 0 ? (
          // Distinct from a failure: nothing metered yet is not something going
          // wrong, and an empty table would look identical to a broken one.
          <EmptyState
            title={t("Nothing metered yet")}
            description={t("Usage appears here once this organisation starts consuming a quota.")}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <tbody>
                {usage.data.map((row, index) => (
                  <tr key={index} className="border-b border-line">
                    {Object.entries(row).map(([key, value]) => (
                      <td key={key} className="py-2 pr-4 align-top">
                        <span className="text-subtle">{key.replaceAll('_', ' ')}</span>{' '}
                        <span className="font-medium">
                          {typeof value === 'string' || typeof value === 'number'
                            ? String(value)
                            : '—'}
                        </span>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
