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
`--ds-accent` is **a tenant's to override** (the palette it chooses on `/palette`). A hero
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

**Four bands may carry one** (2026-09-28): the headline, a step, a use case
and a proof. A step shows its picture *after* its number and title, because
those are what make it a sequence; a use case shows it last in the card,
because the reader recognises themselves in the before and the after and
looks at the picture once they have. The questions band carries none — a
picture per question makes a frequently-asked list unreadable, and there is
no layout for one that does not push the answers apart.

**A picture is described where it means something.** `alt` is a band field
like any sentence, so it is translated by the same mechanism rather than a
second one — and it was accepted by the server and offered by no screen
until 2026-09-28, so every picture ever published went out decorative, the
hero included. Empty stays meaningful and the console's hint says so: a
proof band's caption sits beside its picture already, and an `alt` repeating
it makes a screen reader say the same sentence twice.

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

**A band carries its own heading** (`product_showcase_bands`, 2026-09-28):
its eyebrow, its title, the sentence under it, in five languages like every
other sentence on the page. One row per `(product, band)`, where
`product_showcase` is one row per *row* — three steps are three rows there
and one here, because the section is called "How it works" once.

**`PRICING` is what settles that design rather than an afterthought to it.**
It has no rows and never will, because a row for it would be a row somebody
could type a price into (§9), and it still needs a title. Nothing that lives
on a row can carry one. So the heading is its own table, and the prices band
becomes editable without ever gaining a field that could hold an amount.

Every field is optional and **absent means the band's compiled default** —
the words its component was written with — so this arrives with no backfill
and a band goes back to its default by having its title emptied. `bands`
absent on a write leaves every heading alone, the same rule and the same
reason as the order: the translation desk writes one sentence through the
operation that owns the story and carries neither.

**Two more bands** (2026-09-28). `PROBLEM` names what the reader lives with
in a few short points, on the one dark ground the page has; `QUOTE` carries
somebody saying it worked. Neither fitted what was there — a problem is not
a numbered sequence and not a before-and-after about one person — and
forcing either would have given the page a numbered list of complaints or an
empty "after" column.

`PROBLEM.icon` names one of a closed set the frontend draws. It is a **code
and not a sentence**: validated against that set, refused in a translation,
and drawn from a table beside the band rather than fetched from an icon
library. An icon has no French.

**And the order of the sections is the product's** (`products.showcase_sections`,
2026-09-28). A JSON array of section kinds, nullable, and null is the default
order — so no backfill, and a product reads exactly as it did yesterday until
somebody drags a row.

It is a **different fact from `position`**, and the two are easy to confuse:
`position` orders the rows *within* one band (three steps, two use cases) and
is unique per `(product, block, position)`; this orders the **bands
themselves**, and there is one of it per product. Until this, the band order
was `BAND_META[kind].order` — a constant compiled into the frontend bundle —
so an operator who wanted the prices above the questions could do nothing
about it without a rebuild and a redeploy, which is the position
`VITE_DEFAULT_PRODUCT` put a deployment in before a tenant could choose its
own product.

**`PRICING` is in this list and in no other.** It is a section that reads the
catalogue and has no row anybody writes — a row for it would be a row
somebody could type a price into (§9) — so its place in the order is the one
thing about it an operator decides. A console showing five movable rows and
one fixed would be a console nobody could explain.

**`sections` absent on a write means "leave the order alone"**, never "put it
back to the default". That distinction is load-bearing rather than a
convenience: the translation desk writes one sentence through the same
operation by re-reading the story and sending the blocks back, and it carries
no order — so a request without one that reset the order would make
translating a headline reorder the page.

**Sent, it must be a permutation**: every section exactly once. A subset would
be a section nobody could put back from the screen that sent it, and hiding a
band is removing its rows — which the editor already does. Two ways of saying
the same thing eventually disagree.

**Read tolerantly, on both sides.** A stored order missing a section gets it
back **beside the section it follows** in the compiled order, a section named
twice is read once, and one the code no longer knows is dropped.

