import { useId, useState, type KeyboardEvent } from 'react';

import { inputClass } from '@/ui/Field';
import { personLabel, type Person } from '@/ui/pickers/Select';
import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * A person found by searching, for lists too long to open in a `<select>`
 * — the platform's whole directory, where the console appoints staff.
 *
 * The search itself is the caller's: this control owns the typing, the
 * list and the choice, and is told what the current query found. That
 * keeps it ignorant of which API answers, which is what lets one control
 * serve any directory, and keeps the query hook where every other server
 * read lives.
 *
 * The chosen person is shown as a line — name and address — with the id
 * beneath it, because the id is what will be sent and a person appointing
 * somebody to a platform role should be able to see that it is the right
 * one. *Change* clears it; there is no editing a choice in place.
 *
 * Accessible as the ARIA combobox pattern: the input announces the list,
 * the arrow keys move a highlight the reader can hear, Enter chooses and
 * Escape closes. Nothing here is a `<div>` pretending to be a button.
 */
export function SearchPicker({
  id,
  query,
  onQueryChange,
  results,
  pending,
  value,
  onPick,
  placeholder,
  invalid = false,
}: {
  id: string;
  query: string;
  onQueryChange: (query: string) => void;
  results: readonly Person[];
  pending: boolean;
  value: Person | null;
  onPick: (person: Person | null) => void;
  placeholder?: string | undefined;
  invalid?: boolean;
}) {
  const listId = useId();
  const [active, setActive] = useState(0);
  const open = value === null && query.trim() !== '';
  const highlighted = results[Math.min(active, Math.max(results.length - 1, 0))];

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (!open) return;

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((current) => Math.min(current + 1, results.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((current) => Math.max(current - 1, 0));
    } else if (event.key === 'Enter' && highlighted !== undefined) {
      event.preventDefault();
      onPick(highlighted);
    } else if (event.key === 'Escape') {
      onQueryChange('');
    }
  };

  if (value !== null) {
    return (
      <div data-picker="search" data-picked={value.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <span className="font-medium">{personLabel(value)}</span>
        <code className="text-xs text-muted">{value.id}</code>
        <button
          type="button"
          onClick={() => {
            onPick(null);
            onQueryChange('');
          }}
          className="rounded px-1 text-xs underline decoration-dotted focus-visible:outline-2 focus-visible:outline-offset-2"
        >
          {t("Change")}</button>
      </div>
    );
  }

  return (
    <div data-picker="search" className="relative">
      <input
        id={id}
        type="search"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={open}
        aria-controls={listId}
        aria-activedescendant={open && highlighted !== undefined ? `${listId}-${highlighted.id}` : undefined}
        autoComplete="off"
        className={inputClass(invalid)}
        placeholder={placeholder}
        value={query}
        onChange={(event) => {
          setActive(0);
          onQueryChange(event.target.value);
        }}
        onKeyDown={onKeyDown}
      />

      {open && (
        <ul
          id={listId}
          role="listbox"
          className="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-card border border-line bg-surface p-1 text-sm shadow-raise"
        >
          {pending && results.length === 0 && (
            <li className="px-2 py-1 text-xs text-muted" aria-live="polite">
              {t("Searching…")}</li>
          )}
          {!pending && results.length === 0 && (
            <li className="px-2 py-1 text-xs text-muted" aria-live="polite">
              {t("Nobody matches.")}</li>
          )}
          {results.map((person, index) => (
            <li
              key={person.id}
              id={`${listId}-${person.id}`}
              role="option"
              aria-selected={index === active}
              data-result={person.id}
              className={cn(
                'cursor-pointer rounded-control px-2 py-1.5',
                index === active ? 'bg-well' : 'hover:bg-well',
              )}
              // Mouse down rather than click, so choosing does not first blur
              // the input and close the list under the pointer.
              onMouseDown={(event) => {
                event.preventDefault();
                onPick(person);
              }}
              onMouseEnter={() => setActive(index)}
            >
              <span className="block">{personLabel(person)}</span>
              <code className="block text-xs text-muted">{person.id}</code>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
