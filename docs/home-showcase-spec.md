# The home page tells the product's story

**Status:** specification, 2026-09-24 (revised the same day with the
operator's four decisions, §11). Nothing below is built.
**Decides:** what `/` shows once a product is chosen, who it speaks to,
where its words and pictures live, **how it looks**, and how it is built so
that changing a band is changing one file.
**Relates to:** `docs/ui-spec.md` (the six regions, §4.2 mobile, §4.3 what
"modern" means here), `docs/tenant-roots.md` (one root, two authorities),
ADR-041 (what the storefront may reveal), ADR-047 (a tenant has products),
ADR-050 (the language is presentation, English is the key), ADR-051 (a
product beside the platform), ADR-052 (a feature is the platform's word),
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
| Anybody, product retired | — | the story, and *No longer sold* where the prices were (§11.3) |

Nothing here is a second implementation of the product card: the card says
where a product lives and what the organisation holds on it, and this page
says what the product **is**. Where both would appear, the page wins and
the card is not repeated.

## 4. The shape of the page

Six bands, in this order. Every band is optional except the first: a
product that has said nothing shows its name, its offers and nothing
invented.

```text
1  HEADLINE     one sentence: what it does, for whom
                one sentence: the change it makes
                the call to action the table above decides
                one image or one short loop

2  STEPS        "in three moves" — three steps, each a line and an icon
                ("draw the parcel", "the terrace follows", "the file prints")

3  USE_CASE     two or three, each: who, what they were doing before,
                what they do now. The customer recognises themselves here
                or nowhere.

4  PROOF        screenshots of the product itself, captioned.
                Optional short animation, muted, never autoplaying sound.

5  PRICING      the plans, from the catalogue already built — never retyped
                here, or two prices exist for one offer. Empty when nothing
                is on sale, and it says so rather than showing a blank band.

6  QUESTION     three to five, answered in two lines each.
```

`PRICING` is the one band with no rows of its own: it is a position in the
order and nothing else. Everything it renders comes from `getPublicOffers`
for a stranger and from the catalogue for a member, which is why a price
can never disagree with itself.

## 5. How it looks

The operator asked for a **wow**, and earlier for *simple and explicit
graphics — no decorative abstraction, no stock photography of people
pointing at laptops*. Those are not in tension, and it is worth writing
down why, because the next person will read one and forget the other.

**The wow comes from composition, type, rhythm and the product itself** —
not from ornament. A screenshot of the real thing, cropped to the one
feature being discussed and given room to be looked at, is more convincing
than any illustration of the idea of it. What the page spends its budget on
is size, space, restraint and one honest motion.

### 5.1 Five properties, in order of how much they carry

**1. A display type scale, which the application does not have.** The scale
in `index.css` stops at `--text-3xl` (1.875rem). That is right for a dense
admin screen and far too small for a hero — a headline set at 30px reads as
a section title, and no amount of weight fixes it. The showcase adds
display steps as tokens, beside the existing scale rather than instead of
it:

```text
--text-display-sm   1.75rem / 2.1rem   -0.02em    mobile headline, desktop band title
--text-display-md   2.5rem  / 2.8rem   -0.025em   mobile hero, desktop sub-head
--text-display-lg   3.5rem  / 3.7rem   -0.03em    desktop hero
--text-display-xl   4.5rem  / 4.6rem   -0.035em   desktop hero, short headlines only
```

Tracking tightens as the size grows, which is the whole difference between
large text that reads as designed and large text that reads as zoomed. The
step is chosen by the band, from the token, never by a component writing
`text-[52px]`.

Display text also sets `font-variant-numeric: proportional-nums`,
overriding the `tabular-nums` the body sets globally. Tabular figures are
right for a column of invoices and wrong in a sentence — "3 minutes" in
tabular figures has a visible gap where the 3 was padded.

**2. Full-bleed bands, and vertical room.** Every band runs edge to edge
and centres its content in a `max-w-6xl` measure, with prose capped at
`max-w-prose` inside it. Vertical padding is the thing the application has
none of and the story needs most: `7rem` desktop, `3.5rem` mobile, between
bands. Rhythm comes from alternating the surface tokens that already
exist — `canvas`, `surface`, `well` — never from a new colour.

**3. Neutral structure, accent only on the action.** The bands are built in
`ink` / `muted` / `subtle` on `canvas` / `surface` / `well`, with `line`
for edges. `--ds-accent` appears on the call to action and on a link, and
nowhere else at size.

This is not timidity, it is a constraint the palette already has:
`--ds-accent` is **a tenant's to override** (`tenant.branding`, U2). A hero
washed in the accent would repaint one company's product story in another
company's brand colour the moment a reader opened `/globex/`. Keeping the
accent on the button is what makes the page safe under any brand — and the
button *should* follow the tenant's colour, because it is the button
somebody presses in that tenant's shop.

**4. One image, large, real.** The hero image and each proof screenshot are
the product, cropped to the thing being discussed:

- mobile: full-bleed, edge to edge, `aspect-ratio` reserved so nothing
  jumps when it loads;
- desktop: inset in a `radius-card` frame with `shadow-float` and a
  `line` hairline — the two shadow levels that already exist, and the one
  place on the platform where `shadow-float` is used on something that is
  not floating, because the image is what the band is *for*;
- `loading="lazy"` below the fold, `fetchpriority="high"` on the hero's.

No device mockups. A browser chrome drawn around a screenshot dates the
page to the year the chrome was drawn.

**5. One motion, and the page is complete without it.** Each band rises
12px and fades in over 400ms on `--ease-out-quart`, children staggered
60ms, triggered once by an `IntersectionObserver`. Three rules keep it
honest:

- **the page renders complete at rest.** The resting state is visible and
  the observer only animates the arrival. A band parked at `opacity: 0`
  waiting for an observer is invisible to anything that does not run the
  observer — a screenshot, a shared link preview, a reader who scrolled
  fast — which is a page that shows nothing;
- **`prefers-reduced-motion` removes it entirely**, which `index.css`
  already enforces globally and this page does not get to bend
  (ui-spec §4.2);
- **no parallax, no scroll-jacking, no pinned sections.** They fight the
  reader for control of the scrollbar and they are unusable with a
  keyboard.

### 5.2 Navigating the page

Long pages need a way back to the top of a thought.

- **Desktop (≥ 1024 px):** a sticky in-page nav under region A, listing
  the bands that exist (*What it does · How it works · Who it is for ·
  Proof · Prices · Questions*). The entry for the band in view carries
  `aria-current="true"`. It is a `<nav>` of anchor links — real links, so
  they are focusable, middle-clickable and in the URL.
- **Mobile (< 768 px):** no in-page nav, which would eat a third of the
  screen. Instead a **sticky action bar at the bottom** carrying the one
  call to action from §3, appearing after the hero has left the screen.
  Bottom of the viewport because that is where a thumb is (ui-spec §4.2).
- Every band heading gets `scroll-margin-top` clearing the sticky header,
  so an anchor never lands with the heading hidden underneath it.
- The anchor is the band's id (`#pricing`, `#proof`), which makes it
  deep-linkable — ui-spec §4.3's first property, applied to a page whose
  sections people will want to send each other.

### 5.3 Where it must hold

- **375 px and up**, no horizontal scroll, 16px side gutter minimum.
- **Both themes**, from the tokens; nothing hard-codes a hex.
- **axe clean** at 375 px and at desktop, animation included — the same
  bar `accessibility.spec.ts` already holds every route to.
- **A stranger's first paint does not wait on the shell's authenticated
  bootstrap.** The public read (§8) is one request, cacheable, and the page
  renders from it; the call to action resolves afterwards and replaces a
  reserved-size placeholder, so nothing reflows.

## 6. How it is built, so that changing a band is changing one file

The operator asked for this to be easy to modify. What makes it easy is
that a band is **a component plus a row shape plus an editor**, registered
in one place, and that nothing else in the page knows which bands exist.

```text
frontend/src/features/showcase/
  ShowcaseScreen.tsx        reads, orders, renders. Knows no band by name.
  ShowcaseBand.tsx          the shared band shell: full-bleed, surface level,
                            vertical rhythm, id/anchor, reveal-on-scroll
  ShowcaseNav.tsx           §5.2 desktop in-page nav
  ShowcaseAction.tsx        the §3 call to action, and the mobile sticky bar
  blocks/registry.ts        BLOCKS: the one table everything reads
  blocks/HeadlineBand.tsx
  blocks/StepsBand.tsx
  blocks/UseCasesBand.tsx
  blocks/ProofBand.tsx
  blocks/PricingBand.tsx    reads the catalogue, owns no rows
  blocks/QuestionsBand.tsx
```

`blocks/registry.ts` is the whole mechanism:

```ts
export const BLOCKS = {
  HEADLINE: { order: 10, surface: 'canvas', anchor: 'what', view: HeadlineBand, editor: HeadlineEditor },
  STEPS:    { order: 20, surface: 'surface', anchor: 'how', view: StepsBand,    editor: StepsEditor },
  // …
} as const;
```

- `ShowcaseScreen` sorts the rows it was given by `BLOCKS[kind].order` and
  renders `BLOCKS[kind].view` inside a `ShowcaseBand`. It contains no list
  of band names and no `switch`.
- The console screen (§8) renders `BLOCKS[kind].editor` the same way, so a
  new band gets its editor by existing.
- **Adding a band is four edits**: the enum value in a migration, a `view`,
  an `editor`, one line in `BLOCKS`. Nothing else is touched.
- **The band's own fields are its component's business.** `content` is
  JSONB and each band parses its own shape at the boundary; a malformed row
  renders that band as absent rather than taking the page down, because a
  page that fails whole because one caption is wrong is worse than a page
  with five bands.

Two things stay out of this directory on purpose: prices come from
`queries/storefront.ts`, and the call to action's permission and
entitlement checks are `can()` / `isEntitled()` over what `/me` returned —
the same two functions every other screen gates on.

## 7. Where the words live — and why not in the frontend

A component that carried Plan's headline would be one product's fact baked
into a shell shared by all of them, which is `gate:products` in PHP moved
somewhere the backend cannot see it. The words are **data about a product**,
written by whoever sells it.

A `product_showcase` row per product and per band, not a blob:

```text
product_showcase
  product_id      the product
  block           HEADLINE | STEPS | USE_CASE | PROOF | QUESTION
  position        within its block kind, in tens (display_order's habit)
  content         JSONB, the band's own fields, English
  asset_id        the picture, when the band has one         nullable
```

Rows rather than one document because the console edits one band at a time,
and because a missing picture must not take a headline with it.

The words themselves go in `product_showcase_translations`, one row per
band and per language, beside the English on the block itself — the
mechanism `docs/translatable-fields-spec.md` settles, and the same one the
feature descriptions and the offer names already use. The console edits the
current language and offers the others behind the language button at the
end of the field (`ui/TranslatedField`); nothing here invents a second
translation UI.

This is operator data, not an application sentence: it never enters
`frontend/src/i18n/catalogues/` and `gate:i18n` has no opinion about it
(that spec, §1.5).

**The pictures** are assets of the product, not of a project: a new
`product_assets` scope beside the existing store, served by signed link the
way `downloadAsset` already is. Size and type are bounded at upload, and an
animation is a video file or an animated image — never a script, because
this page is read by strangers and the CSP must stay as narrow as it is.

## 8. What the API adds

```text
GET  /api/v1/public/products/{code}/showcase        public, cached, per language
GET  /api/v1/staff/products/{productId}/showcase    staff.products.manage
PUT  /api/v1/staff/products/{productId}/showcase    staff.products.manage
POST /api/v1/staff/products/{productId}/showcase/publish   staff.products.manage
POST /api/v1/staff/products/{productId}/assets      staff.products.manage
```

The public read is the one that matters: a stranger has no session, no
product context and no permissions, so it takes the **code** in the path
and answers from the product alone. It reveals only what somebody decided
to publish — ADR-041's rule holds: what the platform *runs* stays private,
what a product *advertises* is public by choice.

**Publishing is a state on the product**, `showcase_published_at`, nullable.
Null is a draft and the public read answers 404 — not an empty page, which
would be the platform advertising that a product exists and has nothing to
say. Publishing is refused `SHOWCASE_INCOMPLETE` while there is no
`HEADLINE` band with an English headline, which is the whole of the
completeness rule (§11.2).

**Which language the public read answers.** The reader's profile, when
there is one, falls back to English (ADR-050, amended 2026-09-23: `?lang=`
is gone and the profile decides). A stranger has no profile — and that was
left open here until 2026-09-24, when it was settled: **`Accept-Language`**.

It has to be a header rather than a parameter, and for the reason ADR-050
removed `?lang=` in the first place. A query parameter travels in a link,
so a page somebody shared would impose its author's language on whoever
opened it next; a header cannot be shared by accident. It is the reader
saying which language they are reading in, *now* — which is exactly what
the storefront's language picker means when somebody uses it, and the
frontend sends the picker's choice as the header and keys the read by it,
so switching language refetches rather than showing the previous one.

Absent, unknown, or a browser's full `fr-FR,fr;q=0.9,en;q=0.8` answers
English. The page is a shop window: it does not refuse people over a
header.

The fallback is **field by field**, not row by row: a band whose headline
is translated and whose subline is not reads with the French headline and
the English subline. Answering the whole English row over one missing
field would throw away the sentence that *was* translated.

## 9. What must never happen

- No `if (product === '…')` in a component, and no product name in a
  translation key. The page is one component driven by rows.
- No raw HTML from the console rendered into the page. The bands have
  fields; a rich-text field that accepted markup would be an XSS surface
  reachable by anybody with `staff.products.manage` and read by everybody.
- No price typed into a showcase band. Band 5 reads the catalogue.
- No autoplaying sound, and no animation without `prefers-reduced-motion`
  honoured — ui-spec §4.2's rule, which this page does not get to bend.
- No band parked at `opacity: 0` awaiting an observer (§5.1).
- No hex in a component. Every colour is a token, both themes (§5.1).
- No second shell. This is a screen in the one shell, with its six regions.

## 10. Build order

Each step leaves `composer run gates` and `npm run build` green.

1. **The page, from nothing.** `/` stops redirecting a member; the screen
   renders the product's name, the catalogue's plans, and the right call to
   action from the table in §3. No showcase rows yet — a product that has
   said nothing looks deliberate rather than broken.
2. **The look.** The display tokens, `ShowcaseBand`, the reveal, the
   in-page nav and the mobile sticky action bar (§5), against the bands
   that exist. Done before the rows so that the design is judged on the
   page and not on a form.
3. **The rows.** Migration, the staff routes, the publish state, the public
   read, and the console screen that edits bands through the registry.
4. **The pictures.** `product_assets`, the upload, the address the bytes
   answer at, and the bands that carry one.

   This step said *"the signed link"*, the way `downloadAsset` serves a
   tenant's files, and that could not be built: a signed link needs an
   authenticated caller to mint it, and the reader of a shop window has no
   session at all. So a showcase picture is **public while its page is
   published** — the join to `showcase_published_at` *is* the
   authorisation, which is why an unpublished page's picture has no address
   that answers rather than an address that refuses. It is the same
   reasoning as the read itself (§8): the page is public, so what it shows
   is public with it, and nothing else in `product_assets` is reachable.

   The server composes the address and puts it on the band as `image`. No
   client builds one out of an id — a URL assembled in two places is two
   contracts, and the second one drifts.
5. **The demonstration says something, in five languages.** Plan's showcase
   written for real — headline, three moves, two use cases, two
   screenshots, four questions — in English, French, Spanish, German and
   Italian, seeded by `DemoFixtures` and verified by it, the same way the
   catalogue's translations are since 2026-09-24. The page is then judged
   on content rather than on lorem, and a French reader sees a French shop
   window.

**Exit criteria**

- A stranger opening the bare host sees the first product's story, and
  switching product rewrites it without a reload.
- A member subscribed to Plan sees *Open Plan* and lands in Plan.
- Every sentence on the page comes from a row; deleting the rows leaves a
  page that still makes sense.
- The page passes axe at 375 px and at desktop width, animation included.
- The page renders complete with JavaScript motion disabled, and a
  screenshot of it at rest shows every band.
- Switching the profile's language rewrites the story, not only the
  buttons — and a stranger, who has no profile, gets the same by sending
  `Accept-Language` (§8).
- Adding a seventh band touches four files and no existing band (§6).
- `gate:ui` accounts for the five new operations.

## 11. Decided by the operator (2026-09-24)

The four questions this specification opened, answered.

### 11.1 The platform's own staff write the story

`staff.products.manage`, on the console, beside the product's other
settings. A tenant never publishes a product page: the story is the
platform's account of what it sells, and a page written by one customer
would be read by every other customer of the same product.

If that ever changes it is a different permission and a different ADR, and
the sentence above is what the ADR will have to argue against.

### 11.2 One language is enough to publish: English

English is the key and the fallback everywhere else on this platform
(ADR-050), and it is the fallback here. A page may be published with the
English filled and nothing else; a reader whose language is unwritten reads
English, exactly as they do for a mail, a feature name and an offer name.

The alternative — waiting for five — was considered and refused: it would
leave the shop window dark for a product that has something to say in one
language, which is worse than saying it in one.

Two consequences, stated so nobody is surprised by them:

- **the mixture is visible.** A page half translated shows French where
  French exists and English where it does not, in the same band. That is
  the honest rendering and it is what the language dots on the console's
  field are for (`ui/TranslatedField`) — the gap is visible to the person
  who can close it;
- **the demonstration does not rely on the fallback.** It is written in all
  five (§10.5), because a demo that fell back would demonstrate the
  fallback rather than the feature.

### 11.3 A retired product keeps everything

Retiring a product stops it being sold. It does not erase what it was: the
showcase rows, the translations and the pictures stay, and the public read
keeps answering for a retired product.

The consequences, each of which is a thing to build rather than a thing to
hope for:

- **Band 5 has nothing to show**, because nothing is on sale. It renders
  *No longer sold* — never an empty band, and never a Buy button that would
  lead to a refusal. (Naming a *successor* product there would be a nice
  thing to have and is not built: nothing on `products` records that one
  replaced another, and inventing the column in passing is how a
  specification grows a feature nobody asked for.)
- **A retired product does not reappear in the public picker.** That list
  is already "active products with a publicly listed offer sellable now"
  (`listPublicProducts`), and a retired product matches neither half. The
  page stays reachable at its address; it stops being advertised. Keeping
  everything means keeping the record, not re-opening the shop.
- **Nothing is deleted on retirement, so nothing has to be recovered on
  reinstatement.** Un-retiring a product puts its story back exactly as it
  was — which is the same reasoning as a retired *feature* (ADR-052) and as
  an invoice the law requires be kept (§26).

### 11.4 The design intent is the one in §5

*Wow* is spent on size, space, restraint, real screenshots and one honest
motion — never on decorative abstraction, stock photography, device
mockups, parallax or scroll-jacking. Desktop and mobile both, from 375 px
up, both themes, axe clean, and complete at rest.
