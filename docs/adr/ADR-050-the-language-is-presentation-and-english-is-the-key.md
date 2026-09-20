# ADR-050 — The language is presentation, and English is the key

**Status:** accepted, 2026-09-19.

## Context

The platform spoke one language, English, and the operator asked for four
more — French, Spanish, German, Italian — "with low impact on performance".
Every screen carried its sentences as literals; every mail the platform sent
had one wording; every date and amount was formatted by the browser's own
locale rather than by any choice the person made.

Three ways of doing this were on the table, and the difference between them
is where the cost lands:

1. **A translation library with a React context** (`react-i18next` and the
   like): every component subscribes, keys are invented (`billing.buy.self`),
   and a screen reads as a list of identifiers. The runtime cost is a
   thousand subscriptions and the authoring cost is a second vocabulary.
2. **The server renders the words**: the API would answer in the caller's
   language. That makes a presentation fact a contract fact, breaks every
   client that matched on a message, and puts translation on the request
   path.
3. **English as the key, catalogues on demand**: the screen keeps saying
   `Buy for yourself`, wrapped in `t()`; a French catalogue maps that
   sentence to its French; the catalogue is one chunk, fetched once, only
   by people reading French.

## Decision

**The language is presentation.** What varies by language is the words on a
screen, the words in a mail, and how a date or an amount is written. Codes,
enumerations, offer names, error codes and the API's own messages stay what
the API says: the contract is English and does not change with the reader.

**English is the key.** `t('Buy for yourself')` is the English sentence
itself. A missing translation falls back to English, not to an identifier;
English costs nothing to load; the source reads as the screen does.
Placeholders are `{name}`, filled after translation, so a translated sentence
can put them in its own order. A plural is two sentences chosen before
translation (`t(n === 1 ? '{count} step' : '{count} steps', { count })`),
because the languages disagree on where the number goes.

**One catalogue per language, one chunk each.** `src/i18n/catalogues/fr.json`
and its siblings are `{ "English": "Traduction" }`, imported with a dynamic
`import()` that Vite splits into its own file (about 100 kB, 25 kB
compressed), cached by the browser, fetched only by people reading that
language. English has no catalogue at all.

**One module-level `t`, no context.** `t` is a plain function so it works in
a hook, a component, a query, a table of error wording and a navigation tree
alike. A language change is rare, so it remounts the tree (`App` keys
`AppProviders` on the locale) rather than subscribing every component — and
nothing renders before the catalogue is in, so no screen paints English and
then flips. Sentences carried as data — a navigation label, an error's title,
a retention ground — are translated where they are rendered, never where they
are declared, because a table evaluated at import time would be evaluated
before any catalogue was loaded.

**Which language, decided once, in this order:** `?lang=` in the address
(somebody saying which they mean now), then what the browser remembered
(`localStorage`, `backprod.locale`), then the browser's own languages by their
two-letter code, then English. Signing in applies the person's own choice
(`/me.locale`) unless the address named one. The choice is a field of the
person — `users.locale`, `PATCH /me {locale}`, refused `422 LOCALE_UNKNOWN`
for a language the platform does not speak — and it is applied the moment it
is saved.

**Before there is an account** (2026-09-20), the storefront and the sign-in
form carry a small language select: applied at once, remembered by the
browser, and — this is the point — sent with the sign-up (`locale` on
`signUp`, one of the five, else `400`), so the account starts in the
language the person was reading in: the browser's, or the one they picked.
Signed in, the language is on the profile, which the account menu (top
right) opens.

**Mails follow the recipient.** `DispatchNotifications` renders a mail in the
recipient's `users.locale`; `MailWording` holds defaults per language and the
platform administrator's overrides per language and type
(`platform_settings.mail_templates` is `{locale: {type: {subject, body}}}`;
the older flat shape is read as English). A language with no words of its
own for a type uses English's, which is what a person reading in that
language receives — and the console's Mail screen says so, one tab per
language, with *Send me a test* in the language being edited.

**Invoices remember their language.** `invoices.locale` is written when the
invoice is issued, the same rule as its numbers: a document is rendered in
one language and stays in it. It is the language of the person the document
is addressed to when it names one (a seat), else of the person who raised it,
else English — read at that moment, because the person's choice may change
and the document must not. Rendering the PDF in that language is a
follow-up; the column exists so that the fact is never lost.

**The gate.** `npm run gate:i18n` collects every `t('…')` in the source, every
prose string a table carries, and both branches of a plural, and fails the
build when a catalogue lacks one, has one the screens no longer say, leaves
one empty, or says different `{placeholders}` from its English. Missing words
would show English in a French screen; an orphan is a translation of nothing,
which is how a catalogue rots; a placeholder that differs drops a name or an
amount from one language's screen. `--write` seeds what is missing and
removes what is orphaned, so a translator sees what is left.

The languages are `en`, `fr`, `es`, `de`, `it` — `Locale::ALL` on the server,
`LOCALES` on the client, a `CHECK` on both columns, and the contract's enum.
Adding a sixth is one catalogue, one entry in each list and one migration.

## Consequences

- Every screen's English is wrapped in `t()`. A one-off codemod
  (`frontend/scripts/i18n-wrap.mjs`) did the first pass — JSX text, prose
  attributes, conditional branches and template literals inside JSX — and is
  kept for a screen written before it and forgotten. New screens write `t()`
  by hand; the gate catches a sentence that a catalogue lacks, not one that
  was never wrapped, so a review still reads for bare English.
- `Money` and `When` format with the chosen locale rather than the browser's;
  `toLocaleDateString(currentLocale())` everywhere else.
- A sentence with markup inside it is one key, not three. `tx()`
  (`src/i18n/react.tsx`) translates the whole sentence and splices React
  nodes into its `{placeholders}` afterwards — `tx('Changing this needs
  {permission}, which an administrator holds.', { permission: <code>…</code> })`
  — so a language can put the pieces in its own order. Three `t()` fragments
  around a `<code>` were three keys a translator saw in isolation, and a
  sentence cut where English cuts it does not survive German. Data is never
  wrapped: a variable name, an example address, a keyboard shortcut stay as
  written.
- Tests assert English. `t` returns its key in the test locale, so no test
  changed for the wrapping; a test of the language itself installs a
  catalogue with `installCatalogue()` and applies `setLocale()`.
- Migration `Version20260919150000` adds `users.locale` and
  `invoices.locale`.
