import { useState } from 'react';

import { PaletteCard } from '@/features/palettes/PaletteCard';
import { PaletteEditor } from '@/features/palettes/PaletteEditor';
import type { ThemeDocument } from '@/features/palettes/themeDocument';
import { t } from '@/i18n';
import {
  MATRIX_PAGE,
  useAssignPalette,
  usePaletteAssignments,
  usePalettes,
  useSavePalette,
  type Palette,
} from '@/queries/palettes';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, inputClass } from '@/ui/Field';
import { PageHeader, Section } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { cn } from '@/utils/cn';

/**
 * `/console/palettes` — the palettes, and who wears which (2026-10-04).
 *
 * **The matrix** is organisations in rows and products in columns; a cell
 * exists only where the organisation holds the product, and holds the palette
 * it wears there. Changing a cell writes the same row the organisation's
 * administrator changes on their own screen, so the matrix shows whichever of
 * the two chose last — one source, two doors.
 *
 * **The palettes** are edited here and nowhere else: a palette is shared by
 * every organisation wearing it, so changing one is the platform's decision.
 * `staff.design.manage`, which PLATFORM_ADMIN alone holds.
 */
export function PalettesScreen() {
  const palettes = usePalettes();

  return (
    <div className="max-w-6xl space-y-10">
      <PageHeader
        title={t('Palettes')}
        description={t(
          'Which palette each organisation wears in each product it holds, and the palettes themselves. Only the platform administrator changes a palette; an organisation’s administrator may change which one it wears, and that choice shows here.',
        )}
      />

      {palettes.isPending ? (
        <SkeletonRows rows={6} />
      ) : palettes.error !== null ? (
        <ErrorSurface error={palettes.error} onRetry={() => void palettes.refetch()} />
      ) : (
        <>
          <Section
            title={t('Who wears which')}
            description={t('Organisations in rows, products in columns. A dash is a product the organisation does not hold.')}
          >
            <Matrix palettes={palettes.data} />
          </Section>

          <Section title={t('The palettes')} description={t('Editing one changes every organisation that wears it.')}>
            <Library palettes={palettes.data} />
          </Section>
        </>
      )}
    </div>
  );
}

function Matrix({ palettes }: { palettes: readonly Palette[] }) {
  const [offset, setOffset] = useState(0);
  const matrix = usePaletteAssignments(offset);
  const assign = useAssignPalette();

  if (matrix.isPending) {
    return <SkeletonRows rows={4} />;
  }

  if (matrix.error !== null) {
    return <ErrorSurface error={matrix.error} onRetry={() => void matrix.refetch()} />;
  }

  const { products, tenants, total } = matrix.data;

  if (tenants.length === 0) {
    return <p className="text-sm text-muted">{t('No organisation yet.')}</p>;
  }

  return (
    <div className="space-y-3">
      {/* A region of its own, reachable by keyboard: on a phone the columns
          scroll, and a scroll region nobody can focus is one a keyboard
          cannot read. */}
      <div
        className="overflow-x-auto rounded-card border border-line bg-surface"
        role="region"
        aria-label={t('Who wears which')}
        tabIndex={0}
      >
        <table className="w-full min-w-max text-sm" data-testid="palette-matrix">
          <thead>
            <tr className="border-b border-line text-left text-xs text-muted">
              <th scope="col" className="px-3 py-2 font-medium">
                {t('Organisation')}
              </th>
              {products.map((product) => (
                <th key={product.id} scope="col" className="px-3 py-2 font-medium">
                  {product.name} <span className="font-mono text-2xs">{product.code}</span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {tenants.map((tenant) => (
              <tr key={tenant.id} className="border-b border-line last:border-b-0" data-tenant={tenant.slug}>
                <th scope="row" className="px-3 py-2 text-left font-medium">
                  {tenant.name} <span className="font-mono text-2xs text-muted">{tenant.slug}</span>
                </th>
                {products.map((product) => {
                  const cell = tenant.products.find((held) => held.product_id === product.id);

                  if (cell === undefined) {
                    return (
                      <td key={product.id} className="px-3 py-2 text-muted" aria-label={t('Not held')}>
                        —
                      </td>
                    );
                  }

                  const busy = assign.isPending && assign.variables.tenantId === tenant.id && assign.variables.productId === product.id;

                  return (
                    <td key={product.id} className="px-3 py-2">
                      <select
                        aria-label={t('{tenant} in {product}', { tenant: tenant.name, product: product.name })}
                        className={cn(inputClass(), 'min-w-44')}
                        value={cell.palette ?? ''}
                        disabled={busy}
                        aria-busy={busy || undefined}
                        data-cell={`${tenant.slug}/${product.code}`}
                        onChange={(event) =>
                          assign.mutate({
                            tenantId: tenant.id,
                            productId: product.id,
                            palette: event.target.value === '' ? null : event.target.value,
                          })
                        }
                      >
                        <option value="">{t('Platform design')}</option>
                        {palettes.map((palette) => (
                          <option key={palette.name} value={palette.name}>
                            {palette.document.label ?? palette.name}
                          </option>
                        ))}
                      </select>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {assign.error !== null && <ErrorSurface error={assign.error} />}

      {total > MATRIX_PAGE && (
        <div className="flex items-center gap-3 text-sm">
          <Button type="button" variant="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - MATRIX_PAGE))}>
            {t('Previous')}
          </Button>
          <span className="text-muted">
            {t('{from}–{to} of {total}', { from: offset + 1, to: offset + tenants.length, total })}
          </span>
          <Button type="button" variant="secondary" disabled={offset + MATRIX_PAGE >= total} onClick={() => setOffset(offset + MATRIX_PAGE)}>
            {t('Next')}
          </Button>
        </div>
      )}
    </div>
  );
}

function Library({ palettes }: { palettes: readonly Palette[] }) {
  const [draft, setDraft] = useState<{ name: string; document: ThemeDocument } | null>(null);
  const save = useSavePalette();

  // A new palette starts as a copy, under a name nobody has yet.
  const copyOf = (palette: Palette) => {
    const taken = new Set(palettes.map((p) => p.name));
    let name = `${palette.name}-copy`;

    for (let n = 2; taken.has(name); n++) {
      name = `${palette.name}-copy-${n}`;
    }

    return { name, document: palette.document };
  };

  return (
    <div className="space-y-6">
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {palettes.map((palette) => (
          <PaletteCard key={palette.name} name={palette.name} document={palette.document}>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                save.reset();
                setDraft({ name: palette.name, document: palette.document });
              }}
            >
              {t('Edit')}
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                save.reset();
                setDraft(copyOf(palette));
              }}
            >
              {t('Start a new one from it')}
            </Button>
          </PaletteCard>
        ))}
      </ul>

      {draft !== null && (
        <PaletteEditor
          key={draft.name}
          initial={draft}
          palettes={palettes}
          saving={save.isPending}
          saved={save.isSuccess}
          error={save.error}
          onSave={(name, document) => save.mutate({ name, document })}
          onClose={() => setDraft(null)}
        />
      )}
    </div>
  );
}
