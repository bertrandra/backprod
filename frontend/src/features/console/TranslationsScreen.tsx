import { useMemo, useState } from 'react';

import {
  useRenameFeature,
  useRenameStaffOffer,
  useTranslations,
  type TranslatableText,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { PageHeader, Section } from '@/ui/Page';
import { currentLocale, LOCALE_NAMES, LOCALES, t, type LocaleCode } from '@/i18n';

/**
 * `console.admin.translations` — everything the operator wrote, in one place,
 * one language at a time (2026-09-26).
 *
 * The console could already translate each of these rows: a feature on the
 * Features screen, an offer in a product's Catalogue, each behind the form that
 * owns it and each showing all five languages at once. What no screen could
 * answer is the question somebody asks when a language is half finished —
 * *what is missing in Italian?* — because the answer spans tables that have
 * nothing else in common. So this screen turns the axes round: one language,
 * every sentence, the English beside each box.
 *
 * **This is the operator's words, not the application's.** Field labels,
 * buttons, hints and error wording live in `src/i18n/catalogues/*.json`, keyed
 * by the English and proved complete by `gate:i18n` (ADR-050,
 * `docs/translatable-fields-spec.md` §1.5). They are not on this screen and
 * this screen is not a reason to move them: the test that separates the two is
 * whether the sentence would be identical on every deployment of this platform.
 *
 * **It writes through the operations that own each row** — `renameFeature`,
 * `renameStaffOffer` — which already carry the permission, the validation and
 * the trail. A second way to write the same table is the drift the gates spend
 * their time preventing, so there is no `saveTranslation` and there must not be.
 *
 * Both of those operations **replace** the translation set, so a language left
 * out is a language removed. That is what {@link translationsOfFeature} is for,
 * and it is also why a card is always given its record whole even when the
 * filter is showing one of its sentences: a feature's name and its description
 * are two rows on this desk and one field in that call, and saving the name
 * while sending no description would erase a description somebody translated
 * last week.
 *
 * The product showcase is not here. Its bands are a JSON object per block whose
 * fields differ by kind, its write replaces the whole story at once, and it
 * answers to `staff.products.manage` rather than the catalogue's permission —
 * three differences, each of which has to be reasoned about rather than
 * pattern-matched. It stays on the product's own Story screen.
 */
export function TranslationsScreen() {
  const texts = useTranslations();

  // The language the operator reads in is the one they are most likely to be
  // filling — and English is the key rather than a translation, so somebody
  // reading in English starts on French instead of on a column of boxes they
  // are not allowed to edit. `openOn` on TranslatedField exists for the
  // mirror-image reason.
  const [locale, setLocale] = useState<LocaleCode>(() => {
    const reading = currentLocale();

    return reading === 'en' ? 'fr' : reading;
  });
  const [search, setSearch] = useState('');
  const [onlyMissing, setOnlyMissing] = useState(false);

  const records = useMemo(() => group(texts.data ?? []), [texts.data]);

  const shown = useMemo(
    () =>
      records
        .map((record) => ({ record, sentences: narrow(record, locale, search, onlyMissing) }))
        .filter((candidate) => candidate.sentences.length > 0),
    [records, locale, search, onlyMissing],
  );

  if (texts.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (texts.error !== null) {
    return <ErrorSurface error={texts.error} onRetry={() => void texts.refetch()} />;
  }

  const sentences = records.reduce((count, record) => count + record.sentences.length, 0);
  const missing = records.reduce(
    (count, record) => count + record.sentences.filter((text) => !has(text, locale)).length,
    0,
  );

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={t("Translations")}
        description={t("What you called the things you sell, in the languages a customer reads them in. The English stays on the row itself: it is the key, and it is what somebody reads in a language nobody has written yet.")}
      />

      <Section
        title={t("One language at a time")}
        description={t("Every other screen shows one row in five languages. This one shows every row in one language, because finishing a language is the job no screen could show the shape of.")}
      >
        <div className="grid gap-3 sm:grid-cols-[12rem_1fr] sm:items-end">
          <Field id="translation-locale" label={t("Language")}>
            <select
              id="translation-locale"
              className={inputClass()}
              data-testid="translation-locale"
              value={locale}
              onChange={(event) => setLocale(asLocale(event.target.value))}
            >
              {/* English is absent on purpose: it is the key and the fallback,
                  and correcting it is a rename of the row rather than a
                  translation of it. That is the owning screen's job. */}
              {LOCALES.filter((code) => code !== 'en').map((code) => (
                <option key={code} value={code}>
                  {LOCALE_NAMES[code]}
                </option>
              ))}
            </select>
          </Field>

          <Field id="translation-search" label={t("Search")}>
            <input
              id="translation-search"
              className={inputClass()}
              // The read already holds every sentence, so the list narrows on
              // each keystroke. Nothing to debounce, no button to press, and no
              // request per character.
              type="search"
              data-testid="translation-search"
              placeholder={t("A word in any language, or a code")}
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </Field>
        </div>

        <label className="mt-3 flex items-center gap-2 text-sm text-muted">
          <input
            type="checkbox"
            data-testid="translation-only-missing"
            checked={onlyMissing}
            onChange={(event) => setOnlyMissing(event.target.checked)}
          />
          {t("Only what this language is missing")}
        </label>

        {/* The tally counts the whole set and not the filtered one, because the
            number somebody came here for is how much is left — a count that
            shrank as they typed would answer a question nobody asked. */}
        <p className="mt-3 text-xs text-subtle" data-testid="translation-tally">
          {t("{shown} of {total} shown · {missing} without a translation in {language}", {
            shown: String(shown.reduce((count, candidate) => count + candidate.sentences.length, 0)),
            total: String(sentences),
            missing: String(missing),
            language: LOCALE_NAMES[locale],
          })}
        </p>
      </Section>

      {shown.length === 0 ? (
        <EmptyState
          title={sentences === 0 ? t("Nothing is named yet") : t("Nothing matches")}
          description={
            sentences === 0
              ? t("A feature or an offer written on its own screen appears here the moment it exists.")
              : t("No sentence contains that, in any language. Clear the search to see the rest.")
          }
        />
      ) : (
        <ul className="space-y-4" data-testid="translation-list">
          {shown.map(({ record, sentences: showing }) =>
            record.kind === 'feature' ? (
              <FeatureRecord
                // Keyed by the language too, so switching it remounts the card
                // and drops what was typed. Kept, a French draft would show
                // under a Spanish label and save as Spanish.
                key={`feature:${record.id}:${locale}`}
                record={record}
                showing={showing}
                locale={locale}
              />
            ) : (
              <OfferRecord
                key={`offer:${record.id}:${locale}`}
                record={record}
                showing={showing}
                locale={locale}
              />
            ),
          )}
        </ul>
      )}
    </div>
  );
}

/**
 * One thing the operator named, with every sentence it carries.
 *
 * Grouped although the read is flat and the counts are per sentence: the write
 * is per *record* and it replaces the translation set, so a card that did not
 * hold both of a feature's sentences would delete one of them.
 */
type Grouped = {
  readonly kind: TranslatableText['kind'];
  readonly id: string;
  readonly code: string;
  readonly product: string | null;
  readonly sentences: readonly TranslatableText[];
};

/** What has been typed and not yet saved, by field. */
type Draft = Readonly<Partial<Record<string, string>>>;

/** The read's rows, back into the records the writes take. */
function group(texts: readonly TranslatableText[]): readonly Grouped[] {
  const records: Grouped[] = [];
  const at = new Map<string, number>();

  for (const text of texts) {
    const key = `${text.kind}:${text.id}`;
    const found = at.get(key);
    const record = found === undefined ? undefined : records[found];

    if (found === undefined || record === undefined) {
      at.set(key, records.length);
      records.push({
        kind: text.kind,
        id: text.id,
        code: text.code,
        product: text.product ?? null,
        sentences: [text],
      });

      continue;
    }

    records[found] = { ...record, sentences: [...record.sentences, text] };
  }

  return records;
}

/** Whether a sentence says anything in that language yet. */
function has(text: TranslatableText, locale: LocaleCode): boolean {
  return (text.translations[locale] ?? '').trim() !== '';
}

/**
 * Which of a record's sentences the filter leaves on screen — empty when the
 * record is out altogether.
 *
 * The search reads the code, the product, the English and **every** language,
 * not only the one being edited: somebody who half-remembers a French word is
 * looking for the row it is on, and a filter that searched only the chosen
 * language would hide that row from them while showing it under a different
 * choice.
 */
function narrow(
  record: Grouped,
  locale: LocaleCode,
  search: string,
  onlyMissing: boolean,
): readonly TranslatableText[] {
  const wanted = search.trim().toLowerCase();

  return record.sentences.filter((text) => {
    if (onlyMissing && has(text, locale)) {
      return false;
    }

    if (wanted === '') {
      return true;
    }

    return [text.code, record.product ?? '', text.source, ...Object.values(text.translations)].some(
      (said) => said.toLowerCase().includes(wanted),
    );
  });
}

/** What the sentence's own box is labelled. */
function fieldLabel(field: string): string {
  return field === 'description' ? t("Description") : t("Name");
}

/** The chooser's value, back to a locale the rest of the screen can use. */
function asLocale(value: string): LocaleCode {
  return LOCALES.find((code) => code === value) ?? 'fr';
}

/**
 * A feature: the platform's own vocabulary, so no product is named (ADR-052).
 *
 * Both of its sentences travel in one call, which is what the shared draft is
 * for: `renameFeature` replaces the translation set, and two calls would each
 * erase what the other wrote.
 */
function FeatureRecord({
  record,
  showing,
  locale,
}: {
  record: Grouped;
  showing: readonly TranslatableText[];
  locale: LocaleCode;
}) {
  const update = useRenameFeature();
  const [draft, setDraft] = useState<Draft>({});

  const english = record.sentences.find((text) => text.field === 'name')?.source ?? '';

  return (
    <RecordCard
      record={record}
      showing={showing}
      locale={locale}
      draft={draft}
      setDraft={setDraft}
      pending={update.isPending}
      error={update.error}
      // The English name goes back unchanged because the operation requires it,
      // not because this screen edits it: correcting the English is the
      // Features screen's job, where the key and the fallback are the subject.
      disabled={english === ''}
      onSave={(next) =>
        update.mutate(
          {
            featureId: record.id,
            name: english,
            translations: translationsOfFeature(record, locale, next),
          },
          { onSuccess: () => setDraft({}) },
        )
      }
    />
  );
}

/**
 * An offer: named within a product, so the product travels with the write.
 *
 * `renameStaffOffer` takes it as a query parameter — a staff route resolves no
 * product of its own, because a platform role grants no membership — and an
 * offer's code is unique only within one, which is why it is also on screen.
 */
function OfferRecord({
  record,
  showing,
  locale,
}: {
  record: Grouped;
  showing: readonly TranslatableText[];
  locale: LocaleCode;
}) {
  // `?? ''` never runs: `offers.product_id` is NOT NULL and the read joins
  // through it. Save is disabled rather than the row hidden, so an impossible
  // row would be visible and unwritable instead of quietly absent.
  const update = useRenameStaffOffer(record.product ?? '');
  const [draft, setDraft] = useState<Draft>({});

  const english = record.sentences.find((text) => text.field === 'name')?.source ?? '';

  return (
    <RecordCard
      record={record}
      showing={showing}
      locale={locale}
      draft={draft}
      setDraft={setDraft}
      pending={update.isPending}
      error={update.error}
      disabled={record.product === null || english === ''}
      onSave={(next) =>
        update.mutate(
          {
            offerId: record.id,
            name: english,
            translations: translationsOfOffer(record, locale, next),
          },
          { onSuccess: () => setDraft({}) },
        )
      }
    />
  );
}

/**
 * One record's card: what it is, and a box per sentence with the English above
 * it.
 *
 * The English is text and not an input. It is the key every translation hangs
 * off and the fallback a customer reads in a language nobody wrote, so editing
 * it here would look like a translation and behave like a rename of the row.
 */
function RecordCard({
  record,
  showing,
  locale,
  draft,
  setDraft,
  pending,
  error,
  disabled,
  onSave,
}: {
  record: Grouped;
  showing: readonly TranslatableText[];
  locale: LocaleCode;
  draft: Draft;
  setDraft: (next: Draft) => void;
  pending: boolean;
  error: unknown;
  disabled: boolean;
  onSave: (draft: Draft) => void;
}) {
  const dirty = Object.keys(draft).length > 0;

  return (
    <li
      data-record={`${record.kind}:${record.code}`}
      className="rounded-card border border-line bg-surface p-4 text-sm shadow-raise"
    >
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-medium">
          {record.kind === 'feature' ? t("Feature") : t("Offer")}
        </span>
        <code className="select-all text-xs text-muted">{record.code}</code>
        {record.product !== null && (
          <span
            data-testid={`product-of-${record.code}`}
            className="rounded-control border border-line px-2 py-0.5 text-xs text-subtle"
          >
            {record.product}
          </span>
        )}
      </div>

      <form
        className="mt-3 space-y-4"
        data-testid={`translate-${record.kind}-${record.code}`}
        onSubmit={(event) => {
          event.preventDefault();
          onSave(draft);
        }}
      >
        {showing.map((text) => {
          const id = `translation-${record.kind}-${record.code}-${text.field}`;
          const saved = text.translations[locale] ?? '';

          return (
            <div key={text.field} data-sentence={text.field}>
              {/* The English and the box, one above the other and always both:
                  translating from memory is how a catalogue ends up saying two
                  different things in two languages. */}
              <p className="text-xs uppercase tracking-wide text-subtle">
                {fieldLabel(text.field)}
              </p>
              <p className="mt-0.5 text-sm text-muted" data-testid={`${id}-source`}>
                {text.source === '' ? (
                  <span data-testid={`${id}-no-source`} className="italic">
                    {t("The English was cleared and this translation was left behind.")}
                  </span>
                ) : (
                  text.source
                )}
              </p>

              <div className="mt-1">
                <label className="sr-only" htmlFor={id}>
                  {t("{field} in {language}", {
                    field: fieldLabel(text.field),
                    language: LOCALE_NAMES[locale],
                  })}
                </label>
                <input
                  id={id}
                  className={inputClass()}
                  data-testid={id}
                  data-missing={saved.trim() === '' ? 'true' : 'false'}
                  placeholder={text.source}
                  value={draft[text.field] ?? saved}
                  onChange={(event) => setDraft({ ...draft, [text.field]: event.target.value })}
                />
              </div>
            </div>
          );
        })}

        {error !== null && error !== undefined && <ErrorSurface error={error} />}

        <div className="flex flex-wrap gap-2">
          <Button
            type="submit"
            pending={pending}
            disabled={disabled || !dirty}
            data-testid={`save-${record.kind}-${record.code}`}
          >
            {t("Save")}
          </Button>
          {dirty && (
            <Button type="button" variant="secondary" onClick={() => setDraft({})}>
              {t("Cancel")}
            </Button>
          )}
        </div>
      </form>
    </li>
  );
}

/**
 * A feature's whole translation set, with one language's boxes replaced.
 *
 * Every language and **both** fields, because the operation replaces the set: a
 * call carrying only French would delete the Spanish, and a call carrying only
 * the name would delete the description. A language that says nothing in either
 * field is left out, which is how one is removed.
 */
function translationsOfFeature(
  record: Grouped,
  editing: LocaleCode,
  draft: Draft,
): Record<string, { name: string | null; description: string | null }> {
  const translations: Record<string, { name: string | null; description: string | null }> = {};

  for (const code of LOCALES) {
    if (code === 'en') {
      continue;
    }

    const name = said(record, 'name', code, editing, draft);
    const description = said(record, 'description', code, editing, draft);

    if (name !== '' || description !== '') {
      translations[code] = {
        name: name === '' ? null : name,
        description: description === '' ? null : description,
      };
    }
  }

  return translations;
}

/** An offer's, the same way — it has a name and no description. */
function translationsOfOffer(
  record: Grouped,
  editing: LocaleCode,
  draft: Draft,
): Record<string, { name: string | null }> {
  const translations: Record<string, { name: string | null }> = {};

  for (const code of LOCALES) {
    if (code === 'en') {
      continue;
    }

    const name = said(record, 'name', code, editing, draft);

    if (name !== '') {
      translations[code] = { name };
    }
  }

  return translations;
}

/**
 * What one field says in one language once the draft is applied.
 *
 * The draft only ever holds the language on screen — the card is keyed by it,
 * so switching remounts and drops what was typed — so every other language
 * reads straight off what the server last answered.
 */
function said(
  record: Grouped,
  field: string,
  locale: string,
  editing: LocaleCode,
  draft: Draft,
): string {
  const sentence = record.sentences.find((text) => text.field === field);

  if (sentence === undefined) {
    return '';
  }

  const drafted = draft[field];

  return (
    locale === editing && drafted !== undefined ? drafted : (sentence.translations[locale] ?? '')
  ).trim();
}
