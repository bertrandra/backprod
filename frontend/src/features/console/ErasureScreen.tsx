import { useState } from 'react';

import { RETENTION_GROUNDS, useEraseUser, type Erasure, type RetentionGround } from '@/queries/admin';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { panel } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';

/**
 * `console.admin.erasure` — the right to be forgotten, against the duty to keep.
 *
 * **Non-negotiable #15: erasure does not delete everything, and an operator must
 * not be able to believe it does.** Somebody who thinks this wipes a person's
 * record will say so to a customer, in writing, and the platform will then be in
 * the position of having promised something the law forbids it to do. So the
 * screen states what is kept and on what ground *before* the button, and shows
 * the counts afterwards.
 *
 * **There is no dry run.** The contract has one operation and it performs the
 * erasure — `erased` and `retained` come back as a report, not a preview. That is
 * why the grounds below are enumerated from the contract's own list rather than
 * fetched: they are what the answer *will* contain, and an operator needs them
 * before deciding rather than as an explanation of what just happened.
 *
 * **The identifier is typed, not clicked.** A user id pasted deliberately is a
 * different act from a row selected by accident, and this is the one operation
 * here that cannot be undone.
 */
export function ErasureScreen() {
  const erase = useEraseUser();
  const [userId, setUserId] = useState('');
  const [confirming, setConfirming] = useState(false);

  const valid = /^[0-9a-fA-F-]{36}$/.test(userId.trim());

  return (
    <div className="max-w-2xl space-y-8">
      <PageHeader
        title={'Erase a person'}
        description={'This anonymises what may be anonymised and keeps what the law requires be kept. It is not a delete, and describing it to a customer as one would be untrue.'}
      />

      <section
        data-testid="retention-grounds"
        className={`${panel('warning')} space-y-3`}
      >
        <h2 className="font-semibold">What will be kept, and why</h2>
        <p className="text-muted">
          These are not exceptions the platform chose. Each is a record it is obliged to retain, and
          each survives an erasure with the person&rsquo;s identity stripped from it:
        </p>

        <ul className="space-y-1">
          {(Object.keys(RETENTION_GROUNDS) as RetentionGround[]).map((ground) => (
            <li key={ground} data-ground={ground} className="flex gap-2">
              <span className="text-subtle">·</span>
              <span>{RETENTION_GROUNDS[ground]}</span>
            </li>
          ))}
        </ul>

        <p className="text-xs text-muted">
          What is removed is the identity: the name, the email, the credentials. The person keeps a
          row in the directory carrying <code>erased_at</code> and nothing else — the row surviving
          is what keeps every invoice and audit entry pointing at it meaningful.
        </p>
      </section>

      <section className="space-y-4 border-t border-line pt-6">
        <Field
          id="user_id"
          label="User identifier"
          hint="Typed or pasted deliberately. There is no undo, so there is no row to click by accident."
        >
          <input
            id="user_id"
            className={inputClass()}
            value={userId}
            onChange={(event) => {
              setUserId(event.target.value);
              setConfirming(false);
            }}
          />
        </Field>

        {erase.error !== null && <ErrorSurface error={erase.error} />}

        {confirming ? (
          <div data-testid="erasure-confirmation" className="space-y-3 text-sm">
            <p>Erasing this person does the following, and none of it can be undone:</p>
            <ul className="list-inside list-disc text-muted">
              <li>the name, email and credentials are removed and cannot be recovered</li>
              <li>no search will ever find this person again, having neither name nor email</li>
              <li>
                the accounting, fiscal and audit records above stay, with the identity stripped from
                them
              </li>
              <li>the directory keeps a row carrying only the date of erasure</li>
            </ul>

            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="danger"
                pending={erase.isPending}
                onClick={() => erase.mutate(userId.trim(), { onSettled: () => setConfirming(false) })}
              >
                Erase permanently
              </Button>
              <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                Do not
              </Button>
            </div>
          </div>
        ) : (
          <Button
            type="button"
            variant="danger"
            disabled={!valid}
            onClick={() => setConfirming(true)}
          >
            Erase this person…
          </Button>
        )}

        {!valid && userId.trim() !== '' && (
          <p role="alert" className="text-xs text-danger">
            That is not a user identifier.
          </p>
        )}
      </section>

      {erase.data !== undefined && <Report erasure={erase.data} />}
    </div>
  );
}

/**
 * What actually happened, in two columns that are never merged.
 *
 * The counts on the left were anonymised; the counts on the right were kept, each
 * with the ground that required it. Merging them into "42 records processed"
 * would be the exact confusion this screen exists to prevent.
 */
function Report({ erasure }: { erasure: Erasure }) {
  const erased = Object.entries(erasure.erased);
  const retained = Object.entries(erasure.retained);

  return (
    <section
      data-testid="erasure-report"
      className="space-y-4 border-t border-line pt-6 text-sm"
    >
      <h2 className="text-xl font-semibold">Done</h2>

      <div className="grid gap-6 sm:grid-cols-2">
        <div className="space-y-2">
          <h3 data-testid="erased-heading" className="font-medium">
            Anonymised
          </h3>
          {erased.length === 0 ? (
            <p className="text-muted">Nothing was anonymised.</p>
          ) : (
            <ul className="space-y-1">
              {erased.map(([what, count]) => (
                <li key={what} data-erased={what} className="flex justify-between gap-2">
                  <span>{what.replaceAll('_', ' ')}</span>
                  <span className="font-medium">{count}</span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="space-y-2">
          <h3 data-testid="retained-heading" className="font-medium">
            Kept, as the law requires
          </h3>
          {retained.length === 0 ? (
            <p className="text-muted">Nothing had to be kept.</p>
          ) : (
            <ul className="space-y-2">
              {retained.map(([what, detail]) => (
                <li key={what} data-retained={what} data-ground={detail.ground}>
                  <div className="flex justify-between gap-2">
                    <span>{what.replaceAll('_', ' ')}</span>
                    <span className="font-medium">{detail.count}</span>
                  </div>
                  {/* The ground, never a bare count: a number without a reason
                      reads as a failure to delete. */}
                  <p className="text-xs text-muted">
                    {RETENTION_GROUNDS[detail.ground] ?? detail.ground}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <p className="text-xs text-muted">
        The person now appears in the directory with no identity and a date. Their acts remain in the
        audit trail, attributed to someone since erased rather than to the platform.
      </p>
    </section>
  );
}
