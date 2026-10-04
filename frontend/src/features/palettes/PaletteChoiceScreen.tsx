import { useState } from 'react';

import { t } from '@/i18n';
import { useSelectTenantPalette, useTenantPalettes } from '@/queries/palettes';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { MoodBoard } from '@/ui/MoodBoard';
import { PageHeader, Section } from '@/ui/Page';
import { SkeletonRows } from '@/ui/Skeleton';
import { pill } from '@/ui/tone';

import { PaletteCard } from './PaletteCard';
import { themeProperties, type ThemeMode } from './themeDocument';

/**
 * `/palette` — which palette the organisation's screens wear in this product
 * (2026-10-04).
 *
 * **It chooses; it never edits.** The palettes are the platform
 * administrator's, shared by every organisation that wears one. What is the
 * organisation's is the choice — the same row the console's matrix shows and
 * changes, so whichever of the two chose last is what both see.
 *
 * Behind `skin.manage`, and only that: the operator chose not to tie it to the
 * offer (2026-10-04).
 */
const MODES: readonly ThemeMode[] = ['light', 'dark'];

export function PaletteChoiceScreen() {
  const choice = useTenantPalettes();
  const select = useSelectTenantPalette();
  const [previewing, setPreviewing] = useState<string | null>(null);

  if (choice.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (choice.error !== null) {
    return <ErrorSurface error={choice.error} onRetry={() => void choice.refetch()} />;
  }

  const { palettes, selected } = choice.data;
  const worn = palettes.find((palette) => palette.name === selected) ?? null;
  const shown = palettes.find((palette) => palette.name === (previewing ?? selected)) ?? palettes[0];

  return (
    <div className="max-w-6xl space-y-10">
      <PageHeader
        title={t('Palette')}
        meta={
          <span data-testid="palette-worn">
            {worn === null
              ? t('Your members see the platform’s own design.')
              : t('Your members see {name}.', { name: worn.document.label ?? worn.name })}
          </span>
        }
        description={t(
          'How your organisation’s screens look in this product. The palettes are the platform’s; which one your members see is your choice, and your platform administrator sees it too.',
        )}
      />

      <Section title={t('Palettes')}>
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {palettes.map((palette) => (
            <PaletteCard key={palette.name} name={palette.name} document={palette.document} selected={palette.name === selected}>
              {palette.name === selected ? (
                <span className={pill('success')}>{t('In use')}</span>
              ) : (
                <Button
                  type="button"
                  pending={select.isPending && select.variables === palette.name}
                  onClick={() => select.mutate(palette.name)}
                >
                  {t('Use this palette')}
                </Button>
              )}
              <Button type="button" variant="secondary" onClick={() => setPreviewing(palette.name)}>
                {t('Preview')}
              </Button>
            </PaletteCard>
          ))}
        </ul>

        {selected !== null && (
          <Button
            type="button"
            variant="secondary"
            pending={select.isPending && select.variables === null}
            onClick={() => select.mutate(null)}
          >
            {t('Go back to the platform’s design')}
          </Button>
        )}
        {select.error !== null && <ErrorSurface error={select.error} />}
      </Section>

      {shown !== undefined && (
        <Section title={t('Preview of {name}', { name: shown.document.label ?? shown.name })}>
          <div className="grid gap-4 xl:grid-cols-2">
            {MODES.map((mode) => (
              <MoodBoard
                key={mode}
                mode={mode}
                label={mode === 'light' ? t('Light') : t('Dark')}
                scope={themeProperties(shown.document, mode, true)}
                stripe={shown.document.colors.flatMap((group) => group.tokens.map((token) => token.name))}
                testId={`palette-preview-${mode}`}
              />
            ))}
          </div>
        </Section>
      )}
    </div>
  );
}