This paragraph said « appended in its compiled place » and both
implementations appended to the end, which nobody noticed until `DEMO` arrived
and landed after the questions on every product that already had an order —
below the prices, which is the one place a band called *Try it* must not be.
The rule cannot guess an opinion nobody expressed: an operator who reordered
their page said nothing about a band that did not exist, so the compiled
neighbourhood is the only honest answer, and moving it afterwards is what says
otherwise. That is not defensiveness for its own
sake: the day a sixth band is added, every order stored before it exists is
short, and the alternative is a band invisible on every product until somebody
re-saves each one by hand.

Rows rather than one document because the console edits one band at a time,
and because a missing picture must not take a headline with it.

The words themselves go in `product_showcase_translations`, one row per
band and per language, beside the English on the block itself — the
mechanism `docs/translatable-fields-spec.md` settles, and the same one the
feature descriptions and the offer names already use. The console edits the
current language and offers the others behind the language button at the
end of the field (`ui/TranslatedField`); nothing here invents a second
translation UI.

**Every sentence of a story is also on the translation desk** (2026-09-26,
translatable-fields-spec §5.1): one language, every sentence across features,
offers and showcases, and one tally saying what a language is still missing.
That is a second *screen* and not a second translation mechanism — it saves
through `PUT /staff/products/{id}/showcase`, this section's own write, because
a second way to write these rows is the drift the gates exist to prevent. What
that write costs a caller is written there: it replaces the story whole, so the
desk re-reads the story from `GET /staff/products/{id}/showcase` at the moment
it saves and sends all of it back, and a band that has gone in the meantime is
refused rather than re-created.

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

1. **The page, from nothing.** `/` stops redirecting a member — *reversed the
   same day; see the note under the exit criteria* — the screen
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

- A stranger opening the bare host sees the story of the product this
  deployment leads with — `DEFAULT_PRODUCT`, not whichever sorts first — and
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

**Signing in does not land here** (reversed 2026-09-24, the same day step 1
was built). Step 1 removed the landing redirect so that `/` would be an
address somebody could reach at all: before it, a member was ejected from the
page before the shell had rendered, and the story existed for strangers only.

That was right about the page and wrong about the landing, and the operator
found out by signing in: they got a shop window for a product they had
already bought, with their projects two clicks away, and a platform
administrator got a tenant's front page instead of the console they had
signed in to run.

**Being reachable and being where signing in puts you are different
questions**, and the first does not need the second — but it does need a
door, and this page had none: it is in no menu.

So the landing goes back to meaning *take me to my work* — the first entry of
the person's own menu — and two things keep this page reachable:

- the redirect fires **once per browsing session** (`sessionStorage`, cleared
  on sign-out), so any later arrival at `/` stays here, from a link or from
  the address bar;
- region A carries an **About** link to it, in the drawer on a phone
  (ui-spec §4.1).

This page keeps its address and every word on it. Nothing above changes
except who is *left* here.

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

## 12. Writing it with the page beside the form (2026-11-20)

The console had a form and no page: an operator wrote, saved, published and
opened the product's address in another tab to find out what it looked like,
and a draft could not be looked at at all. Two additions, both in the
frontend and neither touching the contract.

### 12.1 The preview

*Edit with the preview* opens the form and the page side by side, filling
the window (`StoryScreen`, `StoryPreview`).

- **It is the real page.** The draft is turned into the shape the public
  read sends (`draftAsPage`) and goes through `contentFrom()` and
  `<Showcase>` — the parser and the component a stranger's browser runs. A
  preview with its own renderer would be a second opinion about what a band
  looks like.
- **In a frame, so a phone is a phone.** Every band is laid out by the width
  of the viewport, so the page is portalled into an iframe 1280 or 390
  pixels wide and scaled to fit. Its breakpoints read the frame and not the
  window; `e2e/story-studio.spec.ts` proves that in a browser.
- **In any of the five languages**, falling back to English field by field
  as `ShowcaseBlock::contentIn()` does.
- **The prices are the product's public offers**, read as a stranger reads
  them. Nothing in the preview is saved and nothing in it can buy.
