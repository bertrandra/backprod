import { useId } from 'react';

import { t } from '@/i18n';
import type { ListFilter } from '@/queries/listFilter';
import { useMembers } from '@/queries/members';
import type { Schemas } from '@/api/client';
import { Field, inputClass } from '@/ui/Field';

/**
 * The bar at the top of the organisation's lists — invoices, payments,
 * credit notes, orders and subscriptions (2026-09-27, the dates 2026-09-28).
 *
 * **The person select is the administrator's only.** A member's lists already
 * hold only what concerns them, so there is nobody to choose between; and the
 * server would answer a member naming a colleague with an empty page anyway —
 * the filter narrows and never widens. Hiding it is courtesy, as every gate
 * in this shell is. It needs `members.read` as well, because its options are
 * the organisation's people.
 *
 * The statuses are the contract's, passed in by the screen that knows which
 * document it lists; a list with no status of its own passes none and the
 * select is not drawn.
 */
export function ListFilterBar<Status extends string>({
  value,
  onChange,
  people,
  statuses = [],
  testId,
}: {
  value: ListFilter<Status>;
  onChange: (next: ListFilter<Status>) => void;
  /** Whether to offer the person select — the administrator's view. */
  people: boolean;
  statuses?: readonly Status[];
  testId: string;
}) {
  const id = useId();
  const members = useMembers(people);

  const options: readonly Schemas['Member'][] = members.data ?? [];

  return (
    <div
      role="search"
      aria-label={t('Filter the list')}
      data-testid={testId}
      className="flex flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-3"
    >
      {people && (
        <div className="min-w-48 flex-1">
          <Field id={`${id}-person`} label={t('Person')}>
            <select
              id={`${id}-person`}
              data-testid="filter-person"
              className={inputClass()}
              value={value.person ?? ''}
              disabled={members.isPending}
              onChange={(event) => onChange({ ...value, person: event.target.value })}
            >
              <option value="">{t('Everybody')}</option>
              {options.map((member) => (
                <option key={member.user_id} value={member.user_id}>
                  {member.display_name !== null && member.display_name !== ''
                    ? `${member.display_name} · ${member.email}`
                    : member.email}
                </option>
              ))}
            </select>
          </Field>
        </div>
      )}

      {statuses.length > 0 && (
        <div className="min-w-40">
          <Field id={`${id}-status`} label={t('Status')}>
            <select
              id={`${id}-status`}
              data-testid="filter-status"
              className={inputClass()}
              value={value.status ?? ''}
              // Only ever one of the options below, each drawn from `statuses`.
              onChange={(event) => onChange({ ...value, status: event.target.value as Status | '' })}
            >
              <option value="">{t('All statuses')}</option>
              {statuses.map((status) => (
                <option key={status} value={status}>
                  {status}
                </option>
              ))}
            </select>
          </Field>
        </div>
      )}

      {/* The window, as two days (2026-09-28). A native date input, so the
          person gets their own locale's order and their own calendar, and
          the value it holds is already the `YYYY-MM-DD` the contract wants —
          formatting one here would be a second contract to keep. Emptying
          one drops that bound rather than sending nothing, which the server
          would refuse. */}
      <div className="min-w-36">
        <Field id={`${id}-from`} label={t('From')}>
          <input
            id={`${id}-from`}
            type="date"
            data-testid="filter-from"
            className={inputClass()}
            value={value.from ?? ''}
            max={value.to !== undefined && value.to !== '' ? value.to : undefined}
            onChange={(event) => onChange({ ...value, from: event.target.value })}
          />
        </Field>
      </div>

      <div className="min-w-36">
        <Field id={`${id}-to`} label={t('To')}>
          <input
            id={`${id}-to`}
            type="date"
            data-testid="filter-to"
            className={inputClass()}
            value={value.to ?? ''}
            min={value.from !== undefined && value.from !== '' ? value.from : undefined}
            onChange={(event) => onChange({ ...value, to: event.target.value })}
          />
        </Field>
      </div>
    </div>
  );
}

/**
 * Whom a document concerns, on every row of the organisation's lists
 * (2026-09-27): the member, or the organisation itself when nobody in
 * particular bought it. The server's answer (`person`), never a guess from
 * the customer snapshot, which names a legal party rather than a colleague.
 */
export function PersonLine({
  person,
  testId = 'row-person',
}: {
  person: Schemas['DocumentPerson'];
  testId?: string;
}) {
  if (person === null) {
    return (
      <p data-testid={testId} data-person="organisation" className="mt-1 text-xs text-muted">
        {t('Member:')} <span className="text-subtle">{t('the organisation')}</span>
      </p>
    );
  }

  const name = person.name !== null && person.name !== '' ? person.name : null;

  return (
    <p data-testid={testId} data-person={person.user_id} className="mt-1 text-xs text-muted">
      {t('Member:')} <span className="text-ink">{name ?? person.email ?? t('an erased person')}</span>
      {name !== null && person.email !== null && ` · ${person.email}`}
    </p>
  );
}
