# ADR-030 — One event, several channels, none in the request path

**Status:** accepted
**Decides:** how the platform tells people things
**Relates to:** Architecture V2 §12.3, §26.1, §27, §27.1, non-negotiables #21, #24;
uses [ADR-027](ADR-027-cron-polled-job-queue.md)

## Context

Four features were waiting on this: unread messages (§12.3, deferred from
M6.2), payment failure (§24), export ready (§15), and the pre-renewal notice
M5.1 needs before anything can renew tacitly.

They have nothing in common except that somebody has to be told, on whatever
channel reaches them.

## Decision

**A notification is not a message.** §12.3's conversations have participants,
an order, a read watermark, and somebody replies. A notification is one-way.
They are separate tables with no foreign key between them, because mixing them
would put system noise in support threads and give a human message the fate of
a mutable preference. This is the same shape as §12.2's two identity axes:
things that never convert into one another get separate tables.

**Channels are adapters behind a `Notifier` port** — the fifth after
`PaymentProvider`, `EInvoiceProvider`, `StorageProvider` and
`VatNumberValidator`. Changing SMS routers is a change of wiring.

**Delivery rows are written up front, one per candidate channel**, before
anything is sent. That is what makes a suppression recordable: deciding at
send time and skipping what fails the gate would leave nothing behind, and
*"did we tell them?"* would have no answer — which is exactly the question a
pre-renewal notice has to settle.

**Exactly-once is `UNIQUE (notification_id, channel)`.** ADR-027 requires
idempotent handlers because an expired lease lets two copies of a job finish.
For a notification a duplicate is a *billed* SMS and an annoyed recipient, and
on WhatsApp it risks the sender's standing. A check would be raced; an index
cannot be. Claiming uses `FOR UPDATE SKIP LOCKED`, as the job queue does.

**Consent fails closed.** SMS and WhatsApp are attempted only against a
recorded, revocable opt-in; with none, the delivery is written `SUPPRESSED`
with `NO_CONSENT` and nothing is tried. Same posture as VIES unreachable
granting no reverse charge (§25.3) and an absent secret validating no signed
link (§31): where permission cannot be established, the platform does not
proceed.

**Revocation is a date, not a deletion.** Erasing the row would destroy the
record that permission once existed, which is the opposite of proof.

**`SECURITY` cannot be switched off**, and the database says so:

```sql
CHECK (category <> 'SECURITY' OR enabled)
```

A notice the recipient can mute is one an attacker can mute — and an attacker
holding the account can change preferences. Non-negotiable #24, and the
counterpart of #21's rule that staff access is never silent.

**The gate lives in one place.** Consent and preferences are settled before a
channel adapter is called, never inside it. An adapter that could also refuse
would put one decision in two places, and the SMS one is where a mistake costs
money.

**The payload is data; the text is rendered at send time** — the language is
the recipient's and the format is the channel's. The exception is a
notification with legal effect, which keeps its rendered body, for the reason
an invoice keeps its snapshot: what can later be relied on must stay
re-readable as it was sent.

## Consequences

- **An absent preference means enabled, except for marketing.** Somebody who
  never opened the settings should still hear that their payment failed:
  opting out is a decision, silence is not. Marketing inverts that, for the
  obvious reason.
- **SMS and WhatsApp are never a default channel.** They cost money and need
  consent, so a caller opts into them per notification rather than getting
  them by omission.
- **No phone number exists in the identity model yet**, so SMS and WhatsApp
  record `NO_ADDRESS` rather than being attempted. That is the honest state,
  and it is visible in the delivery record instead of being a silent nothing.
- The three outbound channels share one honest stand-in configured per
  channel. What differs between real SMTP and real SMS is everything; what
  differs between three fakes is nothing.
- Logs carry the channel, the subject and a masked recipient — never the body,
  which can hold a name, an amount or a link (§31, §26.1).
- `failure_reason` stores the exception class, never its message: a provider's
  message can carry an endpoint or a token, and the field is served over the
  API.
- Polling, not push. R2 stands: no persistent process, so `unread-count` is
  its own cheap route because a badge is polled far more often than a list is
  read.

## Alternatives considered

**Sending inside the request that produces the notification.** A slow SMS
provider becomes a slow API and an unreachable one becomes an unreachable API.

**One `notifications` table with a channel column and no delivery rows.** It
cannot express "sent by email, suppressed by SMS", which is the normal case.

**Skipping suppressed channels instead of recording them.** Cheaper, and it
throws away the only evidence that a decision was made.

**Letting each channel adapter check consent.** Four places to get the same
rule right, and the expensive one is the one people forget.

**Treating notifications as messages in a conversation.** It would have reused
§12.3 wholesale, and made every system notice something a person could reply
to and a preference could silence.
