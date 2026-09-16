# ADR-048 — Stripe is the first real provider, and the page talks to it

**Status:** accepted
**Implements:** [docs/stripe-payments.md](../stripe-payments.md), with one
departure recorded in §"Where the form lives"
**Relates to:** [ADR-022](ADR-022-payments-and-webhook-idempotency.md);
[ADR-024](ADR-024-payment-gated-activation.md);
[ADR-034](ADR-034-a-checkout-session-is-an-order.md);
[ADR-038](ADR-038-this-platform-issues-its-own-sessions.md);
[ADR-041](ADR-041-the-storefront-sells-to-strangers.md);
[ADR-045](ADR-045-the-console-shows-the-path-instead-of-refusing-along-it.md);
Architecture V2 §24, §25, §31; non-negotiables #17, #20

## Context

The payment path was built and proven against a provider that is honestly
not one. `StubPaymentProvider` exercises the whole chain — open a checkout,
raise the invoice, authorize, receive a signed webhook, apply it exactly
once, settle the invoice, activate the subscription — and its docblock says
it exists so that *"the first real adapter has a worked example of what the
port expects"*. This is that adapter.

Everything above `PaymentProvider` was decided before it: the webhook is the
only thing that marks money collected (ADR-022); exactly-once is a unique
index; the signature is verified over the raw bytes before parsing; a
checkout session is an order and the `client_secret` is returned once and
never stored (ADR-034); a card never reaches this platform (§24). So what
this ADR decides is only where Stripe's shape does not fit the platform's
without a choice.

## Decision

**One PaymentIntent per attempt, and it is the payment's id.** `authorize()`
creates the intent, so `payments.provider_payment_id` is `pi_…` from the
first row and every event Stripe will ever send about that money —
succeeded, failed, refunded, disputed — keys on the same string. The
reference `<invoice number>/<attempt>` (ADR-034), keyed with the
installation's webhook secret, is the idempotency key, so the same attempt
asked twice is the same intent — and the same reference from *another*
installation on the same Stripe account (invoice numbers restart at `000001`
everywhere) is not: the first deployment beside the developer's sandbox was
handed the laptop's already-paid intents until it was. Nothing about a customer
is sent: no `customer`, no stored instrument, no manual capture.

**The Payment Element in the page, not hosted Checkout.** Hosted Checkout
keys the page on a `cs_…` session and creates the intent later, so the
adapter would have to map `pi_` events back to a `cs_` row by calling Stripe
from inside the webhook handler — an adapter that has to phone the provider
to know which payment a delivery is about fails closed at the worst moment.
The Element slots into what the port already drew: `ProviderPayment.clientSecret`
is documented as *"an intent secret"* among other things.

**The signature is verified by hand, over the raw bytes.** Stripe's
`t=<unix>,v1=<hex>[,v1=…]` scheme: HMAC-SHA256 over `"<t>.<body>"` with the
endpoint's `whsec_…`, a 300-second replay window, any of several `v1`
accepted during a rotation, constant-time comparison, one refusal for
absent, malformed, stale and wrong. Thirty lines in `StripeSignature`, and
no SDK on that path: it is the part that decides whether money moves on
somebody else's say-so, and it must be readable without a vendor directory.

**Six events, and the rest parse to null.** `payment_intent.succeeded`,
`payment_intent.payment_failed`, `payment_intent.canceled`, `refund.updated`
(when `succeeded`), `charge.dispute.created` map onto the platform's five;
`PAYMENT_AUTHORIZED` is never emitted because manual capture is not used.
`charge.succeeded` is the sixth, and it is new to the platform: an intent's
event names the instrument only by an unexpanded `pm_…` id, and the *kind* —
card, SEPA debit, a wallet — is on the charge, so that event is
`INSTRUMENT_KNOWN`, which moves no status, writes no ledger row and fills
`payments.method` whenever it lands, before or after the outcome. The
alternative was to expand the intent from inside the webhook, which is the
phone call the paragraph above refuses. Everything else —
`payment_intent.created`, `processing`, every `customer.*` and `invoice.*`
— is `null`, answered 200,
as ADR-022 requires: a 4xx would have Stripe retry it forever. An event
whose `livemode` disagrees with the configured key's mode is also `null`:
that is a webhook endpoint configured in the wrong Stripe mode, and
applying test money to live invoices is the worst version of it.

**Two things the port learned.** `isSandbox()`, so the console's readiness
step can say *"Stripe (sandbox)"* and the checkout can say *"no money
moves"* — a demo on real cards and a production on test cards are the two
mistakes that cost the most, and both are surfaced rather than refused
(`bin/preflight.php` warns of `APP_ENV=prod` on an `sk_test_` key). And
`clientKey()`, the key a page needs to load the provider's own component —
designed by the provider to sit in a page, so not a secret, so in the API
(`payment_provider.publishable_key`) rather than in a build variable that
would freeze one deployment's key into a bundle another reuses. Plus one
field on `ProviderEvent`: `method`, because a provider that lets the
customer choose the instrument *after* the payment starts cannot say its
kind at `authorize()` time — mapped onto `payments.method`'s own vocabulary
(`CARD`, `SEPA_DEBIT`, `APPLE_PAY`, …), never Stripe's word — and one event
type, `INSTRUMENT_KNOWN`, for a provider that reports the instrument in a
delivery of its own. The first word stands: a method known at `authorize()`
or from an earlier delivery is never overwritten.

