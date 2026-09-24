# The home page tells the product's story

**Status:** specification, 2026-09-24. Nothing below is built.
**Decides:** what `/` shows once a product is chosen, who it speaks to, and
where its words and pictures live.
**Relates to:** `docs/ui-spec.md` (the six regions), `docs/tenant-roots.md`
(one root, two authorities), ADR-041 (what the storefront may reveal),
ADR-047 (a tenant has products), ADR-051 (a product beside the platform),
`docs/translatable-fields-spec.md` (the same translation mechanism).

## 1. What is there today, honestly

`/` inside the shell renders the **catalogue** — the offers of the current
product, with Buy where a permission allows it. A stranger at the same
address gets the storefront instead (`SignInGate`), and a signed-in member
never sees either: `useLanding` moves them to the first entry of their
navigation as soon as they touch `/`.

So the platform has three behaviours at one address and no page that says
**what the product is**. The catalogue answers "what does it cost"; the
storefront answers "what may I buy"; neither answers "what is this, and is
it for me" — which is the only question somebody who has just arrived is
actually asking.

The switcher sits in region A, before the organisation's name.

## 2. The decision

**`/` is the product's story, and the product is chosen in region A right
after the organisation's name.** Choosing another product rewrites the page
below: same shape, that product's words, that product's pictures, that
product's call to action.

Three consequences, each deliberate:

- **A member is no longer ejected.** `useLanding` stops navigating away
  from `/`; it keeps settling the root and the product, and then leaves the
  person on the page. The first navigation entry is one click away in the
  rail, which is where somebody looking for their work will go anyway.
- **A stranger and a member read the same page**, as they already do at a
  tenant root (`docs/tenant-roots.md` §2.2). What differs is the call to
  action, not the story — and what differs is decided by permissions and
  entitlements, never by a second page.
- **The order of the switcher decides what a newcomer sees first**
  (`display_order`, 2026-09-23). The first product is the shop window.

## 3. Who is reading, and what the page offers them

| Who | What they hold | The call to action |
| --- | --- | --- |
| A stranger | no session | *See the offers* — and *Sign in* in region A, as today |
| A member, no subscription on this product | a membership | *See the offers*, and what the organisation already holds elsewhere |
| A member, subscribed, product inside the shell | an entitlement | *Open* — the product's first screen |
| A member, subscribed, product beside the platform | an entitlement and an `app_url` | *Open Plan* — the door of ADR-051 §3, the one the card above the project list already offers |
| A platform administrator | a platform role, no membership | the same page, plus *Edit this page* → the console |

Nothing here is a second implementation of the product card: the card says
where a product lives and what the organisation holds on it, and this page
says what the product **is**. Where both would appear, the page wins and
the card is not repeated.

## 4. The shape of the page

One column, six blocks, in this order. Every block is optional except the
first: a product that has said nothing shows its name, its offers and
nothing invented.

```text
1  Headline        one sentence: what it does, for whom
   Subline         one sentence: the change it makes
   Call to action  the table above decides which
   Illustration    one image or one short loop

2  In three moves  three steps, each a line and an icon
                   ("draw the parcel", "the terrace follows", "the file prints")

3  Use cases       two or three, each: who, what they were doing before,
                   what they do now. The customer recognises themselves here
                   or nowhere.

4  Proof           screenshots of the product itself, captioned.
                   Optional short animation, muted, no autoplay with sound.

5  What it costs   the plans, from the catalogue already built — never
                   retyped here, or two prices exist for one offer.

6  Questions       three to five, answered in two lines each.
```

**Simple and explicit graphics**, per the operator: no decorative
abstraction, no stock photography of people pointing at laptops. A
screenshot of the real product, cropped to the thing being discussed, beats
any illustration of the idea of it.

## 5. Where the words live — and why not in the frontend

A component that carried Plan's headline would be one product's fact baked
into a shell shared by all of them, which is `gate:products` in PHP moved
somewhere the backend cannot see it. The words are **data about a product**,
written by whoever sells it.

