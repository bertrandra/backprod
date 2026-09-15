# Taking money through Stripe, starting in a sandbox

**Status:** proposed. Nothing below is built.
**Relates to:** Architecture V2 §24, §25, §31; non-negotiables #17, #20;
[ADR-022](adr/ADR-022-payments-and-webhook-idempotency.md) (the webhook is
the source of truth, exactly once);
[ADR-024](adr/ADR-024-payment-gated-activation.md);
[ADR-034](adr/ADR-034-a-checkout-session-is-an-order.md);
[ADR-038](adr/ADR-038-this-platform-issues-its-own-sessions.md);
[ADR-044](adr/ADR-044-a-product-cannot-invoice-until-somebody-says-who-is-selling.md);
[ADR-045](adr/ADR-045-the-console-shows-the-path-instead-of-refusing-along-it.md).

## 1. What was asked

Integrate Stripe as the payment provider, using a Stripe **sandbox** as the
starting point — so a deployment can take a real card through a real PSP flow
end to end, with test money, before any live key exists.

## 2. What already exists, and what this therefore is

The payment path is built and proven against a provider that is honestly not
one. `StubPaymentProvider` exists so that the whole chain — open a checkout,
raise the invoice, ask the provider to authorize, receive a signed webhook,
apply it exactly once, settle the invoice, activate the subscription — runs
without a PSP, and so that *"the first real adapter has a worked example of
what the port expects"* (its own words).

Everything above the `PaymentProvider` port is therefore done:

| Already decided | Where |
| --- | --- |
| The webhook is the only thing that marks money collected; the API never can | ADR-022 |
| Exactly-once is `UNIQUE (provider, provider_event_id)` on `payment_events`, inside the applying transaction | ADR-022 |
| The signature is verified over the raw bytes, before parsing | ADR-022, `PaymentProvider::verify` |
| Payment status is a one-way machine; a stale delivery is recorded `IGNORED_STALE`, never applied | ADR-022 |
| A checkout session *is* the order; `client_secret` is returned once and never stored | ADR-034 |
| A retry is a new payment with its own provider reference, `<invoice number>/<attempt>` | ADR-034 |
| The subscription waits for the money | ADR-024 |
| A card never reaches this platform; the customer gives it to the provider and what comes back is a handle | §24, the port's docblock |
| Providers are a registry, and with no configured secret there is no provider (fail closed) | `PaymentProviders`, `config/container.php` |
| The console's readiness chain has a `payments` step that reads `PaymentProviders::isConfigured()` | ADR-045 |

**So this is one adapter, one page fragment, one configuration decision and
one deployment decision.** The spec is short on the money path because the
money path is not what is being written. What *is* being decided is the four
places where Stripe's shape does not fit the platform's without a choice:

1. how the customer enters a card without the card touching this platform, on a
   page whose Content-Security-Policy currently says the browser talks to this
   origin only (§4);
2. which Stripe object is *the* provider payment id, so that every event about
   one payment keys on the same string (§5.1);
3. which Stripe events map onto the six the platform models, and what happens to
   the rest (§5.3);
4. how a deployment knows it is running on a sandbox, and never mistakes that
   for live (§7).

## 3. What does not change, stated first

- **Stripe does not invoice, subscribe, or renew.** This platform numbers its
  own invoices gaplessly (ADR-021), snapshots them, and runs its own
  subscriptions and renewals. Stripe Billing, Stripe Subscriptions, Stripe
  Invoices and Stripe Tax are **not used**, and the adapter must never create
  one. Stripe takes one amount for one attempt, and reports what happened to
  it. Two systems each numbering invoices is two legal series for one sale.
- **No card data in PostgreSQL** (§24). Nothing in the adapter's output is an
  instrument; `payments.method` holds the *kind* (`card`, `sepa_debit`), never
  a PAN, a last-four, or a fingerprint.
- **The webhook stays the truth.** The browser's own report that a payment
  succeeded — `stripe.confirmPayment()` resolving, a `redirect_status=succeeded`
  in a return URL — is never read by the screen and never sent to the API. The
  screen waits for the order's status to move, exactly as it does today.
- **`PaymentProvider` does not change.** Not one method. If it had to, the port
  would have been wrong, and the stub — which passes the same tests — would
  have hidden it.
