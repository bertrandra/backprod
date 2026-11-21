import { useState } from 'react';

import {
  useProductConfiguration,
  useProductManifest,
  useSetProjectSchemaVersions,
} from '@/queries/staff';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { FieldCell, FieldGroup, FieldRow, FormActions, FormCard } from '@/ui/Form';
import { Section } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice } from '@/ui/tone';
import { t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * The document versions one product accepts, on that product's own row.
 *
 * **Moved here from the invoicing screen on 2026-11-20.** It sat there because
 * `showStaffConfiguration` is the read that carries the list, which is a fact
 * about the API and not about where somebody looks: when Plan started saving
 * schema 4 and every save was refused, the operator went to the product, found
 * nothing, and was right. A list that decides whether a product's saves are
 * taken at all belongs with the product.
 *
 * It still reads the configuration desk — the list is stored there — and still
 * writes through the one `PUT` that owns it, so the invoicing screen and this
 * one share a cache entry rather than holding two answers.
 *
 * Mounted only once its disclosure is open (`ProductsScreen`), like the keys
 * and the story: every product on the screen fetching its configuration and
 * asking its own host for a manifest would be a console that took seconds to
 * draw a list of names.
 */
export function ProductDocumentVersions({ productCode }: { productCode: string }) {
  const configuration = useProductConfiguration(productCode);

  if (configuration.isPending) {
    return <SkeletonRows rows={2} />;
  }

  if (configuration.error !== null) {
    return <ErrorSurface error={configuration.error} onRetry={() => void configuration.refetch()} />;
  }

  return (
    <SchemaVersionsForm
      productCode={productCode}
      initial={configuration.data.project_schema_versions}
    />
  );
}

/**
 * Which document versions the product takes (non-negotiable #10).
 *
 * **Chips and a number, not a comma-separated box.** A text field would make
 * the screen parse what the operator typed, and the one thing that must not
 * differ between here and the server is what counts as a version — `"2"` is
 * refused there precisely so a product never displays a version it refuses.
 * Entering one at a time leaves nothing to parse.
 *
 * The empty state gets an alert of its own rather than a quiet form, because it
 * is the state of every product the console has ever created and it looks
 * exactly like a screen that has finished loading.
 */
function SchemaVersionsForm({
  productCode,
  initial,
}: {
  productCode: string;
  initial: number[];
}) {
  const save = useSetProjectSchemaVersions(productCode);

  const [versions, setVersions] = useState<number[]>(initial);
  const [entry, setEntry] = useState('');
  const parsed = Number(entry);
  // What the server would take: a whole number of 1 or more that is not already
  // in the list. `Number('')` is 0 and `Number('2.5')` is not an integer, so
  // both fail here for the same reason they would fail there.
  const addable =
    entry.trim() !== '' && Number.isInteger(parsed) && parsed >= 1 && !versions.includes(parsed);

  function add() {
    if (!addable) {
      return;
    }

    setVersions([...versions, parsed].sort((left, right) => left - right));
    setEntry('');
  }

  return (
    <Section
      description={t("Which versions of this product's project documents the platform will store. A product that accepts none refuses every save, which is the state of one nobody has configured — creating a product writes no configuration at all.")}
    >
      {save.error !== null && <ErrorSurface error={save.error} />}

      <WhatTheProductDeclares
        productCode={productCode}
        versions={versions}
        onAdd={(adding) =>
          setVersions([...versions, ...adding].sort((left, right) => left - right))
        }
      />

      {versions.length === 0 && (
        <p data-testid="accepts-no-version" role="alert" className={notice('danger')}>
          {tx("This product accepts no document version, so every project saved against it refuses with {code}. Add the versions its releases write.", {
            code: <code>UNSUPPORTED_SCHEMA_VERSION</code>,
          })}
        </p>
      )}

      <FormCard
        onSubmit={(event) => {
          event.preventDefault();
          save.mutate(versions);
        }}
      >
        <FieldGroup
          legend="The versions"
          hint={t("Keep the versions earlier releases wrote, not only the newest: a document written last month is still one its owner opens, and dropping its version refuses their own work.")}
        >
          <ul data-testid="schema-versions" className="flex flex-wrap gap-2">
            {versions.map((version) => (
              <li
                key={version}
                data-version={version}
                className="flex items-center gap-2 rounded-control border border-line bg-well px-3 py-1.5 text-sm"
              >
                <span className="tabular-nums">{version}</span>
                <button
                  type="button"
                  aria-label={t("Remove version {version}", { version: String(version) })}
                  className="text-muted underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
                  onClick={() => setVersions(versions.filter((kept) => kept !== version))}
                >
                  {t("Remove")}</button>
              </li>
            ))}
          </ul>

          <FieldRow>
            <FieldCell width="short">
              <Field id={`schema-version-${productCode}`} label={t("Add a version")}>
                <input
                  id={`schema-version-${productCode}`}
                  type="number"
                  min={1}
                  step={1}
                  inputMode="numeric"
                  className={inputClass()}
                  value={entry}
                  onChange={(event) => setEntry(event.target.value)}
                  onKeyDown={(event) => {
                    // Enter adds the version rather than submitting the form:
                    // typing a number and pressing Enter must not save a list
                    // that does not yet contain it.
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      add();
                    }
                  }}
                />
              </Field>
            </FieldCell>

            <FieldCell>
              <Button type="button" variant="secondary" disabled={!addable} onClick={add}>
                {t("Add")}</Button>
            </FieldCell>
          </FieldRow>
        </FieldGroup>

        <FormActions>
          {/* Disabled on an empty list as a courtesy only — the server refuses
              it either way, and the frontend is never the authority. */}
          <Button type="submit" pending={save.isPending} disabled={versions.length === 0}>
            {t("Save the accepted versions")}</Button>
        </FormActions>
      </FormCard>
    </Section>
  );
}