**Proposal:** a `product_showcase` row per product and per block, not a blob:

```text
product_showcase
  product_id      the product
  block           HEADLINE | STEPS | USE_CASE | PROOF | QUESTION
  position        within its block kind, in tens (display_order's habit)
  content         JSONB, the block's own fields, values translated
  asset_id        the picture, when the block has one         nullable
```

Rows rather than one document because the console edits one block at a
time, and because a missing picture must not take a headline with it.

The words themselves go in `product_showcase_translations`, one row per
block and per language, beside the English on the block itself — the
mechanism `docs/translatable-fields-spec.md` settles, and the same one the
feature descriptions and the offer names use. The console edits the current
language and offers the others behind the language button at the end of the
field; nothing here invents a second translation UI.

This is operator data, not an application sentence: it never enters
`frontend/src/i18n/catalogues/` and `gate:i18n` has no opinion about it
(that spec, §1.5).

**The pictures** are assets of the product, not of a project: a new
`product_assets` scope beside the existing store, served by signed link the
way `downloadAsset` already is. Size and type are bounded at upload, and an
animation is a video file or an animated image — never a script, because
this page is read by strangers and the CSP must stay as narrow as it is.

## 6. What the API adds

```text
GET  /api/v1/public/products/{code}/showcase     public, cached, per language
GET  /api/v1/staff/products/{productId}/showcase staff.products.manage
PUT  /api/v1/staff/products/{productId}/showcase staff.products.manage
POST /api/v1/staff/products/{productId}/assets   staff.products.manage
```

The public read is the one that matters: a stranger has no session, no
product context and no permissions, so it takes the **code** in the path
and answers from the product alone. It reveals only what somebody decided
to publish — ADR-041's rule holds: what the platform *runs* stays private,
what a product *advertises* is public by choice.

## 7. What must never happen

- No `if (product === '…')` in a component, and no product name in a
  translation key. The page is one component driven by rows.
- No raw HTML from the console rendered into the page. The blocks have
  fields; a rich-text field that accepted markup would be an XSS surface
  reachable by anybody with `staff.products.manage` and read by everybody.
- No price typed into a showcase block. Block 5 reads the catalogue.
- No autoplaying sound, and no animation without `prefers-reduced-motion`
  honoured — ui-spec §4.2's rule, which this page does not get to bend.
- No second shell. This is a screen in the one shell, with its six regions.

## 8. Build order

Each step leaves `composer run gates` and `npm run build` green.

1. **The page, from nothing.** `/` stops redirecting a member; the screen
   renders the product's name, the catalogue's plans, and the right call to
   action from the table in §3. No showcase rows yet — a product that has
   said nothing looks deliberate rather than broken.
2. **The rows.** Migration, the two staff routes, the public read, and the
   console screen that edits blocks in the current language.
3. **The pictures.** `product_assets`, the upload, the signed link, and the
   blocks that carry one.
4. **The demonstration says something.** Plan's showcase written for real —
   headline, three moves, two use cases, two screenshots — so the page can
   be judged on content rather than on lorem.

**Exit criteria**

- A stranger opening the bare host sees the first product's story, and
  switching product rewrites it without a reload.
- A member subscribed to Plan sees *Open Plan* and lands in Plan.
- Every sentence on the page comes from a row; deleting the rows leaves a
  page that still makes sense.
- The page passes axe at 375 px and at desktop width, animation included.
- `gate:ui` accounts for the four new operations.

## 9. Open questions for the operator

1. **Who writes the story?** The console screen assumes the platform's own
   staff. If a tenant may ever publish its own product page, that is a
   different permission and a different ADR.
2. **How many languages must be filled before a page may be published?**
   The mails fall back to English; a shop window that falls back may be
   worse than one that waits.
3. **Does a retired product keep its page?** Its tenants and invoices stay
   (§25); the shop window probably should not.
