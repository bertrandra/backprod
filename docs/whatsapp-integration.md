# WhatsApp integration — specification

**Status:** proposed, not scheduled. Written 2026-09-17 at the operator's
request, after the question "what would it take". Nothing below is built.
**Relates to:** Architecture V2 §27.1 (notifications), §12.3 (messaging),
§24 (webhooks); non-negotiables #17 (no PSP/provider in the domain) and #24
(no SMS or WhatsApp without provable, revocable consent; no security notice
that can be switched off); ADR-048 (the shape a provider adapter takes here).

---

## 1. What exists already

§27.1 was designed with WhatsApp as a channel, so most of what a first
integration needs is in place and tested:

| Already there | Where |
|---|---|
| `Channel::WHATSAPP` beside SCREEN, EMAIL, SMS | `src/Notification/Domain/Channel.php` |
| The `Notifier` port — one adapter per channel, `send(address, subject, body, payload) → provider message id` | `src/Notification/Domain/Notifier.php` |
| Delivery rows written up front, one per candidate channel; `SUPPRESSED` with a reason when the gate refuses; exactly-once by `UNIQUE (notification_id, channel)` | `notification_deliveries`, `DispatchNotifications` |
| Sending through the M7 job queue, never inside a request | `bin/run-jobs.php` |
| Consent with proof, per user, channel and purpose (`TRANSACTIONAL` / `MARKETING`), revocable; the gate fails closed without it | `notification_consents`, `DeliveryGate`, `POST/DELETE /notifications/consents` |
| Preferences per category and channel; `SECURITY` cannot be muted | `notification_preferences` |
| A stub adapter that logs instead of sending, wired for EMAIL, SMS and WHATSAPP | `LogNotifier`, `config/container.php` |
| Delivery statuses `PENDING → SENT → DELIVERED / FAILED`, and `provider_message_id` on the row | migration `Version20260904050000` |

What is missing is a phone number, a real adapter, the provider's
templates, and the provider's delivery receipts. Everything else — who may
be told what, on which channel, and how the platform proves it — does not
change.

## 2. Two scopes, and which comes first

```text
A. Outbound notifications   the platform tells a person something      §27.1
B. Two-way support          a person answers, staff reply              §12.3
```

**A first.** It is a channel adapter behind an existing port, it ships
without a new concept, and it is what most of the notification catalogue
wants (a payment failed, a renewal is coming). B turns WhatsApp into a
*conversation* transport and meets the provider's rules head on — the
24-hour window, opt-out handling, media, identity matching — and is the
part where a mistake costs a business account its standing. B can come
later on the same number and the same adapter.

## 3. The provider

**WhatsApp Business Platform — Cloud API**, hosted by Meta
(`graph.facebook.com/v<N>/<phone_number_id>/messages`). Not the on-premises
API (deprecated) and not a reseller unless the operator prefers a reseller's
billing; the port makes that a change of adapter, not of code.

What the operator has to obtain, none of which is code:

1. A **Meta Business Account**, and **business verification** (legal
   documents; days to weeks).
2. A **WhatsApp Business Account** (WABA) under it, with a **phone number
   dedicated to the API** — a number cannot be on the API and in the
   WhatsApp app at the same time — and its **display name** approved.
3. A **system user access token** (long-lived) with `whatsapp_business_messaging`
   and `whatsapp_business_management`, and the **app secret** that signs
   webhooks. These are secrets: pasted by the operator into the host's
   `.env`, never handled by anybody else, as with Stripe's.
4. **Message templates**, one per notification type and language, submitted
   and approved by Meta (hours to a day each).

Until verification lands, Meta provides a **test number** that can message
up to five pre-registered recipients: enough for the demo world.

**Pricing** is per conversation opened, by category (utility, marketing,
authentication, service), per country; on the order of €0.03–0.10 per
utility conversation in the EU. The platform records every delivery, so the
bill is reconcilable against `notification_deliveries` by month.

## 4. The rules WhatsApp imposes, and where each lands in this platform

