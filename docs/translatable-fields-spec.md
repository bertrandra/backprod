# A field the operator writes in five languages, and a code they never type

**Status:** specification, 2026-09-24. Steps 1–3 of §8 are built: the
mechanism on a feature's name and description, offer names, and the one
platform-wide list of features (ADR-052). Steps 4–6 — the picker, a
product declaring its own capabilities, and the demonstration translated —
are not.
**Decides:** where a translated catalogue value lives, how one field is
edited in five languages without a second interface, and where the list of
feature codes comes from.
**Relates to:** ADR-050 (the language is presentation, English is the key),
`docs/home-showcase-spec.md` (the same mechanism, for the shop window),
ADR-051 §4.3 (a product's own capabilities), §13 (offers and entitlements).

## 1. Two problems that look like one

**A feature code is read by another program.** Plan gates its terrace
engine on `plan.terrasse`; the platform grants that code through an offer
version. Nothing checks that the two agree: the console's feature form is a
free-text box, and `plan.terrase` typed once silently sells a capability
that no product will ever honour. The gate exists (`gate:permissions`
checks the *frontend's* vocabulary) and stops at the platform's own edge.

**A feature name is read by a person, in their language.** `Plan documents`,
`DXF export`, `Pro monthly` are on a customer's screen and on their invoice,
and today they exist in exactly one language: whichever the operator typed.
The catalogues under `frontend/src/i18n/` cannot help — they carry what the
*application* says, shipped with the bundle, not what an operator wrote
about their own offer last week.

They are one job because they are the same form. The code must come from a
list; the name must come in five languages; and neither may turn the
catalogue screen into a translation workbench.

## 1.5 Two translation systems, and they must not be confused

**The application's own sentences stay in JSON, exactly where they are.**
Field labels, buttons, hints, empty states, error wording — everything a
developer wrote — live in `frontend/src/i18n/catalogues/{fr,es,de,it}.json`,
keyed by the English, shipped with the bundle, checked by `gate:i18n`
(ADR-050). Nothing below touches them, and nothing below is a reason to
move one of them into a database.

**What this specification is about is the other kind: words an operator
typed about their own business.** A feature's name, its description, an
offer's name, and later a showcase block. They are not in the bundle
because they are not in the source: they appear when somebody creates an
offer on a Wednesday afternoon, on one deployment and not another.

```text
"Save", "At least 12 characters."        the application's        JSON catalogues
"Terrace engine", "Pro monthly"          the operator's           the database
```

The test that separates them: if the sentence would be identical on every
deployment of this platform, it is the application's. If it changes when
somebody sells something different, it is the operator's.

## 2. Where a translated value lives

**Decided by the operator (2026-09-24): in a table of its own, not in a
column beside the value.**

```text
feature_translations
  feature_id   uuid     → features(id) ON DELETE CASCADE
  locale       text     one of the five (ADR-050), never 'en'
  name         text
  description  text
  PRIMARY KEY (feature_id, locale)

offer_translations
  offer_id     uuid     → offers(id) ON DELETE CASCADE
  locale       text
  name         text
  PRIMARY KEY (offer_id, locale)
```

**One table per translated thing, not one table for everything.** A single
`translations(entity_type, entity_id, field, locale, value)` is the shape
everybody draws first, and it is the one shape PostgreSQL cannot keep
honest: an `entity_id` that points at two tables can carry no foreign key,
so nothing stops a row surviving the feature it describes, and nothing
catches an `entity_type` misspelt in a migration written at speed. Three
narrow tables cost three migrations and give `ON DELETE CASCADE`, which
means a deleted offer takes its translations with it without anybody
remembering to.

**English stays on the row it belongs to**, in `features.name` as today,
and stays required. It is the key — exactly as ADR-050 decided for the
application's own sentences — so a missing French falls back to something a
person can read rather than to a code, the platform's own logs and invoices
stay in one language, and a `LEFT JOIN` that finds nothing is not an error
to handle but the fallback itself.

**Reading them.** One `LEFT JOIN ... AND t.locale = :locale` per translated
table, in the same query that already reads the catalogue — not a second
round trip per row, which is how this shape turns into forty queries for a
list of twelve offers. The staff read takes no locale and returns every
row, because the console edits them all.

**Resolution is the reader's, at the edge.** The API answers a *resolved*
string to a tenant or a stranger — `name` in the language the caller reads,
falling back to English — and the *whole set* to the console, which is the
only place that edits it. Two shapes, one row, and the rule written once in
PHP the way `MailWording` already resolves a mail.

## 3. The field, and the button at its end

One control, not a workbench:

```text
┌───────────────────────────────────────────────┬──────┐
│ Terrace engine                                │ EN ▾ │
└───────────────────────────────────────────────┴──────┘
   Plan documents · quota in documents            ● ● ○ ○ ○
```

- The button at the end names the language being edited and opens the
  other four. It is the password field's eye, in the same place, for the
  same reason: the thing you need is where your hand already is.
- The dots say which languages are filled, without opening anything. A
  translation somebody forgot is visible from the list, which is what makes
  this honest rather than decorative.
- English cannot be emptied; the others can, and an empty one falls back.
- Switching language switches the **field**, never the screen: nothing else
  moves, and a form half-typed in French is not lost by looking at Spanish.

**The mails keep their four tabs.** They are a different job: a mail is a
subject *and* a body *and* a link, edited together and previewed together,
and one tab per language is the shape that fits. Nothing here changes
`MailScreen`.

## 4. The features are one list, at platform level

**Decided by the operator (2026-09-24).** A feature is not a thing a
product owns; it is a word the platform and a product's code have agreed
on. So there is **one list**, kept by the platform, and a product's
catalogue picks from it.

```text
features                     today                  after
  product_id    →  each product has its own    (gone)
  code             unique per product          unique, full stop
  kind, unit       per product                 on the one row
```

What this settles, and what it costs:

- **`max_projects` stops existing five times.** The four the platform
  itself enforces — `max_projects`, `exports`, `users`, `white_label` —
  are one row each, and the code that reads them
  (`ProjectWorkspace::QUOTA`, `SubscriptionPeople::USERS_FEATURE`) stops
  depending on every product's catalogue having been seeded with the same
  spelling.
- **A grant still belongs to a product**, because it lives on an offer
  version and an offer belongs to a product. Nothing about entitlement
  resolution moves: what a tenant holds is still decided per (tenant,
  product), and a feature nobody granted them is still absent.
- **The migration is the real work.** Today five products carry five rows
  called `max_projects`; they must be merged into one and every
  `offer_version_features` row repointed, in one transaction, with a
  refusal if two rows sharing a code disagree about `kind` or `unit` —
  because merging a `QUOTA` into a `BOOLEAN` would reinterpret a price
  somebody is paying (ADR-033's rule, applied to the thing being priced).
- **Creating a feature is a platform act**, `staff.features.manage`, on a
  screen of its own — Console → Features — beside Products. Adding one to a
  product's catalogue is then picking from that list, and typing a code
  that is not in it is refused rather than created: `FEATURE_CODE_UNKNOWN`,
  with what may be picked in the details.
- **Retiring a feature is not deleting it.** One that any offer version
  grants cannot be removed — the same refusal as a product with a live
  subscription — so the list gains an `active` flag rather than a delete.

**Where a product's own codes come from.** `plan.terrasse` and its six
siblings are Plan's words, and Plan's code gates on them; the platform only
carries them. They are created in the same list, by the same act, and the
product remains the authority on what they *mean*. When a product grows a
server it may declare them itself — `PUT /api/v1/product/capabilities`
under its product key (ADR-051 §4) — and what arrives is checked against
this list rather than written straight into it: a program may tell the
platform what it gates on, and may not invent a priced capability on its
own.

## 5. What the API changes

```text
GET   /api/v1/products/{productId}/catalog     unchanged shape, resolved names
GET   /api/v1/staff/features                   the platform's one list     ✓
POST  /api/v1/staff/features                   create one   features.manage ✓
PATCH /api/v1/staff/features/{featureId}       name, description, active,
                                               and their translations      ✓
GET   /api/v1/staff/products/{id}/catalogue    every translation, for editing
POST  /api/v1/staff/products/{id}/features     pick a code from the list
PATCH /api/v1/staff/offers/{offerId}           the name and its translations
PUT   /api/v1/product/capabilities             a product declares what it gates
                                               on, checked against the list
```

A translation is written with the thing it translates — `PATCH` takes the
English and the four others together — rather than through a route of its
own per language. One call, one transaction, and no state where a name has
been renamed but its Spanish still says the old thing.

An offer version stays frozen (ADR-033): its **price and terms** are
snapshotted and immutable. A translation of its *name* is not a term — it
is the same offer said in another language — so it may be corrected after
publication. That distinction is worth writing down because the next person
will reach for "offers are immutable" and stop there.

## 6. The demonstration speaks five languages

The demo world writes its catalogue in English today. It gains the four
translations for every feature name, every feature description and every
offer name — Plan's seven capabilities included — so that switching the
interface to French shows a French catalogue rather than an English one
with French buttons around it. That is also the only way anybody notices
this works.

## 7. What must never happen

- No operator data in the frontend's catalogues, and no application
  sentence in the database (§1.5). Each is unusable in the other's place: a
  feature name shipped in the bundle would be one deployment's business in
  everybody's build, and a button label in a table would need a migration
  to fix a typo.
- No code typed twice. The picker is the only way in, and the platform's
  four are not special-cased in a component.
- No resolution in React. The API answers the reader's language, as it does
  for mails; a component choosing among five strings would be the money
  rule's mistake in another currency.
- No language forced on a customer by an address (ADR-050, amended
  2026-09-23): the resolution reads the person's own language.

## 8. Build order

1. **The mechanism, on one field.** `feature_translations`, the resolving
   read, the staff read that returns every language, and the button at the
   end of the field.
2. **The rest of the catalogue.** Feature descriptions and offer names,
   through `offer_translations`.
3. **One list of features.** The migration that merges the duplicates and
   repoints the grants, `staff.features.manage`, and Console → Features.
   This is the step that can go wrong quietly, so it is its own.
4. **The picker.** A product's catalogue chooses from the list, and a code
   outside it is refused.
5. **The product's own voice.** `PUT /product/capabilities` under a product
   key, checked against the list rather than writing into it.
6. **The demonstration**, translated.

**Exit criteria**

- A feature created in the console with a French name shows that name to a
  French reader and its English to everybody else.
- A code that is not in the platform's list cannot be added to a product's
  catalogue at all.
- After the merge, `max_projects` is one row, every offer version grants
  the same one, and no entitlement changed for anybody — asserted against a
  seeded world before and after.
- Deleting a feature deletes its translations, and deleting every
  translation leaves a catalogue in English rather than a catalogue of
  codes.
- `gate:i18n` is untouched: none of this is an application sentence.

## 9. Open questions for the operator

1. **Who may translate?** Today anybody with `catalog.manage`. A translator
   who is not a catalogue administrator would need a permission of their
   own, and that is a different decision.
2. **Must a translation be complete before an offer is advertised?** The
   readiness path (ADR-045) could refuse to publish an offer whose name has
   no French — or say nothing and let English through.
3. **Does a feature belong to nobody now?** One list at platform level
   means `plan.terrasse` is visible to whoever administers the catalogue of
   any product. That is a disclosure of one product's capabilities to
   another's administrator — small, and probably fine on a platform whose
   staff is one person, but it is a change and it is worth saying out loud.
4. **Machine translation**: out of scope here. If it ever arrives it is a
   suggestion in the console, never a value written without somebody
   reading it.