- A picture is shown from the operator's own file the moment it is chosen;
  the platform's address for it answers nothing until the page is published.

### 12.2 Templates

*Start from a template* fills the draft with an order and with sentences
that say what goes where (`blocks/templates.ts`): *The whole story*, *Proof
first*, *Short*.

- **Not stored, and not a fact about any product.** Applying one produces an
  ordinary draft, saved by the ordinary write; adding a template is adding
  an entry to one file.
- **English only**, because the English is what a page is published from
  (§11.2) and a translated placeholder would be published as though chosen.
- **Replacing what is written takes a second press.**
- No template carries a `DEMO` row: that band is refused without an address
  or a picture, and a template whose first save fails teaches nothing. Each
  template is tested against what the endpoint requires.

## 13. Four bands that sell, and a choice of layouts (2026-11-20)

### 13.1 The bands

The page could say what a product does and prove it with screenshots, and
had nowhere to put what a hesitating reader looks for next. Four kinds, each
added the way §6 promises — a migration (`Version20261120090000`), the
validator's field table, a view, an editor row, one line in the registry.

| Kind | Where | Rows | Fields |
| --- | --- | --- | --- |
| `TRUST` | under the headline | many | `name`; an optional logo, read aloud by that name |
| `STATS` | after the problem | many | `value`, `label`, `detail` |
| `FEATURE` | after the steps | many | `title`, `body`, `icon` |
| `CTA` | last | **one** | `title`, `body` |

- **A figure is text.** `3 min`, `1 200`, `−40 %` are stored and drawn as
  written, unit included. The page never computes with one and never
  animates up to one: a count that ticked from zero would be the page
  inventing the claims in between.
- **`TRUST` carries no `alt`.** The name is what a screen reader says for
  the logo; a second description would be a second sentence that could
  disagree. Without a logo the name is set as text, so the band works for a
  product with customers and no permission to use their marks.
- **`CTA` shows the reader's own call to action** (§3), the one the hero
  carries. It owns no button: a second answer to "what should this reader
  do" is the one that sends a subscriber to the prices. One per page, by a
  partial unique index, like the headline.
- **`TRUST` and `CTA` are not in the in-page nav** (`quiet`). Nobody
  navigates to a strip of logos.
- The icons gained four that say something good (`check`, `bolt`, `shield`,
  `layers`); the first eight were drawn for the problem band.

An order stored before these existed is completed as §7 already describes:
each goes back beside the band it follows.

### 13.2 Layouts

Five bands may be laid out more than one way. The first of each list is the
band as it has always been drawn, and is what an absent value means — no
page moved the day this was added.

| Band | Layouts |
| --- | --- |
| `HEADLINE` | `split` · `centered` · `stacked` |
| `STEPS` | `columns` · `timeline` |
| `FEATURE` | `grid` · `list` |
| `QUOTE` | `grid` · `single` |
| `PROOF` | `alternate` · `grid` · `carousel` |

- **A closed set and a code**, like a problem's icon: English only, refused
  in a translation, and refused on a band that is drawn one way. A free
  field would be an operator choosing between layouts nobody built.
- **Stored beside the band's heading** (`product_showcase_bands.content`,
  key `layout`), because that row is the one fact there is per band rather
  than per row of a band. It needs no column: a layout alone, with no title
  beside it, is still a heading that said something.
- **Never a colour.** A band's surface stays the registry's (§5): a page
  where every band picks its own ground is the page the registry prevents.
- A layout a bundle does not know is drawn as the usual one, never as a
  blank band.

### 13.3 What holds the two halves together

`composer run gate:showcase` compared the fields the console offers with the
fields the endpoint accepts. It now also compares the three closed sets both
sides spell — the kinds, the icons, and the layouts each band has, in order —
and fails when one is changed in TypeScript or in PHP only.

### 13.4 The carousel (2026-11-21)

`PROOF` gained a third layout: the screenshots large and one at a time
(`ProofCarousel`), for a product with five things to show on one screen.

