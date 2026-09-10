import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from '@tanstack/react-router';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useCreateProject, useProjects } from '@/queries/projects';
import { supportedSchemaVersions, useProductConfiguration } from '@/queries/products';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `workspace.projects` — the list a person lands on, and creating one.
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
 */
const schema = z.object({
  name: z.string().trim().min(1, 'A project needs a name.'),
  description: z.string().trim(),
  schema_version: z.coerce.number().int().positive(),
});

type Values = z.input<typeof schema>;

export function ProjectsScreen() {
  const { data: session } = useSession();
  const projects = useProjects();
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
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-lg font-semibold">Projects</h1>
        <span className="text-sm text-neutral-600 dark:text-neutral-400">
          {projects.data.total} in this product
        </span>
      </div>

      {projects.data.projects.length === 0 ? (
        <EmptyState
          title="No projects yet"
          description="Everything else in the workspace hangs off a project — start with one."
        />
      ) : (
        <ul className="space-y-2">
          {projects.data.projects.map((project) => (
            <li key={project.id} data-project={project.id}>
              <Link
                to="/projects/$projectId"
                params={{ projectId: project.id }}
                className="block rounded border border-neutral-200 p-3 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-neutral-800 dark:hover:bg-neutral-900"
              >
                <span className="block font-medium">{project.name}</span>
                {project.description !== null && (
                  <span className="block truncate text-sm text-neutral-600 dark:text-neutral-400">
                    {project.description}
                  </span>
                )}
                <span className="text-xs text-neutral-500">
                  schema v{project.schema_version} · updated{' '}
                  {new Date(project.updated_at).toLocaleString()}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      <section className="space-y-3 border-t border-neutral-200 pt-6 dark:border-neutral-800">
        <h2 className="text-base font-semibold">New project</h2>

        {configuration.isPending ? (
          <SkeletonRows rows={2} />
        ) : versions.length === 0 ? (
          // Said plainly rather than shown as a failure, because nothing has
          // failed: this product has not declared which document shapes it
          // accepts, and until it does there is nothing valid to send.
          <EmptyState
            title="This product accepts no project documents yet"
            description="No document schema version is configured for it, so a new project could not be stored. An administrator configures this on the product."
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
            <Field id="project-name" label="Name" error={form.formState.errors.name?.message}>
              <input
                id="project-name"
                className={inputClass(form.formState.errors.name !== undefined)}
                {...form.register('name')}
              />
            </Field>

            <Field id="project-description" label="Description" hint="Optional.">
              <input
                id="project-description"
                className={inputClass()}
                {...form.register('description')}
              />
            </Field>

            {versions.length > 1 ? (
              <Field
                id="project-schema"
                label="Document schema"
                hint="What this product accepts. The newest is chosen by default."
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
              <p className="text-sm text-neutral-600 dark:text-neutral-400">
                Document schema v{newest} — the only version this product accepts.
              </p>
            )}

            {create.error !== null && <ErrorSurface error={create.error} />}

            <Button type="submit" pending={create.isPending}>
              Create project
            </Button>
          </form>
        )}
      </section>
    </div>
  );
}