| WhatsApp's rule | Consequence here |
|---|---|
| A business may *initiate* a conversation only with a **pre-approved template**; free text is allowed only within **24 hours** of the customer's last message | Every outbound notification is a template. The adapter maps `notification.type → (template name, language, parameters)`; a type with no template is not sent on this channel (delivery `SUPPRESSED`, reason `NO_TEMPLATE`), never sent as free text and refused. Free text is scope B only. |
| Templates are per language and reviewed for wording | The template catalogue is data, versioned in the repo, and the payload the notification stores is what fills the placeholders — "store the payload, render at send time" (§27.1) is exactly this |
| **Opt-in** required, and the person may opt out at any time (replying STOP is a convention, not enforced by Meta, but expected) | Already §27.1's consent model. Add: an inbound `STOP`/`ARRÊT` on the number **revokes** the consent (scope B webhook, but worth doing in A as the one inbound case handled) |
| Recipient addressed by **E.164** phone number | A phone number on the account (§5.1), validated E.164, stored only once consented |
| Delivery receipts arrive by **webhook**: `sent`, `delivered`, `read`, `failed` (with an error code) | `POST /api/v1/webhooks/notifications/whatsapp`, signed with the app secret (`X-Hub-Signature-256`, HMAC-SHA256 over the raw body), moving the delivery row; a verification `GET` challenge on the same address at registration |
| **Quality rating** per number: too many blocks/reports and the number is throttled or banned | Marketing stays off by default (already: MARKETING is opt-in), and a `failed` receipt with a "user not opted in"-class error revokes rather than retries |
| Rate limits per number (tiered by volume) | The queue already retries with backoff; a `429`-class refusal is a retry, a template or recipient refusal is a `FAILED` |

## 5. Scope A — design

### 5.1 A phone number on the account

```text
users
└── phone_e164        TEXT NULL    CHECK (phone_e164 ~ '^\+[1-9][0-9]{6,14}$')
```

- Set from **Your profile** (`PATCH /me`, one new optional field), through a
  phone picker: country from `CountrySelect` (ui/pickers), national number
  typed, composed to E.164 on the client and **validated on the server** —
  the API is the authority, as everywhere.
- **Not verified in v1.** A wrong number means a message to a stranger, so
  the consent step (below) is what stands in front of the first send; a
  verification code by WhatsApp itself (Meta's `authentication` template
  category) is a natural v2 and would use the same adapter.
- `DispatchNotifications::addressFor()` returns it for `SMS` and `WHATSAPP`;
  no number → delivery `SUPPRESSED`, reason `NO_ADDRESS` (today: empty
  address is already suppressed; the reason is made explicit).
- Erasure (§16) blanks it with the rest of the identity.

### 5.2 Consent, as it exists, with one screen

`POST /notifications/consents {channel: WHATSAPP, purpose: TRANSACTIONAL,
source, evidence}` already exists. What is missing is the place a person
gives it: **Notification settings** gets a "WhatsApp" block — the number on
the account, a checkbox "Send me billing and account notices on WhatsApp"
whose tick records the consent with `source: 'notification-settings'` and
evidence `{ip, user_agent, at}`, and whose untick revokes it. MARKETING is a
second, separate checkbox, off by default (§27.1).

### 5.3 The adapter

```text
src/Notification/Infrastructure/WhatsApp/
├── WhatsAppChannel.php        implements Notifier; channel() = WHATSAPP
├── WhatsAppTemplates.php      type → template name, language, parameter order
├── WhatsAppWebhookParser.php  receipts → delivery transitions; STOP → revoke
└── GraphApiClient.php         the HTTP calls, the only file that knows the URL
```

- `send()` posts `{messaging_product: "whatsapp", to, type: "template",
  template: {name, language: {code}, components: [{type: "body",
  parameters: [...]}]}}` and returns `messages[0].id` as the provider
  message id. A `4xx` naming the template or the recipient throws a refusal
  the dispatcher records as `FAILED` with the provider's code in
  `failure_reason`; a `429`/`5xx`/timeout throws a transient error the queue
  retries.
- **Configuration:** `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`,
  `WHATSAPP_APP_SECRET`, `WHATSAPP_API_VERSION` (default pinned). All three
  secrets absent → the `LogNotifier` stays wired and the channel is
  "not configured", visible on the console's Setup screen the way the
  payment provider is. Partial configuration is refused at boot, as
  Stripe's is.
- **Language:** the template language is the recipient's `users.locale` if
  the platform has one by then, else the product's default, else `en`. A
  type with a template in some languages but not this one falls back to
  the default language rather than being suppressed.
- No provider name in `src/Notification/Domain` or `Service`. Deptrac
  already forbids `Stripe\` outside its adapter; the Graph client is
  hand-rolled over PSR-18, so there is no SDK layer to add.

### 5.4 Templates (the first catalogue)

| Type | Category | Template (utility) | Parameters |
|---|---|---|---|
| `payment.failed` | BILLING | `payment_failed` | product, amount, invoice number |
| `invoice.issued` | BILLING | `invoice_issued` | invoice number, amount, due |
| `subscription.renewing` | BILLING | `renewal_notice` | product, date, amount — the §13.1 pre-renewal notice, whose sending must be answerable |
| `subscription.ending` | BILLING | `subscription_ending` | product, date |
| `security.sign_in` | SECURITY | `new_sign_in` | device, place, time — not mutable, as SECURITY never is |

Each row is a template submitted to Meta in every language the platform
serves, with the wording kept in the repo beside the mapping so the
approved text and the parameters the code fills cannot drift apart.
Marketing templates: none in v1.

### 5.5 Receipts

`POST /api/v1/webhooks/notifications/whatsapp` — public, unauthenticated,
signature-checked like the payment webhook (§24), replay-safe by the
provider's message id, and answering `200` to anything it recognises and
`200` to anything it does not (Meta retries on non-2xx, and a receipt for
a delivery the platform never made is not a failure worth a retry storm —
it is logged).

```text
statuses[].status  sent       → delivery SENT        (already SENT: no-op)
                   delivered  → delivery DELIVERED
                   read       → delivery DELIVERED   (read is not stored; see §12.3 for why read state is a watermark, not a flag)
                   failed     → delivery FAILED, failure_reason = errors[0].code + title
