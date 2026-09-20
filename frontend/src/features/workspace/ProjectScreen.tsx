import { zodResolver } from '@hookform/resolvers/zod';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import {
  useCreateProjectVersion,
  useDeleteProject,
  useDuplicateProject,
  useProject,
  useProjectVersions,
  useRestoreProject,
  useUpdateProject,
} from '@/queries/projects';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

import { AssetsPanel } from './AssetsPanel';
import { ProjectCanvas } from './ProjectCanvas';
import { currentLocale, t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * `workspace.project` — one project: rename, duplicate, snapshot, restore,
 * delete, its files and its canvas.
 *
 * **Restore means restoring to a version. Undeleting is a different operation**,
 * and now it exists. U4 shipped this screen against a *hard* delete — versions
 * went with the project through `ON DELETE CASCADE` — so the confirmation said
 * what was true then: permanent, unrecoverable, type the name. The mismatch with
 * the roadmap's exit criterion was filed as R13 rather than papered over, and
 * R13 has been closed: deletion is a date now, the versions stay, and
 * `POST /projects/{projectId}/undelete` puts the project back.
 *
 * So the confirmation says what is true *now*. The typed name stays — deleting
 * still takes a project out of everybody's list and out of every link, which
 * deserves more than one click — but it no longer claims to destroy history it
 * does not destroy. A warning that overstates is a warning people learn to
 * dismiss.
 *
 * Restoring is safe in a way worth showing: the backend snapshots the current
 * state first, in the same transaction, so restoring can never be the operation
 * that loses work.
 */
const renameSchema = z.object({
  name: z.string().trim().min(1, 'A project needs a name.'),
  description: z.string().trim(),
});

export function ProjectScreen({ projectId }: { projectId: string }) {
  const navigate = useNavigate();
  const project = useProject(projectId);
  const versions = useProjectVersions(projectId);
  const update = useUpdateProject(projectId);
  const snapshot = useCreateProjectVersion(projectId);
  const restore = useRestoreProject(projectId);
  const duplicate = useDuplicateProject();
  const remove = useDeleteProject();

  const [confirmName, setConfirmName] = useState('');
  const [deleting, setDeleting] = useState(false);
  const [label, setLabel] = useState('');

  const form = useForm<z.infer<typeof renameSchema>>({
    resolver: zodResolver(renameSchema),
    // `values` rather than `defaultValues`: the project arrives after the first
    // render, and defaults would leave the form permanently empty.
    values: {
      name: project.data?.name ?? '',
      description: project.data?.description ?? '',
    },
  });

  if (project.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (project.error !== null) {
    return <ErrorSurface error={project.error} onRetry={() => void project.refetch()} />;
  }

  const current = project.data;

  return (
    <div className="max-w-4xl space-y-8">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">{current.name}</h1>
        <p className="text-xs text-subtle">
          {t("schema v")}{current.schema_version} {t("· updated")}{' '}{new Date(current.updated_at).toLocaleString(currentLocale())}
        </p>
      </header>

      <section className="space-y-4">
        <h2 className="text-xl font-semibold">{t("Details")}</h2>

        <form
          className="max-w-md space-y-4"
          onSubmit={(event) => {
            void form.handleSubmit((values) =>
              update.mutate({
                name: values.name,
                description: values.description.trim() === '' ? null : values.description,
              }),
            )(event);
          }}
        >
          <Field id="name" label={t("Name")} error={form.formState.errors.name?.message}>
            <input
              id="name"
              className={inputClass(form.formState.errors.name !== undefined)}
              {...form.register('name')}
            />
          </Field>

          <Field id="description" label={t("Description")}>
            <input id="description" className={inputClass()} {...form.register('description')} />
          </Field>

          {update.error !== null && <ErrorSurface error={update.error} />}

          <div className="flex flex-wrap gap-2">
            <Button type="submit" pending={update.isPending}>
              {t("Save")}</Button>
            <Button
              type="button"
              variant="secondary"
              pending={duplicate.isPending}
              onClick={() =>
                duplicate.mutate(projectId, {
                  onSuccess: (copy) => {
                    void navigate({
                      to: '/projects/$projectId',
                      params: { projectId: copy.id },
                    });
                  },
                })
              }
            >
              {t("Duplicate")}</Button>
          </div>

          {duplicate.error !== null && <ErrorSurface error={duplicate.error} />}
        </form>
      </section>

      <section className="space-y-3 border-t border-line pt-6">
        <h2 className="text-xl font-semibold">{t("History")}</h2>

        <div className="flex flex-wrap items-end gap-2">
          <Field id="version-label" label={t("Label")} hint={t("Optional — why this snapshot exists.")}>
            <input
              id="version-label"
              className={inputClass()}
              value={label}
              onChange={(event) => setLabel(event.target.value)}
            />
          </Field>
          <Button
            type="button"
            pending={snapshot.isPending}
            onClick={() =>
              snapshot.mutate(label.trim() === '' ? null : label, {
                onSuccess: () => setLabel(''),
              })
            }
          >
            {t("Take a snapshot")}</Button>
        </div>

        {snapshot.error !== null && <ErrorSurface error={snapshot.error} />}
        {restore.error !== null && <ErrorSurface error={restore.error} />}

        {versions.isPending ? (
          <SkeletonRows rows={3} />
        ) : versions.error !== null ? (
          <ErrorSurface error={versions.error} onRetry={() => void versions.refetch()} />
        ) : versions.data.length === 0 ? (
          <EmptyState
            title={t("No versions yet")}
            description={t("A snapshot records the project as it is now, so you can come back to it.")}
          />
        ) : (
          <ul className="space-y-2">
            {versions.data.map((version) => (
              <li
                key={version.id}
                data-version={version.id}
                className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm md:flex md:items-center md:gap-3"
              >
                <div className="min-w-0 md:flex-1">
                  <p className="font-medium">
                    v{version.version_number}
                    {version.label !== null && ` — ${version.label}`}
                  </p>
                  <p className="text-xs text-muted">
                    {version.name} · {new Date(version.created_at).toLocaleString(currentLocale())}
                  </p>
                </div>

                <Button
                  type="button"
                  variant="secondary"
                  pending={restore.isPending}
                  onClick={() => restore.mutate(version.id)}
                >
                  {t("Restore")}</Button>
              </li>
            ))}
          </ul>
        )}

        <p className="text-xs text-muted">
          {t("Restoring snapshots the current state first, so it can never be the step that loses work.")}</p>
      </section>

      <ProjectCanvas projectName={current.name} />

      <div className="border-t border-line pt-6">
        <AssetsPanel projectId={projectId} />
      </div>

      <section className="space-y-3 border-t border-line pt-6">
        <h2 className="text-xl font-semibold">{t("Delete this project")}</h2>

        {/* Recoverable, and said so (R13). It used to be a hard delete with the
            versions cascading behind it, and the wording here said exactly that;
            keeping that wording now would be scaring somebody with a
            consequence that no longer happens. */}
        <p data-testid="delete-explanation" className="text-sm text-muted">
          {tx(
            (versions.data?.length ?? 0) === 1
              ? "The project leaves your list and keeps everything — its {count} snapshot, its files and its jobs. You can put it back from {where}."
              : "The project leaves your list and keeps everything — its {count} snapshots, its files and its jobs. You can put it back from {where}.",
            { count: versions.data?.length ?? 0, where: <strong>{t("Deleted projects")}</strong> },
          )}
        </p>

        {deleting ? (
          <div className="max-w-md space-y-3">
            <Field
              id="confirm-name"
              label={t("Type “{name}” to confirm", { name: current.name })}
              hint={t("Recoverable, but it leaves every list and every link — so more than one click.")}
            >
              <input
                id="confirm-name"
                className={inputClass()}
                value={confirmName}
                onChange={(event) => setConfirmName(event.target.value)}
              />
            </Field>

            {remove.error !== null && <ErrorSurface error={remove.error} />}

            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="danger"
                pending={remove.isPending}
                disabled={confirmName !== current.name}
                onClick={() =>
                  remove.mutate(projectId, {
                    onSuccess: () => {
                      void navigate({ to: '/projects' });
                    },
                  })
                }
              >
                {t("Delete permanently")}</Button>
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  setDeleting(false);
                  setConfirmName('');
                }}
              >
                {t("Cancel")}</Button>
            </div>
          </div>
        ) : (
          <Button type="button" variant="danger" onClick={() => setDeleting(true)}>
            {t("Delete project")}</Button>
        )}
      </section>
    </div>
  );
}
