import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from '@tanstack/react-router';
import { useForm } from 'react-hook-form';
import { useState } from 'react';
import { z } from 'zod';

import { useCreateProject, useProjects, useUndeleteProject } from '@/queries/projects';
import { supportedSchemaVersions, useProductConfiguration } from '@/queries/catalogue';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader } from '@/ui/Page';
import { currentLocale, t } from '@/i18n';

import { ProductCard } from './ProductCard';

/**
 * `workspace.projects` — the list a person lands on, and creating one.
 *
 * Above the list, the product this workspace belongs to: where it lives
 * when it is deployed beside the platform, and the organisation's
 * subscription to it ({@see ProductCard}).
 *
 * **The schema version is not this screen's to choose.** A new project must
 * declare one, the backend accepts only what the *product* has configured, and
 * a form that sent `1` would hard-code one product's fact into a client shared
 * by all of them (UR5). So the versions come from
 * `GET /products/{id}/configuration` and the newest is the default.
 *
 * A product that has configured none accepts no documents at all. That is the
 * backend's intended failure, not a bug to route around, so the form is not
 * shown — a create button that always produced a 422 would be worse than the
 * sentence explaining why there is none.
 *
 * **The bin is a place you go** (R13). Deleting a project used to destroy its
 * versions through a database cascade, and U4 shipped a confirmation that said
 * so honestly because it was true. It is no longer true: a deleted project keeps
 * everything and comes back. So there are two lists here rather than one list
 * with a badge on some rows — a deleted project is not something anybody is
 * browsing among their work.
 */
const schema = z.object({
  name: z.string().trim().min(1, 'A project needs a name.'),
  description: z.string().trim(),
  schema_version: z.coerce.number().int().positive(),
});

type Values = z.input<typeof schema>;

export function ProjectsScreen() {
  const { data: session } = useSession();
  const [showingBin, setShowingBin] = useState(false);
  const projects = useProjects(25, 0, showingBin);
  const undelete = useUndeleteProject();
  const configuration = useProductConfiguration(session?.productId ?? null);
  const create = useCreateProject();

  const versions = supportedSchemaVersions(configuration.data);
  const newest = versions[versions.length - 1];

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    values: {
      name: '',
      description: '',
      schema_version: newest ?? 1,
    },
  });

  if (projects.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (projects.error !== null) {
    return <ErrorSurface error={projects.error} onRetry={() => void projects.refetch()} />;
  }

  return (
    <div className="max-w-4xl space-y-6">
      <PageHeader
        title={showingBin ? t("Deleted projects") : t("Projects")}
        meta={`${String(projects.data.total)} ${showingBin ? t("deleted") : t("in this product")}`}
        actions={
          <Button
            type="button"
            variant="secondary"
            data-testid="toggle-bin"
            onClick={() => setShowingBin(!showingBin)}
          >
            {showingBin ? t("Back to projects") : t("Deleted projects")}
          </Button>
        }
      />

      {!showingBin && <ProductCard />}

      {undelete.error !== null && <ErrorSurface error={undelete.error} />}

      {projects.data.projects.length === 0 ? (
        showingBin ? (
          <EmptyState
            title={t("Nothing deleted")}
            description={t("A deleted project waits here with its versions, its assets and its jobs intact.")}
          />
        ) : (
          <EmptyState
            title={t("No projects yet")}
            description={t("Everything else in the workspace hangs off a project — start with one.")}
          />
        )
      ) : showingBin ? (
        <ul className="space-y-2">
          {projects.data.projects.map((project) => (
            <li
              key={project.id}
              data-project={project.id}
              data-deleted="true"
              className="flex flex-wrap items-center gap-3 rounded-card border border-line bg-surface p-4 shadow-raise"
            >
              <span className="min-w-0 flex-1">
                <span className="block font-medium">{project.name}</span>
                <span data-testid="deleted-at" className="text-xs text-subtle">
                  {/* Not a link: a deleted project has no screen to open, and a
                      row that looked clickable and was not would be worse than
                      one that plainly is not. */}
                  {t("deleted")}{' '}
                  {project.deleted_at === null
                    ? t("at some point")
                    : new Date(project.deleted_at).toLocaleString(currentLocale())}
                </span>
              </span>

              <Button
                type="button"
                pending={undelete.isPending && undelete.variables === project.id}
                onClick={() => undelete.mutate(project.id)}
              >
                {t("Put it back")}</Button>
            </li>
          ))}
        </ul>
      ) : (
        <ul className="space-y-2">
          {projects.data.projects.map((project) => (
            <li key={project.id} data-project={project.id}>
              <Link
                to="/projects/$projectId"
                params={{ projectId: project.id }}
                className="block rounded-card border border-line bg-surface p-4 shadow-raise hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 dark:hover:bg-inverse"
              >
                <span className="block font-medium">{project.name}</span>
                {project.description !== null && (
                  <span className="block truncate text-sm text-muted">
                    {project.description}
                  </span>
                )}
                <span className="text-xs text-subtle">
                  {t("schema v")}{project.schema_version} {t("· updated")}{' '}
                  {new Date(project.updated_at).toLocaleString(currentLocale())}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {!showingBin && (
      <section className="space-y-3 border-t border-line pt-6">
        <h2 className="text-xl font-semibold">{t("New project")}</h2>

        {configuration.isPending ? (
          <SkeletonRows rows={2} />
        ) : versions.length === 0 ? (
          // Said plainly rather than shown as a failure, because nothing has
          // failed: this product has not declared which document shapes it
          // accepts, and until it does there is nothing valid to send.
          <EmptyState
            title={t("This product accepts no project documents yet")}
            description={t("No document schema version is configured for it, so a new project could not be stored. An administrator configures this on the product.")}
          />
        ) : (
          <form
            className="max-w-md space-y-4"
            onSubmit={(event) => {
              void form.handleSubmit((values) =>
                create.mutate(
                  {
                    name: values.name,
                    description: values.description.trim() === '' ? null : values.description,
                    schema_version: Number(values.schema_version),
                    // An empty document, because the shape belongs to the Core
                    // and not to this form. The canvas fills it in; inventing
                    // keys here would be the frontend deciding what a project
                    // *is* (§4).
                    document: {},
                  },
                  { onSuccess: () => form.reset() },
                ),
              )(event);
            }}
          >
            <Field id="project-name" label={t("Name")} error={form.formState.errors.name?.message}>
              <input
                id="project-name"
                className={inputClass(form.formState.errors.name !== undefined)}
                {...form.register('name')}
              />
            </Field>

            <Field id="project-description" label={t("Description")} hint={t("Optional.")}>
              <input
                id="project-description"
                className={inputClass()}
                {...form.register('description')}
              />
            </Field>

            {versions.length > 1 ? (
              <Field
                id="project-schema"
                label={t("Document schema")}
                hint={t("What this product accepts. The newest is chosen by default.")}
              >
                <select
                  id="project-schema"
                  className={inputClass()}
                  {...form.register('schema_version')}
                >
                  {versions.map((version) => (
                    <option key={version} value={version}>
                      v{version}
                    </option>
                  ))}
                </select>
              </Field>
            ) : (
              // One choice is not a choice. Shown, because the version ends up
              // on the row and someone reading it later should know where it
              // came from.
              <p className="text-sm text-muted">
                {t("Document schema v{version} — the only version this product accepts.", { version: newest ?? '' })}
              </p>
            )}

            {create.error !== null && <ErrorSurface error={create.error} />}

            <Button type="submit" pending={create.isPending}>
              {t("Create project")}</Button>
          </form>
        )}
      </section>
      )}
    </div>
  );
}