messages[] text "STOP" (any case, FR/EN) → consent WHATSAPP revoked, source 'inbound-stop', evidence the message id
```

The `GET` verification handshake (`hub.mode=subscribe`,
`hub.verify_token`, `hub.challenge`) is answered with the challenge when the
token matches `WHATSAPP_VERIFY_TOKEN`.

### 5.6 Contract, coverage, gates

- `openapi.json`: `whatsappWebhook` (POST) and `whatsappWebhookVerify`
  (GET) under `/api/v1/webhooks/notifications/whatsapp`; `phone_e164` on
  `Me` and on `PATCH /me`; nothing else — consents and preferences are
  already declared.
- `docs/ui-api-coverage.json`: the two webhook operations under
  `not_in_ui` with the reason the payment webhook has; `phone_e164` is
  part of `account.profile`.
- Tests: adapter against a fake PSR-18 client (request shape, id
  returned, refusal vs transient); webhook signature, replay, each status,
  STOP; dispatcher suppresses with `NO_ADDRESS` and `NO_TEMPLATE`;
  Playwright for the settings block. The existing `NotificationsTest`
  cases for consent keep holding.
- ADR-049 "WhatsApp is the second real provider, behind the fifth port",
  short, on the ADR-048 model.

### 5.7 Estimate

| Piece | Effort |
|---|---|
| Phone number: migration, `PATCH /me`, profile field with the picker, `addressFor()` | ½ day |
| Consent block on Notification settings | ½ day |
| Adapter + template catalogue + configuration + readiness row | 1 day |
| Receipts webhook + STOP | ½ day |
| Tests, contract, docs, ADR | ½ day |
| **Code** | **~3 days** |
| Meta: business verification, number, display name, five templates approved | operator; days to weeks, in parallel |

## 6. Scope B — two-way support, sketched

To be specified when A has run for a while. The shape:

- An inbound message from a known number (matched on `users.phone_e164`,
  exactly one live match or nothing) opens or continues a **`SUPPORT`
  conversation** (§12.3) with the sender as participant and a `channel`
  column on the message (`APP` | `WHATSAPP`), so the console's
  Conversations shows where each message came from.
- A staff reply on such a thread goes out as **free text while the 24-hour
  window is open** (the last inbound message's time is on the thread) and
  as a `support_reply` **template** otherwise; the screen says which will
  happen before the reply is sent.
- Unknown numbers get one templated "we don't recognise this number" and
  nothing else; media is stored through `StorageProvider` and linked, not
  inlined; an inbound message is *also* a `message.unread` notification to
  staff, through the existing queue.
- Identity is the hard part: a number is not an account. A number on two
  accounts (a shared office phone) must not silently route to one; the
  first version refuses (`AMBIGUOUS_NUMBER`) and tells the sender to write
  from the app.

Estimate, roughly: **1–2 weeks**, mostly in messaging and the console.

## 7. Out of scope, deliberately

- Sending from the customer's own WhatsApp number (each tenant its own WABA):
  a platform-level number is the product; per-tenant numbers are a later
  commercial decision and would sit in product configuration.
- Marketing broadcasts: MARKETING consent exists, but campaigns are a
  feature, not a channel.
- Interactive messages (buttons, lists): nice for B, nothing for A.
- WhatsApp as a sign-in factor: the `authentication` template category makes
  it possible; it is an identity decision, not a notification one.

## 8. Decisions to take before starting

1. Meta directly, or a reseller (Twilio, 360dialog, Infobip) that fronts the
   same Cloud API with its own billing and support? Same adapter interface;
   the reseller changes the base URL and the token, and adds a monthly fee.
2. Which languages the first templates are approved in (the demo world is
   English; the operator's customers may not be).
3. Whether `security.sign_in` goes to WhatsApp at all — it is the one
   notice that cannot be muted, and a security notice arriving on a channel
   a person did not choose is its own kind of noise. Proposal: only with
   consent given, like any other, since #24 governs the channel and not the
   category.
