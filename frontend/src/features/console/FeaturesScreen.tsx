import { useState } from 'react';

import {
  useCreateFeature,
  usePlatformFeatures,
  useRenameFeature,
  type PlatformFeature,
} from '@/queries/staff';
import { TranslatedField, type Translated } from '@/ui/TranslatedField';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader, Section } from '@/ui/Page';
import { FormCard } from '@/ui/Form';
import { t } from '@/i18n';

/**
 * `console.admin.features` — the platform's one list of features
 * (2026-09-24, `docs/translatable-fields-spec.md` §4).
 *
 * A feature is not a thing a product owns; it is a word the platform and a
 * product's code have agreed on. `max_projects` existed once per product
 * until this screen, and the code that reads it — the workspace's quota, a
 * subscription's seat count — depended on every one of those rows having
 * been seeded with the same spelling and the same kind.
 *
 * **No product anywhere on this screen**, which is the whole point. What
 * belongs to a product is the *grant*: which of these an offer includes,
 * and at what limit. That is Console → Catalogue.
 *
 * **Nothing is deleted.** A feature some offer version grants can never be
 * removed — the entitlements resting on it are what customers are paying
 * for — so the list retires instead, and a retired row stays visible with
 * its code still taken.
 */
export function FeaturesScreen() {
  const features = usePlatformFeatures();

  if (features.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (features.error !== null) {
    return <ErrorSurface error={features.error} onRetry={() => void features.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={t("Features")}
        description={t("What a product may sell, platform-wide. A quota is counted in a unit; a switch is on or off. The kind cannot be changed afterwards — every grant written against a feature meant one or the other, and flipping it would reinterpret prices somebody is already paying.")}
      />

      <Section
        title={t("The list")}
        description={t("Every product's catalogue picks from this list, so a code means the same thing everywhere. A feature is never deleted: retire it instead, and everybody already entitled keeps what they bought.")}
      >
        {features.data.length === 0 ? (
          <EmptyState
            title={t("No features yet")}
            description={t("An offer can be sold without them — they are what a plan grants beyond access to the product.")}
          />
        ) : (
          <ul className="space-y-2" data-testid="feature-list">
            {features.data.map((feature) => (
              <FeatureRow key={feature.id} feature={feature} />
            ))}
          </ul>
        )}
      </Section>

      <NewFeature />
    </div>
  );
}

function NewFeature() {
  const create = useCreateFeature();

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [kind, setKind] = useState<'BOOLEAN' | 'QUOTA'>('QUOTA');
  const [unit, setUnit] = useState('');

  return (
    <Section
      className="border-t border-line pt-6"
      title={t("Add a feature")}
      description={t("The code is what a product's own code gates on, so it is chosen once and never changed. The name and its translations can be corrected afterwards.")}
    >
      {create.error !== null && <ErrorSurface error={create.error} />}

      <FormCard
        className="grid gap-3 sm:grid-cols-[1fr_1fr_8rem_1fr_auto] sm:items-end"
        onSubmit={(event) => {
          event.preventDefault();

          if (code.trim() !== '' && name.trim() !== '') {
            create.mutate(
              {
                code: code.trim().toLowerCase(),
                name: name.trim(),
                kind,
                // Null rather than "", and never on a switch: the API refuses
                // a unit on a boolean and the form should not send one.
                unit: kind === 'QUOTA' && unit.trim() !== '' ? unit.trim() : null,
              },
              {
                onSuccess: () => {
                  setCode('');
                  setName('');
                  setUnit('');
                },
              },
            );
          }
        }}
      >
        <Field id="feature-code" label={t("Feature code")}>
          <input
            id="feature-code"
            className={inputClass()}
            placeholder="projects"
            value={code}
            onChange={(event) => setCode(event.target.value)}
          />
        </Field>
        <Field id="feature-name" label={t("Feature name")}>
          <input
            id="feature-name"
            className={inputClass()}
            placeholder={t("Projects")}
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Field id="feature-kind" label={t("Kind")}>
          <select
            id="feature-kind"
            className={inputClass()}
            value={kind}
            onChange={(event) => setKind(event.target.value === 'BOOLEAN' ? 'BOOLEAN' : 'QUOTA')}
          >
            <option value="QUOTA">{t("Quota")}</option>
            <option value="BOOLEAN">{t("Switch")}</option>
          </select>
        </Field>
        <Field id="feature-unit" label={t("Unit")}>
          <input
            id="feature-unit"
            className={inputClass()}
            placeholder="projects"
            // Disabled rather than hidden, so the rule is visible instead of
            // being discovered through a 400.
            disabled={kind === 'BOOLEAN'}
            value={kind === 'BOOLEAN' ? '' : unit}
            onChange={(event) => setUnit(event.target.value)}
          />
        </Field>
        <Button
          type="submit"
          pending={create.isPending}
          disabled={code.trim() === '' || name.trim() === ''}
        >
          {t("Add feature")}</Button>
      </FormCard>
    </Section>
  );
}

/**
 * One feature: what it is called in each language, what it says, and
 * whether it may still be granted.
 *
 * Editing is behind a click because naming a feature is done once and
 * corrected rarely, and a list of open forms is a screen nobody can scan.
 *
 * The code and the kind are shown and never editable: the code is how
 * grants and entitlements name this, and the kind decides how every grant
 * already written against it is read.
 */
function FeatureRow({ feature }: { feature: PlatformFeature }) {
  const update = useRenameFeature();
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState<Translated>(() => nameOf(feature));
  const [description, setDescription] = useState<Translated>(() => descriptionOf(feature));

  const reopen = () => {
    setName(nameOf(feature));
    setDescription(descriptionOf(feature));
    setEditing(!editing);
  };

  return (
    <li
      data-feature={feature.code}
      data-active={feature.active ? 'true' : 'false'}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-medium">{feature.name}</span>
        <code className="select-all text-xs text-muted">{feature.code}</code>
        <span className="text-xs text-subtle">
          {feature.kind === 'QUOTA' ? t("quota in {value}", { value: feature.unit ?? '—' }) : t("switch")}
        </span>
        {!feature.active && (
          <span
            data-testid={`retired-${feature.code}`}
            className="rounded-control border border-line px-2 py-0.5 text-xs text-subtle"
          >
            {t("retired")}</span>
        )}

        <Button
          type="button"
          variant="secondary"
          data-testid={`rename-${feature.code}`}
          onClick={reopen}
        >
          {t("Rename")}</Button>

        {/* Retiring is a separate act from correcting the wording, and reads
            as one: an offer written after this cannot grant it. */}
        <Button
          type="button"
          variant="secondary"
          pending={update.isPending}
          data-testid={`retire-${feature.code}`}
          onClick={() =>
            update.mutate({ featureId: feature.id, name: feature.name, active: !feature.active })
          }
        >
          {feature.active ? t("Retire") : t("Reinstate")}</Button>
      </div>

      {feature.description !== null && feature.description !== undefined && (
        <p className="mt-1 text-xs text-muted">{feature.description}</p>
      )}

      {editing && (
        <form
          className="mt-3 space-y-3"
          data-testid={`rename-form-${feature.code}`}
          onSubmit={(event) => {
            event.preventDefault();

            if (name.en.trim() === '') {
              return;
            }

            update.mutate(
              {
                featureId: feature.id,
                name: name.en.trim(),
                description: description.en.trim() === '' ? null : description.en.trim(),
                translations: translationsOf(name, description),
              },
              { onSuccess: () => setEditing(false) },
            );
          }}
        >
          <Field id={`feature-name-${feature.code}`} label={t("Feature name")}>
            <TranslatedField id={`feature-name-${feature.code}`} value={name} onChange={setName} />
          </Field>
          <Field id={`feature-description-${feature.code}`} label={t("Description")}>
            <TranslatedField
              id={`feature-description-${feature.code}`}
              value={description}
              onChange={setDescription}
            />
          </Field>

          {update.error !== null && <ErrorSurface error={update.error} />}

          <div className="flex flex-wrap gap-2">
            <Button type="submit" pending={update.isPending}>
              {t("Save")}</Button>
            <Button type="button" variant="secondary" onClick={() => setEditing(false)}>
              {t("Cancel")}</Button>
          </div>
        </form>
      )}
    </li>
  );
}

/** The row's five names, as the field edits them. */
function nameOf(feature: PlatformFeature): Translated {
  const translations = feature.translations ?? {};

  return {
    en: feature.name,
    fr: translations.fr?.name ?? '',
    es: translations.es?.name ?? '',
    de: translations.de?.name ?? '',
    it: translations.it?.name ?? '',
  };
}

/** The row's five descriptions, the same way. */
function descriptionOf(feature: PlatformFeature): Translated {
  const translations = feature.translations ?? {};

  return {
    en: feature.description ?? '',
    fr: translations.fr?.description ?? '',
    es: translations.es?.description ?? '',
    de: translations.de?.description ?? '',
    it: translations.it?.description ?? '',
  };
}

/**
 * The two fields, back into the one map the API takes.
 *
 * Merged here rather than sent as two calls: the server replaces the set,
 * so a second call carrying only descriptions would erase the names it did
 * not mention. One call, one transaction — the same reason the English and
 * its translations travel together.
 *
 * A language that says nothing in either field is left out, which is how a
 * translation is removed.
 */
function translationsOf(
  name: Translated,
  description: Translated,
): Record<string, { name: string | null; description: string | null }> {
  const translations: Record<string, { name: string | null; description: string | null }> = {};

  for (const code of ['fr', 'es', 'de', 'it'] as const) {
    const written = (name[code] ?? '').trim();
    const said = (description[code] ?? '').trim();

    if (written !== '' || said !== '') {
      translations[code] = { name: written === '' ? null : written, description: said === '' ? null : said };
    }
  }

  return translations;
}
