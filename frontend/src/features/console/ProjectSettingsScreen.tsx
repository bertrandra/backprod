import { useState } from 'react';

import { useProjectSettings, useSetProjectSettings, type ProjectSettings } from '@/queries/staff';
import { formatBytes } from '@/ui/Bytes';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { PageHeader } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice } from '@/ui/tone';
import { currentLocale, t } from '@/i18n';

/**
 * `console.admin.projects` — how large a project document may be
 * (2026-10-07).
 *
 * It was a constant, and every change to it was a release: 1 MiB, then 4 MiB
 * the morning Plan's schema 4 outgrew the first. The operator asked to make it
 * here instead. One limit for every product, behind `staff.products.manage`,
 * because setting the platform up is what that permission is for.
 *
 * The bounds offered are the ones the server answered, never a copy of them,
 * and so is whether the host's own upload ceiling overrules the choice: a
 * screen that compared the two would be a second answer to a question the
 * server already settled.
 */
export function ProjectSettingsScreen() {
  const settings = useProjectSettings();

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title={t('Projects')}
        description={t(
          'How large a project document may be, for every product. A save above it is refused with the limit named; projects already stored are measured again only when somebody saves them.',
        )}
      />

      {settings.error !== null ? (
        <ErrorSurface error={settings.error} onRetry={() => void settings.refetch()} />
      ) : settings.isPending ? (
        <SkeletonRows rows={3} />
      ) : (
        // Keyed by the value in force, so a save the server answered with a
        // different number resets the field to that number.
        <LimitForm key={settings.data.max_document_mib} settings={settings.data} />
      )}
    </div>
  );
}

function LimitForm({ settings }: { settings: ProjectSettings }) {
  const set = useSetProjectSettings();
  const [value, setValue] = useState(String(settings.max_document_mib));

  return (
    <section className="space-y-4" data-testid="document-limit">
      <form
        className="space-y-3"
        onSubmit={(event) => {
          event.preventDefault();
          // The field is a number input bounded by the server's own figures;
          // the server checks again, and its refusal is shown below.
          set.mutate(Number(value));
        }}
      >
        <Field
          id="max-document-mib"
          label={t('Largest project document (MB)')}
          hint={t('Between {minimum} and {maximum}. {default} while nobody has chosen.', {
            minimum: String(settings.minimum_mib),
            maximum: String(settings.maximum_mib),
            default: String(settings.default_mib),
          })}
        >
          <input
            id="max-document-mib"
            type="number"
            inputMode="numeric"
            step={1}
            min={settings.minimum_mib}
            max={settings.maximum_mib}
            required
            value={value}
            onChange={(event) => setValue(event.target.value)}
            className={inputClass()}
          />
        </Field>

        {set.error !== null && <ErrorSurface error={set.error} />}

        <Button type="submit" pending={set.isPending} data-testid="save-document-limit">
          {t('Save')}
        </Button>
      </form>

      {settings.host_overrules && settings.host_upload_bytes !== null && (
        <p role="alert" data-testid="host-overrules" className={notice('warning')}>
          {t(
            'This host accepts request bodies up to {size}, so a document near the limit would be dropped before the platform could refuse it by name. Raise post_max_size on the host, or choose a smaller limit.',
            { size: formatBytes(settings.host_upload_bytes, currentLocale()) },
          )}
        </p>
      )}
    </section>
  );
}
