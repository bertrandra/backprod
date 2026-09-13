import { useState } from 'react';

import {
  ACCESS_PURPOSES,
  isMotiveComplete,
  MINIMUM_REFERENCE,
  PURPOSE_LABELS,
  type AccessMotive,
  type AccessPurpose,
} from '@/queries/staff';
import { Button, Field, inputClass } from '@/ui/Field';

/**
 * The reason, collected **as part of the read** (R14).
 *
 * Non-negotiable #21 requires a staff access to be *traced, motivated and never
 * silent*. U8 could only deliver the first and third: the platform recorded the
 * permission a read was made under — the *authority* — and no endpoint accepted
 * a reason for the read itself. R14 was filed rather than adding a free-text box
 * that went nowhere, because a box that collects nothing while looking like a
 * control is worse than no box.
 *
 * **This is a gate, not a form beside the data.** The read does not happen until
 * there is a motive: `useStaffTenant` and `useSupportConversation` are disabled
 * without one, so nothing is fetched and then apologised for. The roadmap's
 * wording was *"the UI collects the reason as part of the read, not as an
 * afterthought"*, and a panel that appeared next to already-loaded data would be
 * exactly the afterthought.
 *
 * **Two fields, because one of them alone is useless.** The purpose is what makes
 * the log countable — how many reads were billing investigations last quarter —
 * and the reference is what makes any single row mean something. R14 named both
 * failure modes: free text alone *"collects 'support' a thousand times"*, and a
 * structured field alone *"is only as good as the system it points at"*.
 *
 * It says who will see it. A person about to type a ticket number should know the
 * entry carries their name and appears in a log their colleagues read; that is
 * the whole mechanism, and hiding it would make this feel like paperwork rather
 * than accountability.
 */
export function AccessMotiveGate({
  what,
  onGiven,
}: {
  /** What is about to be opened, in words — "this customer", "this thread". */
  what: string;
  onGiven: (motive: AccessMotive) => void;
}) {
  const [purpose, setPurpose] = useState<AccessPurpose | ''>('');
  const [reference, setReference] = useState('');

  const draft = purpose === '' ? null : { purpose, reference };
  const ready = isMotiveComplete(draft);

  return (
    <form
      data-testid="access-motive"
      className="max-w-md space-y-4 rounded border border-amber-300 p-4 dark:border-amber-800"
      onSubmit={(event) => {
        event.preventDefault();

        if (ready && purpose !== '') {
          onGiven({ purpose, reference: reference.trim() });
        }
      }}
    >
      <div className="space-y-1">
        <h2 className="text-xl font-semibold">Why are you opening {what}?</h2>
        <p className="text-sm text-muted">
          {/* Said before the fields, not after. */}
          Nothing is read until you answer. Your name, this reason and the
          permission you used are recorded together, and appear in the access log
          your colleagues can read.
        </p>
      </div>

      <Field id="access-purpose" label="Purpose">
        <select
          id="access-purpose"
          className={inputClass()}
          value={purpose}
          onChange={(event) => setPurpose(event.target.value as AccessPurpose | '')}
        >
          <option value="">Choose one</option>
          {ACCESS_PURPOSES.map((value) => (
            <option key={value} value={value}>
              {PURPOSE_LABELS[value]}
            </option>
          ))}
        </select>
      </Field>

      <Field
        id="access-reference"
        label="Reference"
        hint={`A ticket number, or what you are looking into. At least ${String(MINIMUM_REFERENCE)} characters — “x” is not a reason.`}
      >
        <input
          id="access-reference"
          className={inputClass()}
          value={reference}
          onChange={(event) => setReference(event.target.value)}
        />
      </Field>

      {/* Disabled rather than hidden: somebody should be able to see what is
          missing without guessing why nothing happens. */}
      <Button type="submit" disabled={!ready}>
        Open {what}
      </Button>
    </form>
  );
}

/**
 * The motive a screen is currently reading under, shown back.
 *
 * A read stays open for as long as somebody keeps the page, and by then they may
 * have forgotten what they typed. The entry in the log says this; so does this.
 */
export function MotiveInEffect({
  motive,
  onChange,
}: {
  motive: AccessMotive;
  onChange: () => void;
}) {
  return (
    <p
      data-testid="motive-in-effect"
      data-purpose={motive.purpose}
      className="flex flex-wrap items-center gap-2 text-xs text-muted"
    >
      <span>
        Reading under <strong>{PURPOSE_LABELS[motive.purpose]}</strong> — {motive.reference}
      </span>
      <button
        type="button"
        onClick={onChange}
        className="min-h-[44px] rounded px-2 underline focus-visible:outline-2 focus-visible:outline-offset-2"
      >
        Change
      </button>
    </p>
  );
}
