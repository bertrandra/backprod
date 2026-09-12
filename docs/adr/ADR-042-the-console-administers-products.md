# ADR-042 — The console administers products, and names which one it means

**Status:** accepted
**Relates to:** [ADR-015](ADR-015-a-client-never-names-a-tenant.md);
[ADR-039](ADR-039-the-installer-appoints-the-first-administrator.md);
[ADR-041](ADR-041-the-storefront-sells-to-strangers.md);
Architecture V2 §10.1, §12.1, §25; non-negotiables #21, #22

## Context

A product is the top of this platform's model. A tenant is a tenant *of* a
product, an offer is priced *for* one, a membership is scoped *by* one, and
`product_configuration` keys from one. Everything else in this repository hangs
off that row.

And until now the platform could not show it to anybody.

**Nothing could create a product but the installer.** `INSERT INTO products`
appears twice in the whole repository: `deploy/siteground/setup.php`, once, at
install, and `bin/seed-demo.php`. There was no endpoint — `/api/v1/products` is
five `GET` routes and nothing else — no screen, and no permission for the act.
A deployment that wanted a second product had an `INSERT` typed against
production, which is exactly the situation ADR-039 removed for staff and had
never removed here.

**And nothing could even list them.** `GET /api/v1/products` resolves through
`ProductRegistry::reachableBy`, which joins `tenant_members`, because §12.1 is
clear that which products exist is commercial information and a person learns
only about the ones they belong to. A platform role never grants membership
(non-negotiable #22). So the platform's own administrator, asking the platform
what it hosts, was answered about their own tenant or not at all.

This surfaced as a real question from a real deployment: *"as an admin I cannot
find the top level with product."* They were right, and the answer was a `psql`
prompt.

A second, smaller thing had gone wrong with it. `console.admin.storefront`
shipped in ADR-041 reading its product from `useSessionStore(state =>
state.productCode)` — the code the *tenant application's* switcher fills, backed
by `localStorage`. So the console silently administered whichever product the
person had last used the application in, and showed an empty state to anybody
who had never opened the application at all.

## Decision

**`ProductDirectory`, a third port, answering "what exists".** Separate from
`ProductRepository` (which answers "which product is this code?" on every
request and is deliberately narrow) and from `ProductRegistry` (which answers
"which products may *this person* reach", by membership, and is therefore blind
to everything they are not a member of). The new port has no caller in the
question, which is why it is reachable only behind a permission.

**`staff.products.manage`, PLATFORM_ADMIN alone.** Not support, who reach every
other `/staff` route: a product is the unit every tenant, catalogue and invoice
hangs from, and switching one off is the widest single act available on this
platform. The membership-scoped route is left exactly as it was — the answer
belongs behind a permission rather than being widened for everybody because an
administrator needed it.

**`GET`, `POST` and `PATCH`, and no `DELETE`.** A product carries tenants,
subscriptions and invoices, and an invoice is a legal document (§25) — a
deletion would either cascade into records the law requires kept, or refuse.
`active = false` is retirement: it closes every door into the product (the
context chain already treats an inactive product as absent, and so does the
storefront) and leaves the history where it is. `DELETE` therefore answers 405
rather than 403, because the method is not offered at all rather than not
permitted to this caller.

**`PATCH`, not `PUT`.** `name` and `active` are independent: renaming a product
says nothing about whether it is active, and a `PUT` would make a form restate
it — which is how a product gets switched off by a checkbox somebody forgot to
send. An empty body is refused rather than absorbed: answering 200 to a request
that changed nothing is how a broken form looks like a working one.

**The code is chosen once and is never editable.** It is what clients send as
`X-Product`, what the storefront takes as `?product=`, what
`product_configuration` keys from and what a deployment's
`VITE_DEFAULT_PRODUCT` has to match. An identifier that can change is not an
identifier — the same rule offer codes already follow. It is validated rather
than merely trimmed (`^[a-z0-9][a-z0-9-]*$`) because it travels in URLs and
headers, and lowercased rather than refused, because capitals are a typing
habit and not a different product.

**The console names the product it is administering, in the URL.** Not from an
ambient context, because the console has none and must not borrow the tenant
application's. `?selected=` — the same view-state field
`console.support.tenants` already uses for the tenant somebody opened. A link
then opens the same product for whoever follows it, which is what ui-spec §4.3
asks of every state that matters, and `console.admin.storefront` stops
depending on where the person happened to have been.

**Reads are not recorded; writes are.** Non-negotiable #21 traces staff
crossing into a *tenant's* data, and the platform's own product list is not
that. The decisions are recorded as `CREATE`, `RENAME`, `RETIRE` and
`REINSTATE` — four actions rather than one `UPDATE` with a payload, because
"who switched this off?" is a question somebody asks at a bad moment and a row
saying only that it was edited cannot answer it.

## Consequences

An administrator can now answer "what does this deployment host, and what are
the codes?" from a screen, which was the immediate problem. Creating a second
product is a form rather than a database session.

A new product is genuinely empty — no plans, no offers, no tenants, no
configuration. Nothing is copied from an existing one, because copying a
catalogue is a decision nobody asked for and an expensive one to undo; the
screen says so before the code is chosen rather than after. In particular a new
product has no `billing_supplier` configuration, so a checkout against it
refuses with `BILLING_NOT_CONFIGURED` until somebody sets it — and there is
still no screen for that. It remains `product_configuration`, by hand.

Retiring a product is now one click, and it is the widest single act on the
platform: every member of every tenant of that product loses their way in at
once. The screen states the consequence beside the button and the trail records
who decided, which is the most a UI can do about an act that is meant to be
available.

`GET /api/v1/products` keeps answering nothing for a platform administrator.
That reads like a bug and is not one: two questions, two permissions, and the
narrow one stays narrow.

## Alternatives rejected

**Widen `listProducts` for platform roles.** One endpoint answering two
different questions depending on who asks, with §12.1's "which products exist
is commercial information" holding for one caller and not the other. The leak
would be a missing branch rather than a missing route.

**A `DELETE` that refuses when a product has tenants.** It would be available
exactly until the moment somebody wanted it and then refuse, teaching people
the console is unreliable — and "has no tenants" is not the same as "has no
invoices", so the condition would have to grow.

**Keep the console's product in the session store, alongside the tenant
application's.** Two pieces of state meaning almost the same thing, one of them
invisible in the URL. The bug ADR-041 shipped was that ambiguity in its
simplest form.
