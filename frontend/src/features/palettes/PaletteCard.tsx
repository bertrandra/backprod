import type { ReactNode } from 'react';

import { t } from '@/i18n';
import { cn } from '@/utils/cn';

import type { ThemeDocument, ThemeMode } from './themeDocument';

const MODES: readonly ThemeMode[] = ['light', 'dark'];

/** Two rows of colour, light over dark, straight from the document's values. */
export function Swatches({ document }: { document: ThemeDocument }) {
  const tokens = document.colors.flatMap((group) => group.tokens);

  return (
    <div className="overflow-hidden rounded-control border border-line" aria-hidden="true">
      {MODES.map((mode) => (
        <div key={mode} className="flex h-5">
          {tokens.map((token) => (
            <span key={token.name} className="flex-1" style={{ backgroundColor: token[mode] }} />
          ))}
        </div>
      ))}
    </div>
  );
}

/**
 * A palette as a card: its swatches, what it is called and for, its typeface,
 * and whatever the screen offers to do with it.
 */
export function PaletteCard({
  name,
  document,
  selected = false,
  children,
}: {
  name: string;
  document: ThemeDocument;
  selected?: boolean;
  children?: ReactNode;
}) {
  const sans = document.fonts.find((font) => font.role === 'sans');

  return (
    <li
      className={cn(
        'flex flex-col gap-3 rounded-card border bg-surface p-4 shadow-raise',
        selected ? 'border-accent ring-2 ring-accent' : 'border-line',
      )}
      data-testid={`palette-${name}`}
      aria-current={selected ? 'true' : undefined}
    >
      <Swatches document={document} />
      <div className="space-y-1">
        <h3 className="text-lg font-semibold">{document.label ?? name}</h3>
        <p className="font-mono text-2xs text-muted">{name}</p>
        {document.description !== undefined && <p className="text-sm text-muted">{document.description}</p>}
        {sans !== undefined && <p className="text-xs text-muted">{t('Typeface: {family}', { family: sans.family })}</p>}
      </div>
      {children !== undefined && <div className="mt-auto flex flex-wrap items-center gap-2">{children}</div>}
    </li>
  );
}