- **A scrolling list, and buttons that scroll it.** The row scrolls sideways
  and snaps; no slide is hidden and none is mounted late. A finger, a
  trackpad, the keyboard and a screen reader reach every picture with what
  they already do, and a browser with scripts off still has a carousel. No
  library: the part that is hard, the browser does.
- **It never moves by itself.** No autoplay and no timer (§4). Under
  `prefers-reduced-motion` the buttons jump instead of gliding.
- **Where the reader is, in words**: "2 of 5", in a live region. The buttons
  stop at both ends, and one picture gets no buttons.
- From `md` up a slide is not quite the full width, so the edge of the next
  picture shows — the hint that there is one.

A template may now name layouts (`StoryTemplate.layouts`): *Proof first*
opens centred and shows its screenshots in the carousel. A band the template
does not name keeps the layout it had.

## 14. Two bands that show rather than say (2026-11-21)

Added the way §6 promises: migration `Version20261121090000`, the
validator's field table, a view, an editor row, one line in the registry.

### 14.1 `TOUR` — the product, one screenshot at a time

What the product includes, each with its own screenshot: a list of things to
see and, beside it, the picture of the one chosen. Rows: `title`, `body`, a
picture and its `alt`.

- **Tabs, in the sense assistive technology means**: a `tablist`, arrow keys
  that move and wrap, one tab in the tab order, each naming the panel it
  controls.
- **Every panel is in the document**; the ones not chosen are `hidden`, not
  unmounted, so all the text is there for a search engine and find-in-page.
- **Nothing rotates.** The reader chooses.
- Below `lg` the list sits above the panel: the same tabs, one column.

### 14.2 `COMPARE` — one plot, before and after

Two rows, read in their order: the first is before, the second after. Each
is — exactly as a `DEMO` row — **an address to embed or a picture, never
both**, with a `label` (required), a `caption` and a `ratio`.

| The two sides are | The reader gets |
| --- | --- |
| two pictures | one over the other, and a handle to drag |
| an embedded page, on either side | one at a time, and a switch |

- **The handle is a range input**, stretched over the pictures and
  invisible: a mouse and a finger drag it, the arrow keys move it, and it is
  announced as a slider with a value. What is drawn only follows it, and
  shows the focus the input holds.
- **Two live 3D views are not overlaid.** Their cameras are each their own,
  so a handle across them would compare two different angles. They are shown
  one at a time; a side is loaded the first time it is chosen and then kept,
  so switching back finds the angle the reader left.
- **At most two** (`ShowcaseBlock::AT_MOST`): a comparison of three is a
  gallery, which `PROOF` is. Said by the validator in words; nothing in the
  database counts it, because the story is replaced whole on every write.
- **One side is not a comparison** and draws nothing — no band, and no entry
  in the page's menu.
- The embedded address is held to what a demo's is (§ `DEMO`): `https`, no
  credentials, refused in a translation, and its origin named to
  `bin/build-dist.sh --embed`, or the browser refuses the frame.

For Plan, the two sides are two projects on one plot — `…&file=<before>` and
`…&file=<after>` — or two screenshots of them.

## 15. The page editor (2026-11-21)

The console's form with a preview beside it became an editor arranged around
the page. Same draft, same single write (`PUT …/showcase`), same registry of
bands; no change to the contract or the database.

```text
left     the bands a reader meets, in order — drag, or choose one
middle   the page itself, in a frame at 1280 / 820 / 390 px, in any language
right    the chosen band: layout, rows, pictures, heading — or the page as a whole
```

- **A click on the page chooses a band; a double click rewrites a sentence
  where it is read**, in the language being read. The sentence is found by
  what it says among that band's own fields, so the band components carry no
  mark for the editor and a new band is editable by existing. What the draft
  does not hold (a price, a default title) is not editable there.
- **Only the bands on the page are listed.** The stored order still names all
  fifteen; a move is relative to the next band a reader would see.
- **A band is added from a library** — its shape and one line about it — and
  lands after the band being looked at. A band already on the page grows from
  its own panel.
- **Undo and redo** cover every change made in the editor, for as long as it
  is open. Typing is one step per pause.
