import { zodResolver } from '@hookform/resolvers/zod';
import { useRef } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { can, isEntitled } from '@/app/access/access';
import { useSession } from '@/queries/session';
import { LOGO_TYPES, useDeleteLogo, useSkin, useUpdateSkin, useUploadLogo } from '@/queries/skin';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `tenant.branding` — the screen U2 exists to build.
 *
 * **Two gates that do not imply each other**, and the reason this area is
 * scheduled early: `skin.manage` says this person may configure the tenant, and
 * the `white_label` entitlement says the tenant's plan includes the feature. One
 * refusal is answered by an administrator and the other by an upgrade, so they
 * must read differently — a single "access denied" would send half the people
 * who see it to the wrong place.
 *
 * Reading needs neither (ui-spec.md §3.4): a client must know how to render
 * itself before it knows what the tenant bought.
 */
const HEX = /^#[0-9a-f]{6}$/;

const schema = z.object({
  // Lower-case hex, because the database refuses anything else — these values end
  // up in a stylesheet and a colour column accepting arbitrary text is a
  // stylesheet injection with extra steps.
  primary: z.string().regex(HEX, 'Use lower-case hex, like #1a2b3c.').or(z.literal('')),
  accent: z.string().regex(HEX, 'Use lower-case hex, like #1a2b3c.').or(z.literal('')),
});

type Values = z.infer<typeof schema>;

export function BrandingScreen() {
  const session = useSession();
  const skin = useSkin();
  const update = useUpdateSkin();
  const upload = useUploadLogo();
  const remove = useDeleteLogo();
  const fileInput = useRef<HTMLInputElement>(null);

  const mayManage = can(session.data, 'skin.manage');
  const entitled = isEntitled(session.data, 'white_label');

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: {
      primary: skin.data?.primary_color ?? '',
      accent: skin.data?.accent_color ?? '',
    },
  });

  if (skin.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (skin.error !== null) {
    return <ErrorSurface error={skin.error} onRetry={() => void skin.refetch()} />;
  }

  const writable = mayManage && entitled;

  return (
    <div className="max-w-lg space-y-6">
      <h1 className="text-lg font-semibold">Branding</h1>

      {/* The two refusals, told apart. Shown above the form rather than instead
          of it, because the current values are worth seeing either way. */}
      {!entitled && (
        <EmptyState
          title="Your plan does not include white labelling"
          description="This one is answered by an upgrade rather than by an administrator of your organisation."
        />
      )}

      {entitled && !mayManage && (
        <EmptyState
          title="You may not change the branding"
          description="An administrator of your organisation can grant this."
        />
      )}

      <form
        className="space-y-4"
        onSubmit={(event) => {
          void form.handleSubmit((values) =>
            update.mutate({
              // Empty clears it: the contract accepts null to mean "use the
              // product's defaults", which is different from leaving it alone.
              primary_color: values.primary === '' ? null : values.primary,
              accent_color: values.accent === '' ? null : values.accent,
            }),
          )(event);
        }}
      >
        {(['primary', 'accent'] as const).map((which) => (
          <Field
            key={which}
            id={`${which}-color`}
            label={which === 'primary' ? 'Primary colour' : 'Accent colour'}
            hint="Lower-case hex, like #1a2b3c. Leave empty to use the product's default."
            error={form.formState.errors[which]?.message}
          >
            <div className="flex items-center gap-2">
              <input
                id={`${which}-color`}
                className={inputClass(form.formState.errors[which] !== undefined)}
                placeholder="#1a2b3c"
                readOnly={!writable}
                {...form.register(which)}
              />
              <span
                aria-hidden="true"
                data-testid={`${which}-swatch`}
                className="size-9 shrink-0 rounded border border-neutral-300 dark:border-neutral-700"
                style={{ backgroundColor: HEX.test(form.watch(which)) ? form.watch(which) : undefined }}
              />
            </div>
          </Field>
        ))}

        {update.error !== null && <ErrorSurface error={update.error} />}

        {writable && (
          <Button type="submit" pending={update.isPending}>
            Save colours
          </Button>
        )}
      </form>

      <section className="space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
        <h2 className="text-base font-semibold">Logo</h2>

        <p className="text-sm text-neutral-600 dark:text-neutral-400">
          {skin.data.logo_asset_id === null
            ? 'No logo. The product’s own is used.'
            : 'A logo is set.'}
        </p>

        {upload.error !== null && <ErrorSurface error={upload.error} />}
        {remove.error !== null && <ErrorSurface error={remove.error} />}

        {writable && (
          <div className="flex flex-wrap items-center gap-3">
            <input
              ref={fileInput}
              type="file"
              // The contract's own list. SVG is deliberately absent — ADR-028
              // refuses it for the stored-scripting reason.
              accept={LOGO_TYPES.join(',')}
              aria-label="Choose a logo image"
              className="text-sm"
              onChange={(event) => {
                const file = event.target.files?.[0];

                if (file !== undefined) {
                  upload.mutate(file, {
                    // Cleared either way, so a failed upload can be retried with
                    // the same file — a file input that keeps its value will not
                    // fire change again for it.
                    onSettled: () => {
                      if (fileInput.current !== null) {
                        fileInput.current.value = '';
                      }
                    },
                  });
                }
              }}
            />

            {upload.isPending && <span role="status">Uploading…</span>}

            {skin.data.logo_asset_id !== null && (
              <Button
                type="button"
                variant="danger"
                pending={remove.isPending}
                onClick={() => remove.mutate()}
              >
                Remove logo
              </Button>
            )}
          </div>
        )}
      </section>
    </div>
  );
}
