# ADR-036 — The frontend contract is generated, and the check is the regeneration

**Status:** accepted
**Decides:** UD1, UD2 and UD6 from [ui-roadmap.md](../ui-roadmap.md) §0
**Relates to:** Architecture V2 §8.1, §34; [ui-spec.md](../ui-spec.md)

## Context

§8.1 says OpenAPI is the source contract, that the TypeScript types and API
client are generated from it, and that TanStack Query consumes only that
client. Until now that was enforced by reading the document.

The roadmap's U0 exists to change that, and it named three decisions that had
to be made before any screen: which generator (UD1), how the generated output
is checked fresh (UD2), and how `X-Product` reaches every request (UD6).

## Decision

### UD1 — `openapi-typescript` for types, `openapi-fetch` for the client

**Types only, no runtime, from a generator whose primary target is OpenAPI
3.1.** The contract is 3.1 and many generators still treat it as 3.0 with
extras; that is the first filter, and it removed most candidates before
ergonomics were considered at all.

`openapi-typescript` emits a single `.d.ts` and nothing executable, so there is
no generated code anyone could be tempted to edit "just this once".
`openapi-fetch` is a small typed wrapper that consumes those types and infers
path, parameters, body and response from them.

**Rejected: generators that emit TanStack Query hooks** (orval, and the
hook-generating modes of others). They would put generated code *inside* the
query layer, blurring the boundary §8.1 draws between the client and TanStack
Query, and they generate far more code — all of which has opinions about
caching and keys that the application should be choosing. A thin client that
TanStack Query wraps keeps each layer doing one thing.

The pinned versions are the intersection of three peer ranges, and finding it
required checking rather than guessing: `typescript-eslint` supports TypeScript
`<6.1.0`, `openapi-typescript` peers `^5.x`, and TypeScript 7 is already
released. TypeScript 5.9 is the only version all three accept. Installing the
newest of each would not have worked.

### UD2 — The check is the regeneration

`gate:client` regenerates from `openapi.json` and compares the result with the
committed file. It does not compare timestamps, hash the input, or record a
version — **all of those can agree while the output is stale**, which is
precisely the failure being guarded against. The only thing that proves the
output current is producing it again.

The generated file is committed. A checkout compiles without a generation step,
and a reviewer sees contract changes as a diff in the same PR that changed the
contract rather than as an invisible consequence of it.

**The gate reports which line drifted**, not just that something did. "Run
`npm run generate`" is the fix, but a reviewer reading a CI log wants to know
what moved in the contract.

It runs **before** lint and typecheck in CI. If the contract moved and the
client did not follow, every type error the later steps report is about a stale
shape and sends the reader looking in the wrong file.

### UD6 — `X-Product` and the token are ambient, set once

Both are attached by one middleware inside `src/api/client.ts`, reading from
functions rather than values because both change while the application runs —
the product switcher in region A, and a token that refreshes.

**No call site passes either.** Product is the root context, and 34 screen
areas each remembering to attach it is 34 chances to forget. A screen that
could pass the product could pass the wrong one.

A header with no value is **omitted rather than sent empty**. An empty
`Authorization` is a malformed credential and an empty `X-Product` claims a
product that does not exist; the backend refuses both, but it should be
refusing a request nobody meant to send rather than one this layer built badly.

### The rule is a lint rule, not a convention

ESLint forbids `fetch`, `XMLHttpRequest`, `EventSource`, `window.fetch` and its
other spellings, and every HTTP library, everywhere in `src/` except
`src/api/client.ts`. Importing `openapi-fetch` outside that file is forbidden
too, as is reaching into `src/api/generated/` — call sites import from
`@/api/client`, which re-exports what they need.

§8.1 says a single contract is not defended by the discipline of whoever writes
the `fetch()` but by there being nowhere to write one. **A rule is cheaper than
review discipline and does not get tired.**

## Consequences

**Both gates were proven by breaking them**, which is the standard M0 set for
Deptrac and the UI coverage gate followed:

| broken deliberately | result |
|---|---|
| renamed a field in the committed generated file | `gate:client` named line 2390, printed committed vs regenerated, exit 1 |
| `fetch`, `window.fetch` and `new XMLHttpRequest` in a component | 4 lint errors, each pointing at §8.1 |
| `import axios` in a component | lint error naming the client as the way to reach the API |

A gate only ever seen green is not known to work.

**The lint rule caught its own author.** The first version of `client.test.ts`
imported `Middleware` from `openapi-fetch` to type a test helper, and the rule
refused it. The fix was better than the exception would have been: the type is
now derived from `contextMiddleware`'s own return type, so the test cannot keep
passing after the client stops using that library. Where a rule and a
convenience disagree, it is worth checking which one is wrong before carving an
exception.

**`ext-gd`-style surprises exist here too.** A pinned Playwright and a
sandbox-provided Chromium can disagree about revision.
`PLAYWRIGHT_CHROMIUM_PATH` points at an existing binary when one is supplied;
unset — as in CI — Playwright resolves its own.

**The frontend job is parallel to the PHP job, not sequential.** They share only
`openapi.json`. Neither needs the other's toolchain, so a lint error surfaces
without waiting for a database to start.

**What U0 does not deliver:** any screen. There is one placeholder component so
the build has something to compile and the browser harness has something to
load. The frame, its six regions and routing are U1. Design tokens are not
guessed at here either — an empty token block would only be a prediction about
what U1 needs.

**Still unbuilt, and named in ui-spec.md §7:** the screen-level check that every
area has a route and calls its operations through the client. That needs
screens, so it remains U9's.
