# A field the operator writes in five languages, and a code they never type

**Status:** specification, 2026-09-24. Nothing below is built.
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

## 2. Where a translated value lives

**Proposal: a JSONB column beside the field it translates**, not a table of
translations.

```text
features.name          text            the English, required, the key
features.name_i18n     jsonb           {"fr": "...", "es": "...", ...}
features.description       text        }  the same pair, for the sentence
features.description_i18n  jsonb       }  a customer reads
offers.name            text
offers.name_i18n       jsonb
```

English stays in its own column and stays required. It is the key, exactly
as ADR-050 decided for the application's own sentences: a missing French
falls back to something a person can read rather than to a code, and the
platform's own API, logs and invoicing stay in one language.

**Why not a `translations` table.** It is the textbook answer and it costs
a join on every list, a locale filter in every query, and a second place
for a row to be orphaned. The set of languages is fixed and small (five,
ADR-050), a catalogue is tens of rows, and the value belongs to the row it
describes. A table would be right if translations were versioned,
attributed or reviewed — none of which anybody has asked for.

**Resolution is the reader's, at the edge.** The API answers a *resolved*
string to a tenant or a stranger — `name` in the language the caller reads,
falling back to English — and the *whole map* to the console, which is the
only place that edits it. Two shapes, one row, and the resolution rule
written once in PHP the way `MailWording` already resolves a mail.

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

## 4. Where the codes come from

A feature code is a contract between the platform and a product's own code.
So the form stops accepting prose and offers a list.

```text
product_configuration[product].feature_catalogue = [
  { code: "plan.terrasse",      kind: "BOOLEAN", name: "Terrace engine" },
  { code: "plan.documents",     kind: "QUOTA", unit: "documents" },
  …
]
```

- The four the platform itself enforces — `max_projects`, `exports`,
  `users`, `white_label` — are always in the list, whatever a product says,
  because the platform reads them (`ProjectWorkspace::QUOTA`,
  `SubscriptionPeople::USERS_FEATURE`).
- The rest are the product's, and the product is the authority on them.
  Today the operator types them once, in the console, for a product with no
  server of its own. When a product grows one, the same list arrives by
  `PUT /api/v1/product/capabilities` with its product key — the route
  exists in ADR-051's authority, and the console then shows the list
  read-only, because a program that gates on a code may not be contradicted
  by a form.
- Creating a feature therefore **picks** a code, and the kind and unit come
  with it. Typing a code that is not in the list is refused, not corrected:
  `FEATURE_CODE_UNKNOWN`, with the list in the details.

A code already sold cannot be withdrawn from the list silently: removing
one that an offer version grants is refused the same way retiring a product
with a live subscription is.

## 5. What the API changes

```text
GET  /api/v1/products/{productId}/catalog        unchanged shape, resolved name
GET  /api/v1/staff/products/{id}/catalogue       names as maps, for editing
POST /api/v1/staff/products/{id}/features        code from the list, name + name_i18n
PATCH /api/v1/staff/features/{featureId}         the map, partially
PATCH /api/v1/staff/offers/{offerId}             the map, partially
GET  /api/v1/staff/products/{id}/feature-catalogue   what may be picked
PUT  /api/v1/staff/products/{id}/feature-catalogue   staff.products.manage
PUT  /api/v1/product/capabilities                    a product's own key
```

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

- No translation in the frontend's catalogues for operator data. Those are
  the application's sentences, shipped with the bundle; a feature's name
  belongs to the deployment's database.
- No code typed twice. The picker is the only way in, and the platform's
  four are not special-cased in a component.
- No resolution in React. The API answers the reader's language, as it does
  for mails; a component choosing among five strings would be the money
  rule's mistake in another currency.
- No language forced on a customer by an address (ADR-050, amended
  2026-09-23): the resolution reads the person's own language.

## 8. Build order

1. **The mechanism, on one field.** `features.name_i18n`, resolution in
   PHP, the map on the staff read, the button at the end of the field.
2. **The rest of the catalogue.** Feature descriptions, offer names.
3. **The codes.** `feature_catalogue` in product configuration, the picker,
   the refusal, and the console screen that edits the list.
4. **The product's own voice.** `PUT /product/capabilities` under a product
   key, and the console list becomes read-only for a product that speaks.
5. **The demonstration**, translated.

**Exit criteria**

- A feature created in the console with a French name shows that name to a
  French reader and its English to everybody else.
- A feature code that no product declares cannot be created at all.
- Deleting every translation leaves a catalogue in English, not a catalogue
  of codes.
- `gate:i18n` is untouched: none of this is an application sentence.

## 9. Open questions for the operator

1. **Who may translate?** Today anybody with `catalog.manage`. A translator
   who is not a catalogue administrator would need a permission of their
   own, and that is a different decision.
2. **Must a translation be complete before an offer is advertised?** The
   readiness path (ADR-045) could refuse to publish an offer whose name has
   no French — or say nothing and let English through.
3. **Machine translation**: out of scope here. If it ever arrives it is a
   suggestion in the console, never a value written without somebody
   reading it.