**Stripe does not invoice, subscribe or renew.** This platform numbers its
own invoices gaplessly (ADR-021) and runs its own subscriptions and
renewals. Stripe takes one amount for one attempt and reports what happened
to it. Nothing creates a Stripe Invoice, Subscription, Customer or Tax
calculation.

**`stripe/stripe-php`, pinned, confined.** `deptrac.yaml` has a `Stripe`
layer collected by `Stripe\`, reachable from `Infrastructure` and nowhere
else; a `Stripe\` import in a service, a controller or the domain fails the
build. The API version is pinned in the client so a dashboard upgrade does
not change what `parse()` receives. Tests replace the SDK's transport and
assert what the adapter *sends*.

### Where the form lives — the departure from the spec

The spec put the Payment Element on `/checkout/{id}`. That screen never
holds a `client_secret`: the storefront reaches it by a full-page navigation
(the hop that restores the session from the refresh cookie, ADR-038/041),
and ADR-034 says the secret is deliberately not recoverable after one. The
secret is born in three places — `openCheckoutSession` after sign-up,
`startPayment` on an invoice, `retryPayment` on a failed attempt — and
**the form is offered exactly there**, by one shared `PaymentElementPanel`:
the storefront gains a pay step (offers → sign-up → pay → the order),
the invoice screen shows the form after *Take a payment*, the payments list
under the attempt that failed after *Try again*. `/checkout/{id}` stays the
status page a reload lands on. No credential crosses a navigation, which is
the property ADR-034 was written to keep.

**`confirmPayment` is never read as a status.** Whatever Stripe's promise
resolves to, the panel tells its caller the form has been through; the
caller re-reads or navigates to the order, and the status on screen is
whatever the server derives once the webhook has arrived. A decline the
Element reports is shown in Stripe's words, which are written for
customers; the *fact* of failure is still the webhook's to record. The
query string a redirect-based method appends on return is ignored for the
same reason.

**The page talks to Stripe, and the one door is intact.** `@stripe/stripe-js`
loads from `js.stripe.com` — Stripe's PCI attestation depends on that — and
talks to Stripe from inside Stripe's iframe. Nothing in `frontend/src` gains a
`fetch`, a URL of ours or a hand-copied type of ours; `src/api/client.ts`
remains the only door to *this platform's* API and ESLint's ban on `fetch`
holds. What the frontend gains is a provider SDK confined to one module, the
frontend copy of the backend's rule. The deployment CSP
(`deploy/siteground/htaccess.template`) gains `script-src js.stripe.com`,
`frame-src js.stripe.com hooks.stripe.com` and `connect-src api.stripe.com`
**only** when the bundle is built with `--payment-provider stripe`; a bundle
built for no provider keeps `'self'` alone.

## Consequences

- Registration is all-or-nothing: `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
  and `STRIPE_PUBLISHABLE_KEY` together, or no Stripe. A secret key with no
  webhook secret would start payments and never learn what became of them,
  which is no provider. When both Stripe and the stub are configured, Stripe
  is the default; the stub stays for the test suite and a demo.
- A three-decimal currency (KWD, BHD, JOD, OMR, TND) amount whose last digit
  is not zero is refused before any request, `422 PAYMENT_AMOUNT_UNSUPPORTED`,
  in this platform's words rather than Stripe's.
- A refused key is `503 PAYMENT_PROVIDER_REJECTED_KEY` and an unreachable
  provider `503 PAYMENT_PROVIDER_UNREACHABLE` — configuration, not the
  caller's doing. Anything else Stripe refuses is
  `422 PAYMENT_PROVIDER_REFUSED` with Stripe's code in `details`, and
  Stripe's sentence — written for merchants — stays out of the message.
- CI runs on the stub and on fixtures; Stripe's sandbox is never called from
  a test. Everything Stripe would answer is a fixture in
  `tests/Fixtures/stripe/`, and a test that needs a third party's uptime
  fails on a Friday evening for reasons nobody can fix.
- A SEPA debit stays `PENDING` for days; the screen says *"waiting for the
  payment to be confirmed"* for that long, which is true. Copy that explains
  *why* per method is open (spec §10).
- Off-session renewals (a Stripe Customer, `setup_future_usage`, a mandate),
  manual capture, Connect, Stripe Tax and a hosted Checkout front end are
  out of scope and named in the spec's §9. The `pi_` keying is what the
  first of those will build on.

## Alternatives rejected

**Hosted Stripe Checkout.** Zero third-party JavaScript in the page and an
untouched CSP, at the price of the `cs_` → `pi_` mapping above. It remains
available as a second front end on the same adapter once that mapping has an
answer that does not phone Stripe from a webhook.

**The SDK's `Webhook::constructEvent` for verification.** Correct, and
opaque. The signature check is the one place a reader must be able to see
exactly what is compared against what.

**Hand-rolled HTTP calls instead of the SDK.** Four form-encoded calls whose
parameter names, error envelope and idempotency semantics Stripe versions;
the SDK exists to track that, and the deptrac layer keeps it where it
belongs.

**Keeping the secret in memory across the hop to `/checkout/{id}`.** Would
mean reworking how the session is restored after sign-up — ADR-038 and
ADR-041 territory — to save one page. Offering the form where the secret is
born costs nothing and keeps ADR-034's guarantee literal.