- **Saving is reviewed first**: what changes band by band (compared as the
  contract would carry it), the required English still missing — which holds
  the save — and how much each language still reads in English. On a published
  page the review says what it is: saving changes what visitors read at once.

Not built, because each needs the server to keep something it does not keep:
a draft separate from the published page, a history of versions to restore,
hiding a band without removing it, and cropping a picture.

## 16. The page's menu is a choice, and a comparison shown side by side (2026-11-22)

**The menu under the headline can be hidden.** The bar naming the page's bands
was drawn on every page with three bands or more, with no way to say otherwise.
It is now a code — `menu`: `bar` (the usual one, and what absent means) or
`hidden` — carried by the **headline's** heading beside its layout, because the
bar is the headline's and a page has one of each. Set in the editor's page
panel. Refused on any other section and in a translation, like a layout.

**A comparison has two layouts**: `overlay` (the usual one — a slider over two
pictures, a switch between two embedded views) and `beside`, both sides next to
each other, stacked on a narrow screen.

**Two embedded views are started together.** A side used to load the first
time it was chosen, so two views that animate by themselves were never at the
same moment. Both now load at once and the one not shown stays drawn
(transparent, `inert`), since a frame the browser does not draw stops
animating.

That is as far as the page can go. Measured on Plan's two demonstration
projects: the angles agree, the hour of day does not — each scene starts its
clock when it has finished loading, and one loads later than the other. The
page cannot reach into a frame from another origin. Exact agreement, and
following a view the reader turns by hand, need the embedded product:

```text
animate from the wall clock     hour and rotation derived from Date.now(), not
                                from the moment the scene became ready
tell the page where it looks    window.parent.postMessage({ type: 'plan:view', … })
                                on every camera change, and apply the same
                                message when it receives one
```

Neither exists in Plan today, so nothing here listens for the second: a relay
written against a message nobody sends would be code with no caller.

## 17. A product's demonstrations, offered by name (2026-11-22)

A demonstration band and each side of a comparison take the address of a live
view. It was typed: a long query string with a number in it. The editor now
offers the product's own demonstrations by name, and choosing one fills the
address field — which stays an ordinary field, since a band may embed anything
served over https.

**The platform probes the product; the product is not changed.** A product is
told two paths on the console's Products screen, both relative to its `app_url`
and both carrying `{n}`:

```text
source   admin/vitrine/{n}                            answers where number n exists
embed    ?mode=demo&file={n}&x={width}&y={height}     what a page embeds for it
```

`GET /staff/configuration/demonstrations` asks for numbers 1 to 20 at once and
keeps those that answer with a JSON document, named by the document's first
`name`. Only the opening of each is read: a demonstration is a whole project
(180 KB to 1.9 MB on Plan) and the list wants one line of it.

- **Configuration, never a constant**: the two paths are one product's own URL
  shape, and the backend is every product's.
- **Relative, and refused otherwise**: the read leaves the host, so a scheme, a
  host, a leading slash, a parent segment or a backslash is a `400`. It can
  only ask the product the platform already embeds.
- **Numbers only, and twenty of them.** A demonstration saved under a name, or
  a twenty-first, is not found. A product that outgrows this should publish its
  list — in its manifest, beside its schema versions — rather than be probed.

The demonstration world tells Plan both paths, and an upgrade adds them to a
deployment seeded before this existed.

## 18. Less in front of the operator (2026-11-22)

The operator's own verdict on the editor: too complicated, and a before/after
could not be set up at all. Both were true, and for reasons in the panel
rather than in the bands.

- **A comparison arrives as two sides, already named.** It is drawn from two
  rows, and adding one added a single nameless side — a band the page did not
  show, with nothing saying a second row was missing. It now starts as *Before*
  and *After*, in the five languages, both forms open, each asking only what it
  shows.
- **What a band shows comes first.** A row's form listed every field in the
  order the server stores them. The demonstration list and the picture now
  lead, then the sentences the band cannot do without.
- **The rest is under *More options*** — a picture's description, a frame's
  shape, a typed address where the product lists its demonstrations, a side's
  caption. One click away, and none removed.