/**
 * What the product itself says it accepts.
 *
 * The list above is a copy, made by hand, of a fact the product owns — it
 * writes the migrations and ships the spec. The copy has fallen behind twice,
 * and both times every save was refused `UNSUPPORTED_SCHEMA_VERSION` and it
 * looked like a bug in the product. This is where the two can be seen at once.
 *
 * **It proposes and never applies.** Pressing the button puts the missing
 * versions into the list above, and saving is still the form's own button — so
 * what gets stored is what the operator can see, and a remote file never
 * decides a list the platform is the authority over. It is also why nothing
 * here offers to *remove* a version the product no longer names: retiring one
 * refuses edits on documents customers already hold, which is a decision
 * somebody makes rather than one a fetch suggests.
 *
 * **Silence is the ordinary answer.** Most products serve no manifest, and a
 * product that runs inside this shell has no address to ask. Neither is a
 * fault, and showing a red failure for each would teach an operator to ignore
 * the one that matters — so only `WRONG_PRODUCT` is called out, because it
 * means an `app_url` is pointing at somebody else.
 */
function WhatTheProductDeclares({
  productCode,
  versions,
  onAdd,
}: {
  productCode: string;
  versions: number[];
  onAdd: (adding: number[]) => void;
}) {
  const asked = useProductManifest(productCode);

  if (asked.isPending) {
    return <SkeletonRows rows={1} />;
  }

  // The request itself failed — not the product's silence, which arrives as a
  // 200 with an `error` code. A console whose own call broke says so.
  if (asked.error !== null || asked.data === undefined) {
    return <ErrorSurface error={asked.error} />;
  }

  const { declared, error, adds, product } = asked.data;

  if (declared === null) {
    return (
      <p
        data-testid="product-declares-nothing"
        className={notice(error === 'WRONG_PRODUCT' ? 'danger' : 'neutral')}
      >
        {error === 'WRONG_PRODUCT'
          ? tx("The host at {url} serves a manifest for a different product. Check this product's address before trusting anything else on this screen.", {
              url: <code>{product.app_url ?? ''}</code>,
            })
          : t("This product does not say which versions it accepts. The list below is the only answer, and it has to be kept by hand.")}{' '}
        <RefetchManifest asked={asked} />
      </p>
    );
  }

  // Already in the list the operator is editing, which is not the same as
  // already stored: they may have added it a moment ago and not saved yet.
  const outstanding = adds.filter((version) => !versions.includes(version));

  return (
    <div data-testid="product-manifest" className={notice(outstanding.length > 0 ? 'warning' : 'neutral')}>
      <p>
        {tx("{name} declares {versions}{release}.", {
          name: <strong>{product.name}</strong>,
          versions: <span data-testid="declared-versions">{declared.schema_versions.join(', ')}</span>,
          release: declared.app_version === null ? '' : ` (${declared.app_version})`,
        })}{' '}
        {outstanding.length === 0
          ? t("The list already covers it.")
          : t("The list below does not cover all of it, so a save in a version it is missing is refused.")}{' '}
        <RefetchManifest asked={asked} />
      </p>

      {outstanding.length > 0 && (
        <p className="mt-3">
          <Button
            type="button"
            variant="secondary"
            data-testid="adopt-declared-versions"
            onClick={() => onAdd(outstanding)}
          >
            {t("Add {versions} to the list", { versions: outstanding.join(', ') })}</Button>
        </p>
      )}
    </div>
  );
}

/** Asking again, for the minute after a release of the product goes out. */
function RefetchManifest({ asked }: { asked: ReturnType<typeof useProductManifest> }) {
  return (
    <button
      type="button"
      data-testid="ask-the-product-again"
      disabled={asked.isFetching}
      className="text-muted underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
      onClick={() => void asked.refetch()}
    >
      {asked.isFetching ? t("Asking…") : t("Ask again")}</button>
  );
}