- **`payments` and `payment_events` do not change.** No migration.

## 4. The customer's card: Payment Element, in the page

Stripe offers two ways to collect a card without it touching the merchant:
**Stripe Checkout** (a page Stripe hosts; the customer is redirected there and
back) and the **Payment Element** (a Stripe-hosted iframe inside the merchant's
page, driven by Stripe.js). Both are PCI SAQ-A; neither lets the card into this
platform.

**Decision: the Payment Element, keyed on a PaymentIntent.**

Because of §5.1: with the Payment Element the platform creates the
PaymentIntent itself in `authorize()`, so `provider_payment_id` is `pi_…` from
the first row, and *every* event Stripe will ever send about that money —
succeeded, failed, refunded, disputed — carries that same `pi_…`. Hosted
Checkout keys the page on a `cs_…` session and creates the PaymentIntent later,
so the adapter would have to map `pi_` events back to a `cs_` row by calling
Stripe from inside the webhook handler. An adapter that has to phone the
provider to know which payment a delivery is about is one that fails closed at
the worst moment.

It is also what the port was drawn for. `ProviderPayment.clientSecret` is
documented as *"a redirect URL, a session id, an intent secret"*, and the
checkout screen already says *"waiting for the payment to be confirmed…"* after
the attempt — the Element slots into the gap between the two.

### 4.1 What this costs, and the two rules it bends

**Stripe.js loads from `js.stripe.com`, and must.** Stripe's PCI attestation
depends on the script coming from Stripe, so it is never bundled or vendored.
The deployment's CSP (`deploy/siteground/htaccess.template`) currently says
`script-src 'self'` and `connect-src 'self'` — *"the page never talks to a
third party"* since ADR-038. That sentence becomes: the page talks to this
origin for everything that is this platform's, and to Stripe for the one thing
that must not be this platform's. The policy gains, only when the bundle is
built with Stripe:

```text
script-src  'self' https://js.stripe.com
frame-src   https://js.stripe.com https://hooks.stripe.com
connect-src 'self' https://api.stripe.com
```

`bin/build-dist.sh` already substitutes `@@CONNECT_SRC@@` at build time for
exactly this kind of provider source (it once carried Supabase); the same
mechanism carries the three directives, driven by `VITE_PAYMENT_PROVIDER`.

**"One door" is not breached, and the ESLint rule stays.** `src/api/client.ts`
is the only door to *this platform's API*, and `no-restricted-globals` bans
`fetch` so nobody builds a second contract by hand. `@stripe/stripe-js` calls no
API of ours — it injects a script tag and talks to Stripe from inside Stripe's
iframe. Nothing in `frontend/src` gains a `fetch`, a URL, or a hand-copied type
of ours. What the frontend gains is a *provider SDK confined to one module*
(`src/features/commerce/payment/StripeElement.tsx`), which is the frontend copy
of the rule the backend already has: provider code lives in the adapter and
nowhere else. Recorded in the ADR so the next reader of the CSP does not read
it as drift.

### 4.2 The screen

`commerce.checkout` (`CheckoutScreen.tsx`) today shows the order, the invoice,
and *"waiting for the payment to be confirmed…"*. It gains one region, shown
only while `client_secret` is in hand — i.e. only in the render that received
it from `openCheckoutSession` or `retryPayment`, never after a reload:

```text
┌ Checkout ─────────────────────────────────────────────┐
│ This is order ord_…                                    │
│ Pro, monthly            49,00 €    invoice F-2026-0042 │
│                                                        │
│ ┌ Pay ─────────────────────────────────────────────┐   │
│ │  [ Stripe Payment Element — card / SEPA / wallet ]│   │
│ │  [ Pay 49,00 € ]                                  │   │
│ └──────────────────────────────────────────────────┘   │
│                                                        │
│ Waiting for the payment to be confirmed…               │
└────────────────────────────────────────────────────────┘
```

- `loadStripe(publishable_key)` once per page; `<Elements>` with the
  `client_secret`; `<PaymentElement>`; on submit,
  `stripe.confirmPayment({ elements, confirmParams: { return_url }, redirect: 'if_required' })`.
- `return_url` is `/checkout/{orderId}` — the order's own address, which is
  reachable after any reload by design (ADR-034). Redirect-based methods (3-D
  Secure challenges, bank redirects) come back there; **the query string Stripe
  appends is ignored**, because the status on screen is the server's.