- **A band that cannot be drawn yet says what it is waiting for.**
- **The band's title is closed until asked for**: three fields in front of
  every band, for words most bands never change.

## 19. A panel that can be read (2026-11-23)

The operator again: the right-hand panel was not readable — too much in it.
What was in it, for a band of two steps: a layout, two row cards with three
icons each, under every text field a language button and a line of five
language codes, a hint under every label, the band's title, and a section of
its own to remove the band.

- **Two tabs: Content and Look.** What a band says opens first; its layout and
  title, changed once, are the second tab.
- **One language at a time, chosen once.** The header's language is the one the
  page is shown in *and* the one the fields edit. A field is a label and a box;
  in another language the English stands in the empty box. The per-field
  language button and the row of codes are gone from the editor (the console's
  form keeps them).
- **Hints appear when a field is used**, not under every label.
- **One row open at a time**, the first by default; a band with one row has no
  list at all. Row actions show on the open row, or on hover.
- **Removing the band is an icon beside moving it**, with undo behind it.
- **Templates are folded** on the page panel: a template is chosen once.

## 20. Eight more things a page can show (2026-11-23)

Four bands and four layouts, asked for by the operator in one go. The ninth on
the same list, a contact form, is the only one that stores what a stranger
types, and is written separately.

| | What it is | Where it is decided |
|---|---|---|
| `ANNOUNCE` | A line of news above the whole page, a link (`https` or `#place`), and a last day (`until`). Drawn above everything whatever the order says. | Past `until`, **the public read leaves the row out** — the server's day, not each browser's. |
| `VIDEO` | A film, YouTube or Vimeo, behind a poster; the player is put in only on a click, YouTube from its no-cookie origin. | The server refuses any other host (`ShowcaseBlock::VIDEO_HOSTS`); the bundle always names the two players in `frame-src`. |
| `VERSUS` | A table: criteria down, this product and up to three other ways across, a `yes` / `partly` / `no` mark where they meet. | The other columns are named once, on the band's heading (`columns`, `;` between names, translated like a title). |
| `HOTSPOT` | One picture — the first point's — and numbered points on it; choosing one says what it is. | A point is `x` and `y`, shares of the picture 0–100, set in the editor by clicking the picture. |
| `STATS` `counter` | The figures count up from zero when they come into view, written the operator's way (`1 200`, `2,5 %`). | Presentation only; still for a reader who asked for less motion. |
| `TRUST` `marquee` | The logos pass by, stopping on hover. | The second copy is hidden from screen readers. |
| `PROOF` `gallery` | A mosaic of screenshots, each opened large; arrows move through them, Escape closes. | — |
| `PRICING` `table` | Under the cards, what each offer grants, feature by feature. | **Read from the catalogue**, like the prices: nothing is typed into it and nothing is added up. |

A migration widens the three constraints that list band kinds, as each new band
has.

## 21. A contact form (2026-11-23)

The ninth of the operator's list, and the only one that keeps what a stranger
types. A `CONTACT` band (one per page) draws name, email, message and a box to
tick beside the operator's own sentence; what is sent reaches support.

- **Only where the published page offers one.** `POST /public/products/{code}/contact`
  answers `404 CONTACT_NOT_OFFERED` unless the *published* page carries the
  band, so the public route is not a mailbox for every product code.
- **The consent is kept as it was read** — the band's sentence, in the language
  the page was read in, stored with each request, as an invoice keeps its
  snapshot.
- **Nothing is kept that was not typed**: no address, no browser, no referrer.
- **Robots**: a hidden `website` field a person never fills; filled, the message
  is thrown away and the answer is the same `202`. Five an hour per address and
  product, on top of the public limit.
- **Support reads and answers** at `/console/contact-requests`
  (`support.read`; marking one answered is `support.respond`). The answer goes
  from the reader's own mail: the platform sends nothing on support's behalf,
  and there is no notification of a new message yet — the menu entry is where
  they are.
- **Not sent from the editor**: the page drawn for its author has no product to
  write to, and says so where the button would be.
