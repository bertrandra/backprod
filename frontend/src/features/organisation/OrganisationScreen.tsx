import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { can } from '@/app/access/access';
import { useOrganisation, useRenameOrganisation, useTenantUsage } from '@/queries/organisation';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/** `tenant.organisation` — the company, and its usage against quota. */
const schema = z.object({
  name: z.string().trim().min(1, 'An organisation needs a name.').max(200),
});

type Values = z.infer<typeof schema>;

export function OrganisationScreen() {
  const session = useSession();
  const organisation = useOrganisation();
  const rename = useRenameOrganisation();
  const usage = useTenantUsage();

  const mayManage = can(session.data, 'tenant.manage');

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: { name: organisation.data?.name ?? '' },
  });

  if (organisation.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (organisation.error !== null) {
    return <ErrorSurface error={organisation.error} onRetry={() => void organisation.refetch()} />;
  }

  return (
    <div className="max-w-2xl space-y-8">
      <section className="space-y-4">
        <h1 className="text-2xl font-semibold">Organisation</h1>

        <form
          className="space-y-4"
          onSubmit={(event) => {
            void form.handleSubmit((values) => rename.mutate(values.name))(event);
          }}
        >
          <Field id="org-name" label="Name" error={form.formState.errors.name?.message}>
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
            Identifier <code className="select-all">{organisation.data?.slug}</code> — set when the
            organisation was created and not editable.
          </p>

          {rename.error !== null && <ErrorSurface error={rename.error} />}

          {mayManage && (
            <Button type="submit" pending={rename.isPending}>
              Save
            </Button>
          )}
        </form>
      </section>

      <section className="space-y-3">
        <h2 className="text-xl font-semibold">Usage</h2>

        {usage.isPending ? (
          <SkeletonRows rows={3} />
        ) : usage.error !== null ? (
          <ErrorSurface error={usage.error} onRetry={() => void usage.refetch()} />
        ) : usage.data.length === 0 ? (
          // Distinct from a failure: nothing metered yet is not something going
          // wrong, and an empty table would look identical to a broken one.
          <EmptyState
            title="Nothing metered yet"
            description="Usage appears here once this organisation starts consuming a quota."
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