- After `confirmPayment` resolves — success or a client-side error such as a
  declined card — the screen does one thing: invalidates the session query and
  keeps polling it. A decline the Element reports is shown as Stripe's message
  (it is written for customers); the *fact* of failure is still the webhook's
  to record, and `PAYMENT_FAILED` on the session is what offers "Try again".
- The amount on the button is the invoice's, formatted by `ui/Money.tsx`. The
  Element is never told an amount: the PaymentIntent already holds it, and two
  numbers for one payment is what §4 forbids.
- After a reload there is no `client_secret` and no Element; the screen says
  *"Paying after a reload is a new attempt"* and offers exactly that, as today.

### 4.3 The contract change, and it is small

`openCheckoutSession` and `retryPayment` already return `client_secret`. They
gain, beside it, what the page needs to *use* one:

```json
"payment_provider": {
  "name": "stripe",
  "publishable_key": "pk_test_…",
  "sandbox": true
}
```

- Nullable, absent for the stub (which has nothing a browser can drive).
- `publishable_key` is not a secret — it is designed to sit in a page — so it
  belongs in the contract rather than in a build variable, which would freeze
  one deployment's key into a bundle that another deployment reuses.
- `sandbox` is the server's word (§7), so the page can say so.

One schema, `PaymentProviderClient`, referenced from both operations. No new
operation, so `gate:ui` and `docs/ui-api-coverage.json` are untouched;
`npm run generate` follows.

## 5. The adapter: `StripePaymentProvider`

`App\Payment\Infrastructure\Stripe\StripePaymentProvider`, named `stripe` —
which is what `payments.provider` records and what the webhook path carries:
`POST /api/v1/webhooks/payments/stripe`, already routed by `{provider}`.

### 5.1 `authorize(Money $amount, string $reference): ProviderPayment`

`POST /v1/payment_intents`:

| Stripe field | Value | Why |
| --- | --- | --- |
| `amount` | `$amount->minorUnits` | Stripe's unit is the currency's smallest, the same convention as `Money`. *Three-decimal currencies (KWD, BHD, JOD, TND, OMR) must end in 0 — Stripe refuses otherwise; the adapter refuses first, with `PAYMENT_AMOUNT_UNSUPPORTED`, rather than letting Stripe's message reach a customer.* |
| `currency` | lowercase ISO code | |
| `automatic_payment_methods[enabled]` | `true` | Card, wallets, SEPA and bank redirects as the Stripe dashboard enables them — §24's list — with no branch here per method. |
| `metadata[reference]` | `$reference` | `<invoice number>/<attempt>`; what a human lines the two systems up by. Also `description`, so it shows on the Stripe dashboard row. |
| `Idempotency-Key` header | `$reference` | ADR-034 made the reference name the *attempt*, so a retried `authorize()` for the same attempt returns the same intent rather than a second one. |

Returns `new ProviderPayment(id: 'pi_…', status: PENDING, clientSecret: $intent->client_secret, method: null)`.
The method is unknown until the customer chooses one; it is filled from the
succeeded event (§5.3).

Never `capture_method: manual`, never `confirm: true`, never a `customer`:
authorization-then-capture, server-side confirmation and stored instruments are
each a different product decision with its own ADR (§9).

### 5.2 `verify(string $rawBody, array $headers): void`

Stripe signs with `Stripe-Signature: t=<unix>,v1=<hex>[,v1=<hex>…]`, where the
signature is HMAC-SHA256 over `"<t>.<rawBody>"` with the endpoint's `whsec_…`.
The adapter:

1. reads `t` and every `v1` from the header (several during a secret rotation);
2. refuses if `|now − t| > 300 s` — a replay window, the one thing the stub's
   scheme does not have and a real one needs;
3. computes the HMAC over `t . '.' . $rawBody` — the bytes exactly as received,
   which is why the port takes them raw;
4. accepts if **any** `v1` matches in constant time (`hash_equals`);
5. throws `UnauthenticatedException` otherwise. Absent, malformed, stale and
   wrong are one refusal: none of them is a distinction a caller should be able
   to learn.

No SDK call in the verification path. It is thirty lines, it is the part that
matters, and it must be readable without opening a vendor directory.

