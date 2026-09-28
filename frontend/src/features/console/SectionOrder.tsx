import { useState } from 'react';

import { BAND_META, type BandKind } from '@/features/showcase/blocks/meta';
import { Button } from '@/ui/Field';
import { t } from '@/i18n';

/**
 * The page at a glance, and the order it is read in (2026-09-28).
 *
 * Six rows, one per section, dragged into the order a reader will meet
 * them. It exists because that order was a constant compiled into the
 * bundle — `BAND_META[kind].order` — so an operator who wanted the prices
 * above the questions could do nothing about it without a rebuild and a
 * redeploy.
 *
 * **`PRICING` is one of the rows.** It is the only section with no form
 * above: it reads the catalogue and has no row anybody writes, because a row
 * for it would be a row somebody could type a price into. Its place in the
 * order is the one thing about it an operator decides, and a list that
 * showed five movable rows and one fixed would be a list nobody could
 * explain.
 *
 * **Dragging is not the only way, and that is not a nicety.** A pointer
 * drag is unreachable by keyboard, hostile on a touch screen and impossible
 * with a screen reader — so every row also carries two buttons, and they are
 * the real interface: the drag is the shortcut. ui-spec §4.2 does not let
 * this page bend the rule that a capability may not be desktop-only.
 *
 * **It reorders a draft, and saves nothing.** The Save button above writes
 * the whole story, order included, for the same reason the bands do: two
 * writes for one afternoon's work is two chances to leave the page half
 * changed.
 */
export function SectionOrder({
  sections,
  onChange,
}: {
  sections: readonly BandKind[];
  onChange: (next: readonly BandKind[]) => void;
}) {
  // Which row the pointer is carrying. Local, and never a store: it exists
  // between a `dragstart` and a `drop` and nowhere else.
  const [carrying, setCarrying] = useState<BandKind | null>(null);

  const move = (kind: BandKind, to: number) => {
    const from = sections.indexOf(kind);

    if (from === -1 || to < 0 || to >= sections.length || to === from) {
      return;
    }

    const next = [...sections];
    next.splice(from, 1);
    next.splice(to, 0, kind);
    onChange(next);
  };

  return (
    <ol className="space-y-2" data-testid="section-order">
      {sections.map((kind, index) => (
        <li
          key={kind}
          data-section={kind}
          data-at={index}
          draggable
          aria-label={t(BAND_META[kind].nav)}
          onDragStart={(event) => {
            setCarrying(kind);
            // Firefox drags nothing at all without data on the transfer.
            event.dataTransfer.setData('text/plain', kind);
            event.dataTransfer.effectAllowed = 'move';
          }}
          onDragEnd={() => setCarrying(null)}
          onDragOver={(event) => {
            // The default is "refuse the drop", so this is what makes a row
            // a target at all rather than a flourish.
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
          }}
          onDrop={(event) => {
            event.preventDefault();

            // The dragged row from the event rather than from state, so a
            // drag begun in another window drops nothing instead of moving
            // whichever row this one happened to be holding.
            const dropped = (carrying ?? event.dataTransfer.getData('text/plain')) as BandKind;

            if (sections.includes(dropped)) {
              move(dropped, index);
            }

            setCarrying(null);
          }}
          className={
            carrying === kind
              ? 'flex items-center gap-3 rounded-card border border-accent bg-accent-wash p-3 opacity-60'
              : 'flex items-center gap-3 rounded-card border border-line bg-surface p-3'
          }
        >
          <span
            aria-hidden="true"
            className="flex size-7 shrink-0 items-center justify-center rounded-full border border-line text-xs font-semibold text-subtle"
          >
            {index + 1}
          </span>

          <span className="font-medium">{t(BAND_META[kind].nav)}</span>

          {kind === 'PRICING' && (
            // Said on the row, because it is the one with no form above and
            // somebody will look for one.
            <span className="text-xs text-subtle" data-testid="reads-the-catalogue">
              {t("reads the catalogue")}</span>
          )}

          <span className="ml-auto flex gap-1">
            <Button
              type="button"
              variant="secondary"
              data-testid={`up-${kind}`}
              disabled={index === 0}
              aria-label={t("Move {section} earlier", { section: t(BAND_META[kind].nav) })}
              onClick={() => move(kind, index - 1)}
            >
              {'↑'}
            </Button>
            <Button
              type="button"
              variant="secondary"
              data-testid={`down-${kind}`}
              disabled={index === sections.length - 1}
              aria-label={t("Move {section} later", { section: t(BAND_META[kind].nav) })}
              onClick={() => move(kind, index + 1)}
            >
              {'↓'}
            </Button>
          </span>
        </li>
      ))}
    </ol>
  );
}
