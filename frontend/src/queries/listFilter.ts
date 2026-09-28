/**
 * What a list is narrowed to: one person, one status, a window of days, or
 * none of them (2026-09-27, the window 2026-09-28). The server's own
 * vocabulary — a user id, a status code from the contract, two calendar days
 * — because it is the server that filters: a page narrowed here in
 * JavaScript would be one page of the answer, not the answer, and its total
 * would lie.
 */
export type ListFilter<Status extends string = string> = {
  readonly person?: string;
  readonly status?: Status | '';
  /** `YYYY-MM-DD`, the value a `<input type="date">` holds. */
  readonly from?: string;
  readonly to?: string;
};

/**
 * The query string a filter becomes. Only what is set: an empty select is
 * "everybody", which is the absence of the parameter, not a value of it —
 * and an emptied date input is the absence of that bound, not a bound of
 * nothing, which the server would refuse.
 */
export function filterQuery<Status extends string>(
  filter: ListFilter<Status>,
): { person?: string; status?: Status; from?: string; to?: string } {
  return {
    ...(filter.person !== undefined && filter.person !== '' ? { person: filter.person } : {}),
    ...(filter.status !== undefined && filter.status !== '' ? { status: filter.status } : {}),
    ...(filter.from !== undefined && filter.from !== '' ? { from: filter.from } : {}),
    ...(filter.to !== undefined && filter.to !== '' ? { to: filter.to } : {}),
  };
}

/** Whether anything is narrowed, so an empty page can say it is the filter's. */
export function isFiltered(filter: ListFilter): boolean {
  return Object.keys(filterQuery(filter)).length > 0;
}

/**
 * Every value of a contract enum, as a list a select can offer — checked
 * **both ways** by the compiler: `satisfies Record<Status, true>` refuses a
 * value the contract does not have *and* one it has that is missing. A plain
 * array would catch only the first, and the second is how `CHARGED_BACK`
 * stood in for `CHARGEBACK` unnoticed.
 */
export function everyValue<Status extends string>(values: Record<Status, true>): readonly Status[] {
  return Object.keys(values) as Status[];
}