### 5.3 `parse(string $rawBody): ?ProviderEvent`

`ProviderEvent.id` is Stripe's `evt_…` — stable across retries, which is what
`payment_events_delivered_once` needs. `providerPaymentId` is always the
`pi_…`, taken from wherever the event carries it:

| Stripe event | `ProviderEvent.type` | `providerPaymentId` from | Also carried |
| --- | --- | --- | --- |
| `payment_intent.succeeded` | `PAYMENT_SUCCEEDED` | `data.object.id` | `amountMinorUnits = amount_received`; `payload.method` = the charge's `payment_method_details.type` |
| `payment_intent.payment_failed` | `PAYMENT_FAILED` | `data.object.id` | `failureCode = last_payment_error.code` (or `decline_code` when present), `failureReason = last_payment_error.message` |
| `payment_intent.canceled` | `PAYMENT_CANCELLED` | `data.object.id` | |
| `refund.updated` with `status = succeeded` | `REFUND_SUCCEEDED` | `data.object.payment_intent` | `providerRefundId = data.object.id`, `amountMinorUnits = amount` |
| `charge.dispute.created` | `CHARGEBACK_OPENED` | `data.object.payment_intent` | `amountMinorUnits = amount`, `payload.reason` |
| anything else | **`null`** | | `payment_intent.created`, `charge.succeeded`, `payment_intent.processing`, `refund.created`, `charge.refunded`, `charge.dispute.closed`, every `customer.*`, `invoice.*`, `checkout.*`… |

Two things the table encodes:

- **`null` is not an error.** The port says so, ADR-022 says why: a 4xx makes
  Stripe retry until it gives up, and buries the deliveries that matter. The
  endpoint answers 200 to a `null` today (`IGNORED_NOT_APPLICABLE`) and nothing
  changes.
- **`PAYMENT_AUTHORIZED` is never emitted.** It corresponds to
  `amount_capturable_updated`, which only fires under manual capture, which §5.1
  rules out. Stripe's `processing` state (a SEPA debit awaiting settlement) maps
  to nothing: the payment stays `PENDING`, which is what it is, and the screen
  keeps saying so.
- `payload` keeps `id`, `type`, `payment_intent`, `refund`, `method`, `reason` —
  the fields above and no more. A raw Stripe event is 3 KB of things nobody
  vetted, and `payment_events.payload` is a column humans read while
  investigating money.

`occurredAt` is `created` (a Unix timestamp), converted to UTC.

Livemode is checked: an event whose `livemode` disagrees with the configured
key's mode is parsed to `null` and logged at `warning`. A live key with a test
event, or the reverse, is a misconfigured webhook endpoint in the Stripe
dashboard, and silently applying test money to live invoices is the worst
version of it.

### 5.4 `refund(Payment $payment, Money $amount, string $reason): ProviderRefund`

`POST /v1/refunds` with `payment_intent = $payment->providerPaymentId`,
`amount`, `reason` mapped to Stripe's three (`duplicate`, `fraudulent`,
`requested_by_customer`; anything else → `requested_by_customer` with the real
reason in `metadata[reason]`), and `Idempotency-Key` = a hash of
`(payment id, amount, reason)` — the same shape the stub mints its refund id
from, for the same reason: two identical requests are one refund.

Returns `new ProviderRefund('re_…', 'PENDING')`. Settlement arrives by
`refund.updated`, as ADR-022 requires; `Payments::refund` already records
`PENDING` and waits.

### 5.5 Talking to Stripe

