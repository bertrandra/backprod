import { useId, useRef, type KeyboardEvent, type ReactNode } from 'react';

import { cn } from '@/utils/cn';

/**
 * Tabs, as WAI-ARIA describes them (2026-10-04).
 *
 * One tab in the page's tab order at a time — the selected one — and the
 * arrow keys, Home and End move between them, selecting as they go. Each panel
 * names the tab that labels it. The look is MailScreen's underline, which was
 * the one set of tabs this application had; that one had no keyboard and no
 * panel, which is what this adds.
 */
export interface Tab<K extends string> {
  readonly id: K;
  readonly label: ReactNode;
}

export function Tabs<K extends string>({
  label,
  tabs,
  selected,
  onSelect,
  children,
  testId,
}: {
  label: string;
  tabs: readonly Tab<K>[];
  selected: K;
  onSelect: (id: K) => void;
  /** The selected tab's panel. */
  children: ReactNode;
  testId?: string;
}) {
  const base = useId();
  const refs = useRef(new Map<K, HTMLButtonElement>());

  const move = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const last = tabs.length - 1;
    const next =
      event.key === 'ArrowRight' ? (index === last ? 0 : index + 1)
      : event.key === 'ArrowLeft' ? (index === 0 ? last : index - 1)
      : event.key === 'Home' ? 0
      : event.key === 'End' ? last
      : null;

    if (next === null) {
      return;
    }

    event.preventDefault();
    const tab = tabs[next];

    if (tab !== undefined) {
      onSelect(tab.id);
      refs.current.get(tab.id)?.focus();
    }
  };

  return (
    <div className="space-y-4" data-testid={testId}>
      <div role="tablist" aria-label={label} className="flex flex-wrap gap-1 border-b border-line">
        {tabs.map((tab, index) => {
          const active = tab.id === selected;

          return (
            <button
              key={tab.id}
              ref={(element) => {
                if (element === null) {
                  refs.current.delete(tab.id);
                } else {
                  refs.current.set(tab.id, element);
                }
              }}
              id={`${base}-tab-${tab.id}`}
              type="button"
              role="tab"
              aria-selected={active}
              aria-controls={`${base}-panel`}
              tabIndex={active ? 0 : -1}
              data-tab={tab.id}
              onClick={() => onSelect(tab.id)}
              onKeyDown={(event) => move(event, index)}
              className={cn(
                '-mb-px border-b-2 px-3 py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-offset-2',
                active ? 'border-accent font-medium text-accent-strong' : 'border-transparent text-muted hover:text-ink',
              )}
            >
              {tab.label}
            </button>
          );
        })}
      </div>

      <div id={`${base}-panel`} role="tabpanel" aria-labelledby={`${base}-tab-${selected}`} tabIndex={0} className="focus-visible:outline-2">
        {children}
      </div>
    </div>
  );
}
