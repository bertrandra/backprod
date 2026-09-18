/**
 * A moment, with its time (2026-09-19).
 *
 * The money screens showed dates alone, and "paid on the 18th" is not an
 * answer to somebody who paid twice that day. Date and time together, in the
 * viewer's locale and zone — the server's RFC 3339 instant is the truth and
 * this is only how it is read. Null is said as a dash, never as "now".
 */
export function When({ at, testId }: { at: string | null | undefined; testId?: string }) {
  if (at === null || at === undefined) {
    return <span data-testid={testId}>—</span>;
  }

  const moment = new Date(at);

  return (
    <time dateTime={at} data-testid={testId} title={at}>
      {moment.toLocaleDateString()} {moment.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
    </time>
  );
}
