import { useState } from 'react';

import { useProductDemonstrations, useSetProductDemonstrations } from '@/queries/staff';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Section } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * Where a product keeps its demonstrations (2026-11-22).
 *
 * **Two paths, and what they find.** The page editor offers a product's
 * demonstrations by name where a band embeds a live view; this is where the
 * platform is told how to ask for them. Both paths sit under the product's
 * own address and carry `{n}` — the server refuses anything else, because
 * the read they drive leaves the platform's host.
 *
 * The list under the form is the read itself, so an operator sees at once
 * whether what they typed finds anything, rather than discovering in the
 * editor that the list is empty.
 */
export function ProductDemonstrations({ productCode }: { productCode: string }) {
  const where = useProductDemonstrations(productCode);

  if (where.isPending) {
    return <SkeletonRows rows={2} />;
  }

  if (where.error !== null) {
    return <ErrorSurface error={where.error} onRetry={() => void where.refetch()} />;
  }

  return (
    <DemonstrationsForm
      // Remounted when the stored paths change, so the fields start from
      // what the server holds and never from what was typed before a save.
      key={`${where.data.source}|${where.data.embed}`}
      productCode={productCode}
      source={where.data.source}
      embed={where.data.embed}
      found={where.data.demonstrations}
      looking={where.isFetching}
    />
  );
}

function DemonstrationsForm({
  productCode,
  source: storedSource,
  embed: storedEmbed,
  found,
  looking,
}: {
  productCode: string;
  source: string;
  embed: string;
  found: readonly { number: number; name: string; embed_url: string }[];
  looking: boolean;
}) {
  const save = useSetProductDemonstrations(productCode);
  const [source, setSource] = useState(storedSource);
  const [embed, setEmbed] = useState(storedEmbed);
  const configured = storedSource !== '' && storedEmbed !== '';

  return (
    <Section
      description={tx("Where this product answers for its demonstrations, so the page editor can offer them by name. Both are paths under the product's own address, with {n} where the demonstration's number goes.", {
        n: <code>{'{n}'}</code>,
      })}
    >
      {save.error !== null && <ErrorSurface error={save.error} />}

      <form
        className="space-y-3"
        data-testid={`demonstrations-form-${productCode}`}
        onSubmit={(event) => {
          event.preventDefault();
          save.mutate({ source, embed });
        }}
      >
        <Field
          id={`demonstrations-source-${productCode}`}
          label={t("Where one is asked for")}
          hint={t("Answers with the demonstration where that number exists. For example admin/vitrine/{n}.")}
        >
          <input
            id={`demonstrations-source-${productCode}`}
            className={inputClass()}
            value={source}
            maxLength={500}
            spellCheck={false}
            onChange={(event) => setSource(event.target.value)}
          />
        </Field>

        <Field
          id={`demonstrations-embed-${productCode}`}
          label={t("What a page embeds to show it")}
          hint={t("Leave {width} and {height} as they are: the page puts in the pixels it drew at. For example ?mode=demo&file={n}&x={width}&y={height}.")}
        >
          <input
            id={`demonstrations-embed-${productCode}`}
            className={inputClass()}
            value={embed}
            maxLength={500}
            spellCheck={false}
            onChange={(event) => setEmbed(event.target.value)}
          />
        </Field>

        <Button type="submit" pending={save.isPending} data-testid={`save-demonstrations-${productCode}`}>
          {t("Save")}
        </Button>
      </form>

      {configured && (
        <div className="mt-4 space-y-2" data-testid={`demonstrations-found-${productCode}`} aria-busy={looking || undefined}>
          <h4 className="text-xs font-semibold">{t("What answers today")}</h4>

          {found.length === 0 ? (
            <p className="text-sm text-muted">
              {t("Nothing answered for numbers 1 to 20. Check the two paths, and that the product has an address.")}
            </p>
          ) : (
            <ol className="space-y-1 text-sm">
              {found.map((demonstration) => (
                <li key={demonstration.number} className="flex gap-2" data-demonstration={demonstration.number}>
                  <span className="w-6 tabular-nums text-muted">{demonstration.number}</span>
                  <span>{demonstration.name}</span>
                </li>
              ))}
            </ol>
          )}
        </div>
      )}
    </Section>
  );
}