**`stripe/stripe-php`, pinned, confined to `App\Payment\Infrastructure\Stripe\`.**
The alternative — four form-encoded calls over a PSR-18 client — is small, but
it is four calls whose parameter names, error envelope and idempotency
semantics Stripe versions, and the SDK exists to track that. The confinement is
what makes the SDK acceptable under *"the domain must not depend directly on
PSP SDKs"*: `deptrac.yaml` gains a `Stripe` layer collected by `Stripe\\`, and
the only layer allowed to depend on it is `Infrastructure`. A `Stripe\` import
in a service or a controller fails `composer run deptrac`.

The SDK's HTTP client is replaced in tests (`\Stripe\ApiRequestor::setHttpClient`)
by a recording fake, so `authorize()` and `refund()` are unit-tested for *what
they send* — amount, currency, idempotency key, metadata — without a network.

API version pinned in the client (`Stripe::setApiVersion`), so a dashboard
upgrade does not change the shape of what `parse()` receives.

## 6. Configuration and registration

```text
STRIPE_SECRET_KEY=            sk_test_… (sandbox / test mode) or sk_live_…
STRIPE_WEBHOOK_SECRET=        whsec_… — the endpoint's, from the dashboard or `stripe listen`
STRIPE_PUBLISHABLE_KEY=       pk_test_… or pk_live_…; handed to the page (§4.3)
```

`config/container.php`'s `PaymentProviders` factory registers Stripe **first**
(the default) when all three are set, keeps the stub *after* it when its own
secret is set, and registers nothing otherwise. The same fail-closed rule as
today: a half-configured Stripe — a secret key with no webhook secret — is
**no provider**, not a provider that cannot verify anything, because
`PaymentProviders::isConfigured()` is what the readiness screen and the
checkout both believe.

The three keys go in `.env.example` with one comment each, and in
`deploy/siteground/setup.php`'s form as three optional fields (blank = no
Stripe, the installer stays honest that payments are not configured).

## 7. Sandbox, and never mistaking it for live

Stripe's **Sandboxes** are isolated test environments with their own keys, own
webhook endpoints and own dashboard, all in test mode: keys read `sk_test_` /
`pk_test_`, every event carries `livemode: false`, and money is not real. That
is the starting point asked for, and it is also the permanent shape of every
non-production deployment.

The platform tells the two apart from the key prefix and says so in three
places, because a demo running on real cards and a production running on test
cards are the two mistakes that cost the most:

| Where | What it says |
| --- | --- |
| `GET /staff/readiness` `payments` step | `detail: { provider: 'stripe', sandbox: true }` — done and non-blocking; the console's setup chain shows *"Payments: Stripe (sandbox)"* |
| `openCheckoutSession` → `payment_provider.sandbox` | the checkout screen shows a **Test payment** band above the Element: *"This is a sandbox. Use a test card; no money moves."* |
| `bin/preflight.php` | `APP_ENV=prod` with a `sk_test_` key is a **warning**, printed and logged, never a refusal — a production-shaped demo is legitimate, and the person deploying it should read the line |

Test cards the suite and the operator use (Stripe's published ones; they work
in every sandbox):

```text
4242 4242 4242 4242   succeeds
4000 0025 0000 3155   requires 3-D Secure — exercises the return_url path
4000 0000 0000 9995   declined, insufficient_funds — exercises PAYMENT_FAILED
4000 0000 0000 0259   succeeds, then disputed — exercises CHARGEBACK_OPENED
```

Local webhooks: `stripe listen --forward-to 127.0.0.1:8080/api/v1/webhooks/payments/stripe`
prints a `whsec_…` for `STRIPE_WEBHOOK_SECRET`; `stripe trigger payment_intent.succeeded`
replays a shaped event. Documented in the README's *Running locally*.

## 8. Tests, gates and what proves it

| | What is proven |
| --- | --- |
| `tests/Unit/Payment/StripeSignatureTest` | the scheme of §5.2 against fixed vectors: a valid `v1`, two `v1`s during rotation, wrong secret, altered body, `t` older than 300 s, missing header — five refusals and two acceptances, and that acceptance is constant-time by construction (`hash_equals`) |
| `tests/Unit/Payment/StripeEventsTest` | every row of §5.3 from trimmed real event fixtures (`tests/Fixtures/stripe/*.json`), including that `charge.succeeded` and `payment_intent.created` parse to `null`, that `livemode` mismatch parses to `null`, and that `payload` carries only the vetted fields |
| `tests/Unit/Payment/StripeRequestsTest` | `authorize()` sends amount, lowercase currency, `automatic_payment_methods`, `metadata.reference` and the idempotency key; three-decimal amounts not ending in 0 are refused before any request; `refund()` sends `payment_intent`, `amount`, mapped `reason`, idempotency key |
| `tests/Integration/StripeWebhookTest` (DB) | the **whole chain** with the Stripe adapter registered and the test computing a real `Stripe-Signature`: open a checkout → `payment_intent.succeeded` → invoice settled, subscription active, ledger has one `PAYMENT_SUCCEEDED`; the same delivery twice → 202 and one ledger row; `payment_failed` after `succeeded` → `IGNORED_STALE`; `refund.updated` → refund settled; `charge.dispute.created` → `CHARGEBACK`; an unsigned delivery → 401 and nothing written. Modelled on the existing stub-driven webhook tests, which stay as they are — the stub is still the fastest way to prove the *pipeline*, and it stays configured in CI |
| `frontend` Vitest, `CheckoutScreen.test.tsx` | `@stripe/stripe-js` mocked: the Element region renders only with a `client_secret`; a resolved `confirmPayment` invalidates the session and does **not** write a status; a rejected one shows the message and leaves status to the server; no region after a "reload" render; the sandbox band appears when `sandbox: true` |
| Playwright `checkout.spec.ts` | `page.route('**/js.stripe.com/**')` serves a fake `Stripe()` whose `confirmPayment` resolves; the screen reaches "waiting for the payment to be confirmed" and never calls anything but the contract's operations |
| `composer run deptrac` | `Stripe\` reachable from `Infrastructure` only |
| `gate:client`, `gate:ui`, `gate:money` | the contract change regenerates the client; no new operation; nothing optimistic in the checkout module |

CI keeps running on the stub. Stripe's sandbox is not called from CI: a test
that needs a third party's uptime to pass is a test that fails on a Friday
evening for reasons nobody can fix, and everything Stripe would answer is
already a fixture. One **manual** checklist in `docs/deploying-to-siteground.md`
runs the four test cards against a sandbox before a deployment goes live.

## 9. Out of scope, named rather than discovered

- **Off-session renewals.** Today a renewal raises an invoice and the customer
  pays it through a new checkout attempt. Charging a stored instrument without
  the customer present is a Stripe **Customer** + `setup_future_usage` +
  off-session PaymentIntent, a mandate for SEPA, and a customer-facing consent
  step. Its own spec; nothing here precludes it and the `pi_` keying is what it
  will build on.
- **Manual capture** (authorize now, capture on fulfilment), **server-side
  confirmation**, **Stripe Connect / marketplaces**, **Stripe Tax** (§25.3 is
  this platform's own), **Link**, **Radar rules**.
- **Hosted Stripe Checkout** as an alternative front end. Rejected above (§4)
  for the id-mapping reason; a deployment that wants zero JavaScript from a
   third party can have it as a second front end later, on the same adapter,
  once the `cs_` → `pi_` question has an answer that does not phone Stripe from
  a webhook.
- **A second PSP.** The registry already allows it; nothing here narrows that.

## 10. Open questions

1. **Payment methods to enable in the sandbox dashboard.** `automatic_payment_methods`
   shows whatever the dashboard enables. Card only to start, or card + SEPA
   Direct Debit? SEPA settles in days, which the `PENDING`-until-webhook
   behaviour handles, but a customer who paid by SEPA sees "waiting" for a
   week — the screen should say *why* for that method (§4.2), and that copy is
   a product decision.
2. **Wallets.** Apple Pay needs a domain registered with Stripe per deployment;
   Google Pay does not. Worth a line in the installer, or left to the operator?
3. **What a `CHARGEBACK` does downstream.** ADR-022 decided it does not rewrite
   the invoice. Whether it suspends the subscription, notifies finance
   (`admin.finance.read`), or both, is not decided anywhere yet — this spec
   delivers the event and stops there.

## 11. Rough size

| Piece | |
| --- | --- |
| `StripePaymentProvider` + signature + event mapping | ~300 lines, one class, one small `StripeSignature` helper |
| Container registration, env, installer fields, preflight warning | ~60 lines across four files |
| Contract: `PaymentProviderClient` on two operations; regenerate | small |
| Readiness `payments` step detail | ~10 lines |
| `StripeElement.tsx` + checkout screen region + sandbox band | ~150 lines |
| CSP directives in `build-dist.sh` / `htaccess.template` | ~15 lines |
| `deptrac.yaml` layer | 4 lines |
| Tests: 3 unit files, 1 DB integration, screen tests, 1 Playwright spec, fixtures | the larger half |
| ADR-048 *"Stripe is the first real provider, and the page talks to it"* — the CSP and one-door decisions of §4.1, the `pi_` keying of §5.1 | 1 document |

One PR, behind the same gates as everything else; the stub stays.
