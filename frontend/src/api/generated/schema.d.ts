/**
 * GENERATED FILE — DO NOT EDIT.
 *
 * Produced from openapi.json by `npm run generate`.
 *
 * A missing field is a field missing from the contract: fix openapi.json and
 * regenerate. Editing this file produces a change the next generation
 * overwrites, and a fix that vanishes silently is worse than no fix (§8.1).
 */

export interface paths {
    "/api/v1/admin/audit": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The audit trail
         * @description Behind its own permission, and separate from the tenant surfaces entirely (#19).
         */
        get: operations["listAudit"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/erasures": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Forget a person, keeping what the law requires
         * @description Non-negotiables #14 and #15. The person is **anonymised, never deleted** — the schema forbids the alternative: of twenty foreign keys into users, five RESTRICT and four CASCADE, so a delete would either be refused or would silently take notification history with it.
         *
         *     The response is the receipt. What was kept is counted and justified per category, because afterwards the question is not "did you delete everything?" — it did not, deliberately — but "what did you keep, and on what ground?"
         *
         *     **The invoice still names them afterwards.** Altering an issued invoice falsifies a legal document, which is a worse answer than keeping it.
         */
        post: operations["eraseUser"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/invoices": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Every invoice raised, across tenants
         * @description `/admin/metrics` answers "how much"; this answers "which ones", which is the question an unpaid balance actually raises.
         *
         *     Requires `admin.finance.read`.
         */
        get: operations["listAdminInvoices"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/jobs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What the queue is carrying
         * @description `/admin/queue` answers whether the runner is alive (R10); this answers what it is carrying, which is the next question when the answer to the first is "yes, and something is still wrong".
         *
         *     **The payload is deliberately not returned.** It is whatever the caller handed the queue, it can carry anything, and "which jobs are stuck" does not need to know what is inside them.
         *
         *     Requires `admin.health.read`.
         */
        get: operations["listAdminJobs"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/metrics": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Turnover, top offers and renewal
         * @description Read from monthly rollups, never recomputed over the ledger — a dashboard that scans every transaction gets slower every month it succeeds (§25.2).
         *
         *     **Turnover is what was invoiced, not what was collected**, and currency is part of the grain: €100 and $100 are never added. A renewal rate is *null* when nothing came up for renewal, because zero would claim everybody left.
         *
         *     The product is named in the query because an admin surface resolves none of its own.
         */
        get: operations["showMetrics"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/queue": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Whether the job queue is still being polled
         * @description A quiet queue and a cron that stopped firing produce the same silence, and this is what tells them apart (R10).
         *
         *     Three signals, because they are three different things to go and fix: `seconds_since_finished` ageing is a cron that stopped; an ageing `oldest_unfinished_seconds` is a runner that died mid-pass; a growing backlog while the clock looks healthy is a wedged handler.
         *
         *     **No staleness verdict is invented.** How often cron fires is deployment configuration this process does not know, so `stale` appears only when `?stale_after=` supplies the expectation.
         */
        get: operations["showQueue"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/subscriptions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What is running, and on what terms
         * @description Carries the offer version each subscription was **sold on**, not the version on sale today — §12's whole point is that those differ, and an operator looking at a customer's bill needs the terms that bill was priced against.
         *
         *     Requires `admin.finance.read`.
         */
        get: operations["listAdminSubscriptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/tenants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Every tenant, with its operational standing
         * @description Distinct from `/staff/tenants`, which support uses to answer one customer's question and which writes an access-log row for having looked. This is the operations view: every tenant with the counts that say whether an account is healthy, and nothing from inside any of them.
         *
         *     Requires `admin.directory.read`.
         */
        get: operations["listAdminTenants"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/users": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * People, across every tenant
         * @description **The only admin surface that returns personal data**, which is why PLATFORM_ADMIN alone may call it: support already has an audited per-tenant read at `/staff/tenants/{tenantId}`, and a list of everyone answers no support question.
         *
         *     An erased person still appears, carrying `erased_at` and no identity. The row survives erasure by design (non-negotiables #14 and #15) — hiding it would make this directory disagree with every count beside it, and would suggest the person had been deleted when the whole point is that they were not.
         *
         *     Requires `admin.directory.read`.
         */
        get: operations["listAdminUsers"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/assets/{assetId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One file's metadata
         * @description Not its bytes — those come from a signed link.
         */
        get: operations["showAsset"];
        put?: never;
        post?: never;
        /**
         * Delete a file
         * @description Removed from storage as well as from the index.
         */
        delete: operations["deleteAsset"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/assets/{assetId}/link": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Mint a signed download link
         * @description Assets are private; this is the only way to read one. The link carries its own signature and expiry, which is why the download path below needs no credential — and why nothing may be mounted under that prefix that does not verify its own signature.
         *
         *     A body is optional: minting with the default lifetime needs nothing said, and demanding `{}` from a client with nothing to say is a rule that serves nobody.
         */
        post: operations["createAssetLink"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/refresh": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Exchange the refresh cookie for a new session
         * @description Takes no body: the credential is the `HttpOnly` cookie, which the browser attaches by itself. A body field would mean a script had read the token, which is what the cookie exists to prevent. The refresh token is rotated on every call, so presenting a spent one is evidence of a copy and revokes every session for the account.
         */
        post: operations["refreshSession"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/sign-out": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * End this session
         * @description Always 204. An unknown token, an expired one, or no cookie at all all end with the caller signed out, because that is what they asked for. The refresh token is revoked server-side as well as cleared from the browser: clearing the cookie alone would leave the credential valid for anybody holding a copy.
         */
        post: operations["signOut"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/token": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Exchange an email and password for a session
         * @description Public, and the most attacked endpoint any application has — which is why the rate limiter sits in front of authentication (§31) and the tighter public allowance applies here. A wrong address and a wrong password are answered identically, and the attempt is logged with the address so brute force is visible in the log rather than in the response.
         */
        post: operations["signIn"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/credit-notes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's credit notes
         * @description Newest first, paginated.
         */
        get: operations["listCreditNotes"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's invoices
         * @description Newest first, paginated.
         */
        get: operations["listInvoices"];
        put?: never;
        /**
         * Raise the invoice for the current subscription
         * @description Takes no body: what to bill is the subscription in force, and letting a client name the amount would let a client name the amount. Issuing assigns the next number in a gapless sequence, under a lock — two simultaneous requests cannot both take it.
         */
        post: operations["issueInvoice"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One invoice
         * @description With its lines and its tax rows. The party snapshots are as of issue, not as the tenant is now.
         */
        get: operations["showInvoice"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}/cancel": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Cancel an invoice
         * @description Only while it is still a draft. A final invoice is corrected by a credit note, never cancelled — cancelling one that has been sent would leave a hole in the legal sequence.
         */
        post: operations["cancelInvoice"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}/credit": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Correct a final invoice
         * @description The only way to change a document that has been issued. The credit note takes the next number in its **own** gapless sequence.
         */
        post: operations["issueCreditNote"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}/pay": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Record an invoice as paid
         * @description For payment received outside the platform — a transfer, a cheque. Money taken through a PSP arrives by webhook instead and must not be recorded here as well.
         */
        post: operations["markInvoicePaid"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}/payments": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Begin a payment through the provider
         * @description Returns the payment **and the provider's client secret**, which is what the browser needs to complete the charge. Card data never reaches this API (§24): the client talks to the provider directly and the platform learns the outcome by webhook.
         */
        post: operations["startPayment"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}/pdf": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One invoice as a PDF
         * @description The document is rendered on the first request and stored, and every later request returns those same bytes — so what is served is what was sent. `ETag` is the SHA-256 of the stored document. A draft has no legal number and therefore no document, which is a 409 rather than a 404: the invoice exists, it is simply not issued yet. Behind `billing.read`, the same permission that returns the invoice as JSON.
         */
        get: operations["showInvoicePdf"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}/transmissions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * An invoice's transmission attempts
         * @description Every attempt, not just the last: a rejection followed by an acceptance is two facts, and the first is the one an auditor asks about.
         */
        get: operations["listTransmissions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/invoices/{invoiceId}/transmit": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Send an invoice to the e-invoicing platform
         * @description 202, not 200: submission is handed to the provider and settles later. Poll the transmissions below, or wait for the webhook. **Today this reaches a stub** — no certified platform is connected yet (R3).
         */
        post: operations["submitInvoice"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/payments": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's payments
         * @description Newest first, paginated.
         */
        get: operations["listPayments"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/payments/{paymentId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One payment
         * @description Including why it failed, as a code and a category — never the provider's raw message, which can carry an endpoint or a token (§31).
         */
        get: operations["showPayment"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/payments/{paymentId}/refund": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Refund a settled payment
         * @description 202: the provider decides when the money moves. Omitting `amount_minor_units` refunds the whole payment; a partial refund may not exceed what is left unrefunded, and the database enforces that rather than the caller remembering it.
         */
        post: operations["refundPayment"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/billing/profile": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's invoicing identity
         * @description Every field is nullable: a tenant that has not filled this in yet gets nulls rather than a 404, because the profile is a property of the tenant and always exists conceptually.
         */
        get: operations["showBillingProfile"];
        /**
         * Replace the invoicing identity
         * @description PUT, not PATCH: this is the whole identity, and a partial update of a legal address is how half an address reaches an invoice. Only `legal_name` is required.
         */
        put: operations["saveBillingProfile"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/checkout/sessions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Buy an offer in one call
         * @description Places the order, raises its invoice and asks the provider to authorize — three calls a client could already make. What this adds is that the client no longer orchestrates them, and that a dropped connection leaves an order they can look up rather than a charge nobody can account for.
         *
         *     **The body names an offer and nothing else.** No amount, because a client that could name a figure could name a smaller one; the price is the offer's. No instrument, because the customer gives that to the provider directly — card data never reaches this platform's database (§24).
         *
         *     **It does not start the subscription.** That waits for the money, through the same webhook everything else arrives by. A checkout that activated on creation would extend credit to anyone who can reach this endpoint.
         */
        post: operations["openCheckoutSession"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/checkout/sessions/{sessionId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Where the purchase got to
         * @description The status is derived from the order and its latest payment rather than stored: the order knows whether it completed, the payment knows whether money moved, and a third status written down could disagree with both.
         *
         *     No `client_secret` — there is nothing to return it from.
         */
        get: operations["showCheckoutSession"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Threads the caller is in
         * @description Participation, not tenancy: being in the tenant does not put you in its threads.
         */
        get: operations["listConversations"];
        put?: never;
        /**
         * Start a thread
         * @description Participants must belong to the tenant; naming somebody outside it is refused rather than silently dropped.
         */
        post: operations["startConversation"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations/{conversationId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** One thread and who is in it */
        get: operations["showConversation"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations/{conversationId}/close": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Close a thread
         * @description A closed thread takes no more messages.
         */
        post: operations["closeConversation"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations/{conversationId}/messages": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Messages in a thread
         * @description `since_seq` fetches what followed, which is how a client catches up without re-reading the thread.
         */
        get: operations["listMessages"];
        put?: never;
        /**
         * Say something
         * @description Only a participant may. The sequence number is assigned by the database, so two simultaneous messages cannot take the same one.
         */
        post: operations["postMessage"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations/{conversationId}/messages/{messageId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Delete a message
         * @description Only its author. The body goes and the message keeps its place, so the thread does not develop a hole where a reply used to be.
         */
        delete: operations["deleteMessage"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations/{conversationId}/participants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Add somebody to a thread
         * @description They must belong to the tenant. Platform staff cannot be added here at all — a composite foreign key confines them to SUPPORT threads.
         */
        post: operations["addParticipant"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations/{conversationId}/participants/{userId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Remove somebody from a thread
         * @description A tenant cannot eject support from its own support thread — that would end the conversation from one side only.
         */
        delete: operations["removeParticipant"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/conversations/{conversationId}/read": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Move the read watermark
         * @description It never goes backwards: marking an older message read cannot un-read a newer one.
         */
        post: operations["markRead"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/downloads/{assetId}/content": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Fetch a file's bytes with a signed link
         * @description The only endpoint that returns something other than JSON, and one of four reachable with no credential — because the **link** carries the authority. It is minted with an expiry and a signature, and this verifies both before a byte is served.
         *
         *     That is why it lives under a public prefix and why nothing may be mounted there that does not authenticate its own request. An expired or tampered link is a 403 with no detail: telling a probing caller which half was wrong is telling them how to fix it.
         *
         *     `X-Content-Type-Options: nosniff` is set deliberately. The stored content type was sniffed from the bytes on upload rather than taken from the uploader's claim, and this stops a browser second-guessing that and executing something as a different type.
         */
        get: operations["downloadAsset"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/entitlements": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What the tenant is entitled to
         * @description Resolved from what is in force now, so a lapsed subscription grants nothing without anything having had to sweep it first. This is the *tenant's* view: a seat held by one colleague still appears here, which is a known gap rather than a design.
         */
        get: operations["listEntitlements"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/features": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The capabilities this product sells
         * @description The vocabulary entitlements are expressed in. A quota carries its unit; a boolean must not.
         */
        get: operations["listFeatures"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/geometry/intersections": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * One shape against a set of candidate polygons
         * @description Answers overlap, containment and proximity for each candidate, in the order they were sent. The candidates carry ids the caller chose and those ids come straight back, which is what makes this usable against a parcel register the platform has never seen: the caller holds the mapping and this side holds none of it.
         *
         *     A polygon subject asks what a footprint touches; a point subject asks which parcel holds an address. Accepted in either coordinate reference, because topology does not depend on the unit — but `distance` is withheld in degrees.
         */
        post: operations["intersectGeometries"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/geometry/measure": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Area, perimeter and extent of a polygon
         * @description Pure computation: no row is read and none is written, and two tenants asking the same question get the same answer. The door is the `gis.access` **entitlement** rather than a permission — what the plan bought, not what the role allows (§10.2) — which is why the refusal here is 403 `ENTITLEMENT_REQUIRED` and never 404.
         *
         *     Requires projected coordinates; see `CoordinateReference` for why degrees are refused rather than converted.
         */
        post: operations["measureGeometry"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/health": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Liveness probe
         * @description The one path reachable with no credential at all. It answers from the process alone: an unreachable database must not make the process look dead to an orchestrator, so this stays up when data is down.
         */
        get: operations["health"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/jobs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's jobs
         * @description Newest first, paginated.
         */
        get: operations["listJobs"];
        put?: never;
        /**
         * Queue a job
         * @description `idempotency_key` makes a retry safe: a second request with the same key while the first is still pending returns that one rather than queueing another. Exactly-once here is a unique index, not a check two callers would both pass.
         */
        post: operations["requestJob"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/jobs/{jobId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One job and its result
         * @description Poll this after a 202. `failure_reason` is a category — the driver's account of what failed goes to the log, never over the wire.
         */
        get: operations["showJob"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/jobs/{jobId}/cancel": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Cancel a queued job
         * @description Only while it is still waiting. A job already running is left alone: stopping it midway would leave whatever it had done half finished.
         */
        post: operations["cancelJob"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The caller, as this product and tenant see them
         * @description Everything the §10.6 chain resolved: who, where, and what that combination permits. `roles` and `permissions` are the tenant's grant; `capabilities` are what the subscription bought. Authorisation asks about capabilities, never about a plan's name (§13).
         */
        get: operations["showMe"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Change the caller's own profile
         * @description Partial by design: an absent `display_name` leaves it untouched, an explicit `null` clears it. Those are different requests and are not collapsed into one.
         */
        patch: operations["updateMe"];
        trace?: never;
    };
    "/api/v1/me/entitlements": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What the caller's subscription bought
         * @description Capability codes, resolved once by the context chain from what is in force now. A lapsed subscription grants nothing without anything having to sweep it first — entitlement asks the clock.
         */
        get: operations["showMyEntitlements"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/me/permissions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The caller's roles and permissions here
         * @description The authorisation half of `/me`, on its own, for a client that wants to refresh what is allowed without re-reading the profile.
         */
        get: operations["showMyPermissions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The caller's notifications
         * @description Theirs alone: another person's notification is not found rather than refused, because whether it exists is itself none of their business.
         */
        get: operations["listNotifications"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications/consents": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Consents given and withdrawn
         * @description Both, deliberately: a withdrawn consent is evidence that permission once existed and when it ended.
         */
        get: operations["listConsents"];
        put?: never;
        /**
         * Record an opt-in
         * @description SMS and WhatsApp are attempted only against one of these. With none, the delivery is written SUPPRESSED with NO_CONSENT and nothing is tried — the same posture as VIES unreachable granting no reverse charge.
         */
        post: operations["grantConsent"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications/consents/{consentId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Withdraw a consent
         * @description Dated, not deleted. The row stays with a `revoked_at`, because proof that permission was once given is the point of keeping it.
         */
        delete: operations["revokeConsent"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications/preferences": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Which categories reach which channels
         * @description Defaults filled in, so a client never has to know what the platform would have done in the absence of a row.
         */
        get: operations["showPreferences"];
        /**
         * Turn one category-and-channel on or off
         * @description SECURITY cannot be switched off, and the database says so rather than this endpoint remembering to (non-negotiable #21).
         */
        put: operations["savePreference"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications/read-all": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Mark everything read
         * @description Returns how many changed, which is not the same as how many existed — one already read is not counted twice.
         */
        post: operations["readAll"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications/unread-count": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * How many are unread
         * @description A badge, on its own, so a client polling it does not fetch a page it will not render.
         */
        get: operations["unreadCount"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications/{notificationId}/deliveries": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What happened on each channel
         * @description Including the ones that were suppressed, and why. This is the endpoint that answers "did we tell them, and if not what stopped it?"
         */
        get: operations["showDeliveries"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/notifications/{notificationId}/read": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Mark one read
         * @description Reading twice keeps the first timestamp. When somebody first saw it is a fact; when they last clicked is not.
         */
        post: operations["readNotification"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/offers": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Offers currently on sale
         * @description Only what may be sold *now*: an offer whose version's validity window has closed is absent rather than marked expired, because a client that can see it will eventually try to buy it.
         */
        get: operations["listOffers"];
        put?: never;
        /**
         * Create an offer and its first draft version
         * @description One call, because an offer with no version has no price and no terms — nothing can be sold, shown or quoted from it. Born `DRAFT`; publishing is a separate act.
         *
         *     Behind `catalog.manage`, not `catalog.read`: reading what is on sale is something every member does, deciding what it costs is not.
         */
        post: operations["createOffer"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/offers/{offerId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One offer on sale
         * @description The offer with its plan and the version that prices it, including every grant that version carries.
         */
        get: operations["showOffer"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Rename an offer
         * @description The name, and only the name. The `code` is how quotes, orders and invoices name this offer; price, terms and grants live in versions, and a version that has been sold is what somebody bought (§12). So what is editable here is the one field no document depends on.
         */
        patch: operations["renameOffer"];
        trace?: never;
    };
    "/api/v1/offers/{offerId}/publish": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Put one draft version on sale
         * @description The version is named in the body rather than the path because an offer can hold several drafts, and "publish the offer" would have to guess which one.
         *
         *     Two versions of one offer may not be on sale over the same period. That is an exclusion constraint in the database, not a check in the service, so it holds against concurrent publishes as well as careless ones.
         */
        post: operations["publishOfferVersion"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/offers/{offerId}/versions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Every version of an offer, drafts included
         * @description Behind `catalog.manage`, and that is the whole distinction: what a company is about to launch, and at what price, is commercial information. `GET /offers/{offerId}` answers what may be bought today and shows a draft to nobody.
         */
        get: operations["listOfferVersions"];
        put?: never;
        /**
         * Add a draft version with new terms
         * @description §12 as an endpoint: a change of price, quota or features **creates a version rather than rewriting history**. There is deliberately no way to edit a published version's price, so this is the only way to change what an offer costs.
         *
         *     The version number is not in the body — it is `max + 1`, assigned by the statement that writes the row, so two authors working at once get 4 and 5 rather than a collision.
         */
        post: operations["addOfferVersion"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/payments/{paymentId}/retry": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Try to collect an invoice again
         * @description A **new** payment against the same invoice, never a resurrection of the old one. `PaymentStatus` is deliberately one-way and says why: the customer may have used a different instrument, and two attempts that must be told apart cannot share a provider reference.
         *
         *     Refused while the previous attempt is still in flight — a second authorization then risks collecting twice for one debt — and refused if it succeeded, where there is nothing to retry.
         */
        post: operations["retryPayment"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/plans": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The plans this product sells
         * @description Ordered by `rank`, which is what an upgrade compares. Nothing in the platform branches on a plan's *name* (§13, and a CI gate says so).
         */
        get: operations["listPlans"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/products": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Products this caller may use
         * @description Runs before a product is resolved, and must: a client cannot send `X-Product` until it knows which products it may use, so requiring one here would make discovery depend on its own result. Authorisation is per product, from membership.
         */
        get: operations["listProducts"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/products/{productId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One product
         * @description Named in the path rather than the header, because this is discovery: the caller is asking about a product, not acting inside one.
         */
        get: operations["showProduct"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/products/{productId}/catalog": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * A product's features and configuration together
         * @description One round trip for what a client needs to render a product before choosing it.
         */
        get: operations["showProductCatalogue"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/products/{productId}/configuration": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * A product's settings
         * @description An object even when empty, so a client never has to tell `{}` from `null`.
         */
        get: operations["showProductConfiguration"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/products/{productId}/features": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What a product offers
         * @description The catalogue of features a product defines, independent of what any tenant has bought.
         */
        get: operations["listProductFeatures"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's projects
         * @description Summaries without documents — a list that carried every JSONB body would be a list nobody could load.
         */
        get: operations["listProjects"];
        put?: never;
        /**
         * Create a project
         * @description Counts against the tenant's project quota, which is checked before the write rather than apologised for after it.
         */
        post: operations["createProject"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects/{projectId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** One project, with its document */
        get: operations["showProject"];
        put?: never;
        post?: never;
        /**
         * Delete a project
         * @description Recoverable since R13. The project leaves every list and keeps everything: its versions, its assets and the jobs that referred to it. `POST /projects/{projectId}/undelete` puts it back.
         *
         *     This was a hard delete, with `project_versions` following through ON DELETE CASCADE — a project with fifty snapshots left nothing behind, and nothing said so until it was gone.
         */
        delete: operations["deleteProject"];
        options?: never;
        head?: never;
        /**
         * Change a project
         * @description Partial: an absent field is untouched. Saving does not create a version — versioning is explicit, so an autosave cannot bury the snapshot somebody meant to keep.
         */
        patch: operations["updateProject"];
        trace?: never;
    };
    "/api/v1/projects/{projectId}/assets": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** A project's files */
        get: operations["listAssets"];
        put?: never;
        /**
         * Upload a file
         * @description The body is the raw bytes and `X-Filename` names it. The stored content type is **sniffed**, never the request's claim — a client that says image/png about a script is not believed, and what is stored is what was sniffed.
         */
        post: operations["uploadAsset"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects/{projectId}/duplicate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Copy a project
         * @description A new project with its own identity and history. The copy counts against the quota like any other.
         */
        post: operations["duplicateProject"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects/{projectId}/exports": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Export a project
         * @description 202 with a job: a long export must never block the request (M7's exit criterion). Poll the job for its result.
         */
        post: operations["requestExport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects/{projectId}/restore": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Restore a saved version over the project
         * @description The current document is replaced. Snapshot first if it matters — restoring does not version what it overwrites, because a restore that silently created a version would make the history a record of undo rather than of intent.
         */
        post: operations["restoreProject"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects/{projectId}/undelete": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Put a deleted project back
         * @description Named `undelete` rather than `restore` because `restoreProject` already exists and restores a project *to one of its versions* — a different operation that shares a word. The project returns with everything that never stopped pointing at it: its versions, its assets, its jobs.
         *
         *     A project that is not deleted answers 404, exactly as one that never existed does. Undeleting a live project is not a thing, and a 409 would confirm that an id is real to somebody guessing at ids.
         */
        post: operations["undeleteProject"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects/{projectId}/versions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * A project's saved versions
         * @description Summaries without documents, newest first.
         */
        get: operations["listProjectVersions"];
        put?: never;
        /**
         * Snapshot the project as it is now
         * @description Explicit, and numbered consecutively per project. A label is optional and is for people, never for identity.
         */
        post: operations["createProjectVersion"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/projects/{projectId}/versions/{versionId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One saved version, with its document
         * @description What comes back is byte-for-byte what was stored — key order preserved, whitespace gone. That is the guarantee restore depends on.
         */
        get: operations["showProjectVersion"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/orders": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's orders
         * @description Newest first, paginated.
         */
        get: operations["listOrders"];
        put?: never;
        /**
         * Order an offer directly
         * @description Without a quote. The resulting order carries a null `quote_id`, which is how the two routes stay distinguishable afterwards.
         */
        post: operations["placeOrder"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/orders/{orderId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One order
         * @description Read `invoice_id` and `subscription_id` together to see how far through the gate it is.
         */
        get: operations["showOrder"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/orders/{orderId}/cancel": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Cancel an order
         * @description Before it completes. An order whose invoice is already paid cannot be cancelled here — that is a refund and a credit note, which are different documents with different consequences.
         */
        post: operations["cancelOrder"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/orders/{orderId}/fulfil": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Invoice the order
         * @description Fulfilment raises the invoice and **stops**. The subscription is created when that invoice is paid, not here — provisioning before the money arrives is the failure this split exists to prevent (ADR-024). The order moves to AWAITING_PAYMENT.
         */
        post: operations["fulfilOrder"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/quotes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's quotes
         * @description Newest first, paginated.
         */
        get: operations["listQuotes"];
        put?: never;
        /**
         * Quote an offer
         * @description Priced from the offer version on sale now and pinned to it, so a catalogue change cannot reprice a quote already sent. `validity_days` is clamped to the platform's maximum — a quote open for ever is a price open for ever. Raised for business customers only: a tenant whose tax profile says `B2C` is refused with `QUOTE_REQUIRES_BUSINESS_CUSTOMER` and buys at the listed price instead. Declaring the organisation a business in its tax profile is what makes it quotable.
         */
        post: operations["createQuote"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/quotes/{quoteId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One quote
         * @description With its lines and the moment it stops being acceptable.
         */
        get: operations["showQuote"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/quotes/{quoteId}/accept": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Accept a quote, creating an order
         * @description 201, because an order is created. Accepting an expired quote is refused rather than honoured — the clock decides, not the status column, so a quote that lapsed a second ago is already closed.
         */
        post: operations["acceptQuote"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/sales/quotes/{quoteId}/reject": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Decline a quote
         * @description Recorded rather than deleted: a quote that was refused is a commercial fact, and the date of the refusal is part of it.
         */
        post: operations["rejectQuote"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/access-log": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What staff have read
         * @description The evidence for #21. Append-only, and readable by staff who hold the permission for it.
         */
        get: operations["listAccessLog"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/conversations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Support threads across tenants
         * @description **Only SUPPORT threads.** A tenant's internal conversations are excluded by the query itself, not by remembering to filter them.
         */
        get: operations["listSupportConversations"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/conversations/{conversationId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One support thread with its messages
         * @description Requires a motive (R14). A conversation’s tenant appears on this detail and not on the list, so this is where the boundary is actually crossed.
         *
         *     Carries the tenant and product it belongs to, because a staff surface resolves neither of its own. Every read is recorded.
         */
        get: operations["showSupportConversation"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/conversations/{conversationId}/close": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Close a support thread */
        post: operations["closeSupportConversation"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/conversations/{conversationId}/messages": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Reply as support
         * @description Recorded as a write in the access log, and authored as STAFF — which the message's own foreign key enforces.
         */
        post: operations["postSupportMessage"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/members": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Who holds platform authority
         * @description Unpaged: platform staff is a handful of people, and a roster needing pages would itself be the finding. Behind `staff.grant`, which PLATFORM_ADMIN alone holds.
         */
        get: operations["listPlatformStaff"];
        put?: never;
        /**
         * Appoint a staff member
         * @description Takes a user id, not an email: resolving an address here would turn this into a way to ask whether an account exists for any address anybody tried. Idempotent — granting a role already held is the state asked for.
         */
        post: operations["grantPlatformRole"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/members/{userId}/roles/{role}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Remove a staff member's role
         * @description Two refusals answer 409 and they are not the same: removing your own administrator role (ask a colleague) and removing the platform's last one (appoint somebody first). The second is the database's, not this service's.
         */
        delete: operations["revokePlatformRole"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The caller's platform role
         * @description A different identity model from a tenant membership: no membership grants these, and a tenant admin is refused outright.
         */
        get: operations["showStaffIdentity"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/tenants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Every tenant
         * @description The one listing that crosses the boundary by design. Reading it is recorded (#21).
         */
        get: operations["listTenantsForStaff"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/tenants/{tenantId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One tenant, as staff
         * @description Requires a motive (R14): opening one customer reveals that customer’s data. Listing customers does not, and requires none — a platform that demanded a ticket reference to page through a list would teach its staff to type "support" into everything.
         *
         *     The tenant arrives as an explicit parameter rather than from a membership — there is none — so the handler must justify it, and the read is written to the access log in the same transaction.
         */
        get: operations["showTenantForStaff"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/tenants/{tenantId}/offer-authoring": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Lend the catalogue to a tenant, or take it back
         * @description PUT because it states a desired state rather than an act: sending the same value twice is the state asked for both times. No motive header, unlike the tenant read beside it — this reveals no customer data, it changes what a customer may do, and the trail records who decided.
         */
        put: operations["setTenantOfferAuthoring"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/tenants/{tenantId}/products/{productId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Give a tenant a product
         * @description PUT with no body: the address states the desired state — this tenant holds this product — and a second identical request is the same state. Every current member of the tenant becomes a member of the product, with the roles they hold in the organisation (ADR-047). No motive header, like the delegation beside it: nothing of the customer’s is revealed, and the trail records who decided. `staff.tenants.manage`.
         */
        put: operations["assignTenantProduct"];
        post?: never;
        /**
         * Take a product back from a tenant
         * @description The memberships in that product go with it; the records keyed on it — projects, invoices, conversations — stay, because a record of what happened is not access to it. 200 with the tenant rather than 204, and 200 for a product the tenant never held: that is the state that was asked for.
         */
        delete: operations["unassignTenantProduct"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/tenants/{tenantId}/members": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Who belongs to a tenant, read-only
         * @description Every member of the tenant once, with their roles and the products they are on — or only those on one product when `product` names it. Read-only by construction: a platform role never grants or edits a membership (non-negotiable #22); it may see them, with a reason, on the record (R14). Requires `staff.tenants.read`.
         */
        get: operations["listTenantMembersForStaff"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/subscription": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The current subscription, its history and its events
         * @description Three answers in one: what is in force now, everything that came before, and the transitions between them. `subscription` is null when the tenant has never subscribed — not the same as a cancelled one, and history tells them apart.
         */
        get: operations["showSubscription"];
        put?: never;
        /**
         * Take out a subscription
         * @description `seat: true` subscribes the caller personally rather than the tenant. A tenant may hold one active subscription and a person one active seat — both are unique indexes, so two simultaneous requests cannot produce two.
         */
        post: operations["subscribe"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/subscription/cancel": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Cancel, or say why it cannot be cancelled
         * @description Returns the subscription **and the decision**. A refusal is a 200 with `accepted: false` and the rule that refused it, not an error: the request was understood and answered, and the customer needs to know which term binds them. Where leaving early costs money, the buy-out invoice is raised in the same transaction and named here.
         */
        post: operations["cancelSubscription"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/subscription/change-offer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Move to a different offer
         * @description The subscription keeps its identity and its history; only what it grants changes. An upgrade compares the plans' ranks, never their names (§13).
         */
        post: operations["changeOffer"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/subscription/resume": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Undo a scheduled cancellation
         * @description Only before it takes effect. Resuming clears the effective date as well as the flag — a subscription claiming to end on a date it no longer honours is refused by the database.
         */
        post: operations["resumeSubscription"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/subscription/schedule": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What cancelling now would do
         * @description The same decision the cancel endpoint would return, without performing it — so a client can show the consequence before the customer commits to it.
         */
        get: operations["showSchedule"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tax/calculate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * What VAT would apply, and why
         * @description A dry run against the tenant's tax profile. Returns the rule that decided, the regime, the legal mention where one is required, and the reasons in plain words — everything a quote needs to show before anything is invoiced.
         */
        post: operations["calculateTax"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tax/profile": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's tax position
         * @description What they claim, what was proved, and whether that adds up to a reverse charge.
         */
        get: operations["showTaxProfile"];
        /**
         * Set the tax position, and verify the VAT number
         * @description Saving a VAT number checks it against the provider — but only when there is no evidence or the evidence is stale, so repeated saves do not spend the provider's rate limit to learn nothing.
         *
         *     When it does not prove out, the person who entered it is told (R8): they typed a number expecting to be zero-rated and will otherwise discover the standard rate on an invoice, which is a legal document that cannot then be quietly recomputed.
         */
        put: operations["saveTaxProfile"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tax/rates": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The rates in force on a date
         * @description `?on=` asks about a past date, which is what pricing a back-dated document needs. The clock picks the rate, never the current value of a row.
         */
        get: operations["listTaxRates"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tax/reports": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The declaration periods
         * @description Open and closed alike. A closed one cannot be reopened.
         */
        get: operations["listVatPeriods"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tax/reports/{periodId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One period, with its totals
         * @description An open period is totalled from the transactions as they stand now. A closed one reports **what was declared** instead — recomputing it would answer a different question than the one that was filed.
         */
        get: operations["showVatPeriod"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tax/reports/{periodId}/close": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Close a period and freeze its declaration
         * @description One-way, and the database enforces it rather than the service remembering to. A period holding more than one currency is refused: summing across currencies would produce a figure that is not money.
         */
        post: operations["closeVatPeriod"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tax/transactions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The fiscal facts behind the documents
         * @description One row per taxed line of a document, with the rule that produced it. Paginated.
         */
        get: operations["listVatTransactions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tenant/skin": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant's colours and logo
         * @description Needs nothing but membership. A client has to know how to render itself before it knows what the tenant bought, and a tenant that has never set a skin gets one with every field null rather than a 404 — which would make every client write a branch to mean what null already means.
         */
        get: operations["showSkin"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Set or clear the colours
         * @description A PATCH, so a field the body does not mention keeps its value. **Omitting a field and sending it as `null` are different instructions**: the first leaves it alone, the second clears it — so a caller who wants to drop one colour without knowing the other can say so.
         *
         *     The logo is not settable here; it is bytes, and it has its own endpoint.
         */
        patch: operations["updateSkin"];
        trace?: never;
    };
    "/api/v1/tenant/skin/logo": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Upload a logo
         * @description **The raw bytes are the request**, as with an asset upload: a base64 field inside JSON would inflate the payload by a third and would put an image inside a document this platform elsewhere refuses to let images into (non-negotiable #9).
         *
         *     The type is sniffed from the bytes and checked **before anything is stored**, so a refused logo leaves no file behind and no orphaned row. Only PNG, JPEG, GIF and WebP are accepted — SVG is refused here as everywhere, being an image to a user and a script container to a browser.
         *
         *     `X-Filename` is a label for humans and decides nothing.
         */
        post: operations["uploadSkinLogo"];
        /**
         * Stop using the logo
         * @description Clears it from the skin. **The asset itself stays**: this says "this is no longer our logo", which is not the same instruction as "destroy this file" — the file may be on a page somebody has open or inside a document already generated, and `/assets` is where a file is deleted deliberately.
         *
         *     200 with the skin rather than 204, because the caller asked for a change and the useful answer is what the skin now is.
         */
        delete: operations["deleteSkinLogo"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tenants/current": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The tenant being acted inside
         * @description Resolved from membership, never from what the client asked for.
         */
        get: operations["showCurrentTenant"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Rename the tenant
         * @description Partial.
         */
        patch: operations["updateCurrentTenant"];
        trace?: never;
    };
    "/api/v1/tenants/current/members": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Who belongs to this tenant */
        get: operations["listMembers"];
        put?: never;
        /**
         * Add somebody to the tenant
         * @description By email. Counts against the tenant's seat quota where one is configured.
         */
        post: operations["addMember"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/tenants/current/members/{userId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /** Remove somebody from the tenant */
        delete: operations["removeMember"];
        options?: never;
        head?: never;
        /**
         * Change somebody's roles
         * @description Replaces the set; roles are not merged.
         */
        patch: operations["updateMember"];
        trace?: never;
    };
    "/api/v1/tenants/current/usage": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * How much of each quota is used
         * @description Unmetered says unmetered. An offer can grant a quota nothing counts yet, and reporting that as "0 used" would tell a customer their limit is being enforced when nothing is enforcing it.
         */
        get: operations["showTenantUsage"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/webhooks/einvoice/{provider}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * An e-invoicing platform reporting an outcome
         * @description Reachable with no credential, because the sender is a provider rather than a person — so the **request** authenticates itself: the signature is verified over the raw body before a single field is read. Nothing may be mounted under this prefix that does not.
         *
         *     Delivery is exactly-once by unique index on (provider, event id), not by a check two concurrent deliveries would both pass.
         */
        post: operations["einvoiceWebhook"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/webhooks/payments/{provider}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * A payment provider reporting an outcome
         * @description Reachable with no credential, because the sender is a provider rather than a person — so the **request** authenticates itself: the signature is verified over the raw body before a single field is read. Nothing may be mounted under this prefix that does not.
         *
         *     Delivery is exactly-once by unique index on (provider, event id), not by a check two concurrent deliveries would both pass.
         */
        post: operations["paymentWebhook"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/public/offers": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The shop window for one product
         * @description Unauthenticated. Returns only offers the platform has marked `publicly_listed` **and** that are inside their sale window. `product` comes back null whenever there is nothing to show — whether the code names no product, an inactive one, or one that advertises nothing — so this cannot be used to tell an unknown product from one with nothing advertised. Which products *do* advertise something is `listPublicProducts`’s answer.
         */
        get: operations["getPublicOffers"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/public/offers/{offerId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * One advertised offer, to a stranger
         * @description Unauthenticated. What a shared link opens. Four situations answer 404 identically — no such offer, another product’s, not advertised, not on sale today — so ids cannot be tested against the private catalogue.
         */
        get: operations["getPublicOffer"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/public/products": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The shop windows a stranger may choose between
         * @description Unauthenticated. Only products that advertise a sellable offer right now — the set a person could already assemble by trying `?product=` codes one at a time — so the list reveals nothing the windows do not. A product with nothing on sale is absent; what the platform *runs* stays private (ADR-047, amending ADR-041). In code order.
         */
        get: operations["listPublicProducts"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/storefront/offers": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Every offer of a product, advertised or not
         * @description The unfiltered view behind `staff.catalog.manage`: deciding what a stranger sees means seeing what is currently hidden, drafts included.
         */
        get: operations["listStorefrontOffers"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/storefront/offers/{offerId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Advertise an offer publicly, or stop
         * @description PUT because it states a desired state: sending the same value twice is the state asked for both times. Withdrawing an offer from the public page does not withdraw it from sale — subscribers keep their terms and members keep seeing it in the catalogue. Recorded in the staff access trail as ADVERTISE or WITHDRAW_ADVERTISING.
         */
        put: operations["setOfferPublicListing"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/sign-up": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Create an account, an organisation and a session
         * @description Public. The only endpoint that creates an account from nothing — user, credential, tenant, TENANT_ADMIN membership of the named product — in one transaction. `organisation` is optional: somebody buying for themselves has no company and the tenant takes their own name instead, which nothing downstream branches on. The address is **not** verified first: a session is issued immediately and `users.email_verified_at` stays null until the confirmation link is followed. Unlike signing in, this says plainly when an address is taken — a person who cannot be told cannot finish the purchase they came for — and the public rate limit is what bounds the enumeration that permits. A minimal billing profile is created alongside the tenant — a checkout refuses an order it cannot invoice, and an account that could not buy anything would make the storefront’s promise false.
         */
        post: operations["signUp"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/verify-email": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Confirm an address from the emailed token
         * @description Public, because the person following the link may be on a device that has never signed in. It never issues a session: a link that did would be a credential living in an inbox for as long as that mail is kept. Unknown, expired and already-used are one answer, so tokens cannot be probed.
         */
        post: operations["verifyEmail"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/products": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Every product on the platform
         * @description The question `listProducts` cannot answer: that one resolves through membership, and a platform role never grants membership, so an administrator asking it what this deployment hosts is answered about their own tenant or not at all. Behind `staff.products.manage`. Retired products are included — they still carry tenants, subscriptions and invoices.
         */
        get: operations["listPlatformProducts"];
        put?: never;
        /**
         * Create a product
         * @description Until this existed, the installer was the only thing on the platform that could create one — once, at install — so a second product meant an INSERT typed against production. The code is chosen here and never again.
         */
        post: operations["createProduct"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/products/{productId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Rename a product, or retire it
         * @description PATCH rather than PUT because the two fields are independent: renaming says nothing about whether a product is active, and a PUT would make a form restate it — which is how a product gets switched off by a checkbox somebody forgot to send. **There is no delete.** A product carries tenants, subscriptions and invoices, and an invoice is a legal document; `active: false` closes every door into it and leaves the history where the law requires it. Recorded in the staff access trail as RENAME, RETIRE or REINSTATE.
         */
        patch: operations["updateProduct"];
        trace?: never;
    };
    "/api/v1/staff/demo/reset": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Empty every business table and seed the demonstration world afresh
         * @description The widest destructive act on this platform. Behind `staff.demo.reset`, which PLATFORM_ADMIN alone holds; refused with `NOT_A_DEMO_DEPLOYMENT` while a product that is not the demonstration's exists, whatever the caller says — those are somebody's real products with legal documents under them. Reference data (permissions, roles, VAT rates) is untouched. Everybody is signed out by it, the caller included: their token names a `users` row that no longer exists. The answer says who to sign in as. No body.
         */
        post: operations["resetDemoWorld"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/catalogue": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The plans and features an offer is built out of
         * @description Both in one read, because an offer needs both and a screen that fetched them separately would render half a form. The offers themselves come from `listStorefrontOffers`, which already answers the unfiltered authoring view.
         */
        get: operations["showStaffCatalogue"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/catalogue/plans": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Create a plan
         * @description The first thing a catalogue needs, and the thing nothing on this platform could create: `INSERT INTO plans` appeared once in the whole repository, in the demo seeder. Without a plan, `createOffer` inserts `SELECT … FROM plans WHERE id = :planId` and matches nothing — so an installation had a product and no way to price it. **`rank` is chosen, not derived**: it is the only ordering this platform has, and an upgrade is a comparison of two integers, never of two names.
         */
        post: operations["createPlan"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/catalogue/plans/{planId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Rename a plan, or move it
         * @description PATCH because the fields are independent, and reordering is the one that matters: which plan sits above which is what an upgrade is measured by, so a rename must not move a plan by omission. There is no delete — offers point at plans by id, and those offers price live subscriptions. Recorded as RENAME or REORDER.
         */
        patch: operations["updatePlan"];
        trace?: never;
    };
    "/api/v1/staff/catalogue/features": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Create a billable capability
         * @description `kind` is chosen here because it can never change: every grant written against a feature meant one kind or the other — a quota’s carries a limit, a boolean’s carries null — and flipping it would reinterpret rows already priced into live subscriptions. A feature that should have been the other kind is a new feature. `unit` is what a quota is counted in and is refused on a boolean.
         */
        post: operations["createFeature"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/catalogue/features/{featureId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Correct a feature’s name
         * @description The only thing about a feature that may change. Its code is how grants and entitlements name it; its kind decides how every grant already written against it is read.
         */
        patch: operations["renameFeature"];
        trace?: never;
    };
    "/api/v1/staff/catalogue/offers": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * The platform prices its own product
         * @description The same act as `createOffer`, from the other side of the boundary. That one lives on the tenant shell behind `catalog.manage`, which ADR-040 made a delegation — so after ADR-040 the platform could price its own catalogue only by lending it to a tenant and acting as that tenant. Born DRAFT: publishing is a separate, deliberate act. The body is the same one the tenant route reads, so a term added to §13.1 cannot be accepted by one and dropped by the other.
         */
        post: operations["createStaffOffer"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/catalogue/offers/{offerId}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Correct an offer’s name
         * @description The code is untouched — documents refer to this offer by it. The price is not here either: a price is a *version*, and editing a published one is what ADR-033 forbids.
         */
        patch: operations["renameStaffOffer"];
        trace?: never;
    };
    "/api/v1/staff/catalogue/offers/{offerId}/versions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Draft the next price
         * @description A published version is frozen (ADR-033), so changing what an offer costs is always a new version rather than an edit. Numbered by the database, not the caller: two authors adding one at once must not both get number 4. DRAFT until published, so the price on sale does not move the moment a new one is written down.
         */
        post: operations["createStaffOfferVersion"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/catalogue/offers/{offerId}/publish": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Put a price on sale
         * @description The loudest act on this shell. From here on every quote, order and subscription written against this offer prices from that version, and ADR-033 freezes it the moment it happens. The database refuses two versions on sale across the same window, which is why this can answer 409 rather than leaving two prices for one offer.
         */
        post: operations["publishStaffOfferVersion"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/configuration": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What a product needs configured before it can take money
         * @description ADR-042 gave the console a way to create a product and ADR-043 a way to price it, and a checkout against one created that way still refused with `BILLING_NOT_CONFIGURED`: an invoice must name its issuer, and the issuer lives in `product_configuration` — a table only the demo seeder ever wrote. Both keys come back in one read, because the two are one decision: the supplier's country is the fallback for the tax jurisdiction, so a screen fetching them separately could show a regime that contradicted the issuer. `can_invoice` is computed from the same rule the invoice path applies, so the console cannot say ready where a checkout would refuse.
         */
        get: operations["showStaffConfiguration"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/configuration/billing-identity": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Set who this product's invoices say is issuing them
         * @description **PUT rather than PATCH**, unlike every other write in this console. The mandatory mentions are one document: a supplier that stops being liable for VAT has to be able to *remove* its VAT number, and under "omitted means leave it" removing anything would be impossible. Sending the whole identity makes clearing a field the same act as changing one.
         *
         *     An incomplete identity is **refused rather than stored**. The console exists to make invoicing possible, and saving something that cannot invoice while answering 200 is how a broken form looks like a working one — the failure would surface later, to a customer, at the checkout. Recorded in `staff_access_log` as `CONFIGURE_BILLING`, because an invoice carries a snapshot of this taken when it was raised.
         */
        put: operations["setBillingIdentity"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/configuration/tax": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Set the supplier's own fiscal position
         * @description §25.3 keeps everything qualifying the supplier fiscally a human decision, configured and never derived. **Every field is required**: the reader of this key defaults what it cannot find, which is right for a document written before a field existed and wrong for a form — a screen omitting `oss_registered` would silently switch the OSS regime off. Recorded in `staff_access_log` as `CONFIGURE_TAX` in full, because all four decide how a cross-border sale is taxed and getting one wrong is a VAT return filed in the wrong country.
         */
        put: operations["setTaxSettings"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/staff/readiness": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * What a product still needs before a stranger can buy from it
         * @description A fresh installation used to be navigable only by walking into its refusals: no plan, so `createOffer` matches nothing; a published version and an empty shop window, because advertising is a separate decision (ADR-041); and a checkout that gets all the way through refusing with `BILLING_NOT_CONFIGURED` because an invoice must name its issuer (ADR-044). Every refusal is correct and together they are a maze.
         *
         *     Every fact here is read through the same port the enforcing code reads — `SupplierDetails` for the issuer, the catalogue repository for plans and offers, **the clock** for sellability — so this cannot report ready where a sale would refuse. `published` in particular asks whether a version is sellable *now*, not whether a status column says ACTIVE: a version can be ACTIVE and outside its window.
         */
        get: operations["showProductReadiness"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        /** @description The §10.4 envelope. Every failure has this shape, whatever produced it. */
        Error: {
            error: {
                /**
                 * @description Stable and machine-readable. Branch on this, never on the message.
                 * @example PERMISSION_DENIED
                 */
                code: string;
                /** @description For a person. Wording may change without notice. */
                message: string;
                /** @description What the code could not say alone — the field at fault, the limit exceeded. Empty rather than absent. */
                details: {
                    [key: string]: unknown;
                };
                /** @description Quote this when reporting a problem; the server log is keyed by it. */
                request_id: string;
            };
        };
        /** @description Integer minor units and the currency. Never a float — a cent lost to binary rounding is a cent an auditor will ask about. The field is `minor_units` because the key this object sits under (`price`, `net`, `vat`, `gross`, `amount`) already says which amount it is; where money appears without such a key it is named in full instead, as `amount_minor_units` beside its own `currency`. */
        Money: {
            /**
             * @description Cents, not euros.
             * @example 2900
             */
            minor_units: number;
            /**
             * @description ISO 4217.
             * @example EUR
             */
            currency: string;
        };
        /** @description `active` is deliberately absent: every product a caller can see is active, so the field would always be true and would only invite clients to branch on it. */
        Product: {
            /** Format: uuid */
            id: string;
            /** @example atlas */
            code: string;
            /** @example Atlas */
            name: string;
        };
        ProductFeature: {
            code: string;
            name: string;
            enabled: boolean;
        };
        /** @description Free-form product settings. An object even when empty, so a client never has to tell `{}` from `null`. */
        ProductConfiguration: {
            [key: string]: unknown;
        };
        Plan: {
            /** Format: uuid */
            id: string;
            /** @example PRO */
            code: string;
            name: string;
            /** @description Orders plans against each other. An upgrade is a comparison of two ranks, never of two names (§13). */
            rank: number;
        };
        Feature: {
            /** Format: uuid */
            id: string;
            code: string;
            name: string;
            /** @enum {string} */
            kind: "BOOLEAN" | "QUOTA";
            /** @description Only a quota has one; the database refuses a unit on a boolean. */
            unit: string | null;
        };
        /** @description What one offer version gives. A quota with no limit is `unlimited`, which is not the same as a limit of zero. */
        OfferGrant: {
            feature: string;
            name: string;
            /** @enum {string} */
            kind: "BOOLEAN" | "QUOTA";
            unit: string | null;
            limit: number | null;
            unlimited: boolean;
        };
        /** @description What one version of an offer grants, as its author sees it: the sale shape plus the feature's id. */
        AuthoredOfferGrant: components["schemas"]["OfferGrant"] & {
            /**
             * Format: uuid
             * @description The feature this grant is against. Present in the authoring view and absent from the sale view: ADR-033 freezes a published version, so changing what an offer grants means writing a new version from the old one — which needs the ids, not only the codes. A shop window read by strangers does not owe them the internal key of a catalogue row.
             */
            feature_id: string;
        };
        OfferVersion: {
            /** Format: uuid */
            id: string;
            version: number;
            /**
             * @description The same three the database allows. `ONE_OFF` was listed here and nowhere else — no migration, no domain code and no CHECK constraint has ever accepted it, so it promised a value the platform could not produce and a client that handled it was writing dead code.
             * @enum {string}
             */
            billing_period: "MONTHLY" | "YEARLY" | "CUSTOM";
            price: components["schemas"]["Money"];
            /**
             * Format: date-time
             * @description The window in which this version may be *sold* — not a subscription period, which §12 keeps separate.
             */
            valid_from: string;
            /** Format: date-time */
            valid_until: string | null;
            grants: components["schemas"]["OfferGrant"][];
        };
        Offer: {
            /** Format: uuid */
            id: string;
            code: string;
            name: string;
            plan: components["schemas"]["Plan"];
            /** @description Null only in principle — an offer is presented once a sellable version exists — but typed honestly rather than asserted away. */
            version: components["schemas"]["OfferVersion"] | null;
        };
        /** @description Who is bound. A seat names the person; a tenant subscription does not. */
        Subscriber: {
            /** @enum {string} */
            kind: "TENANT" | "USER";
            /** Format: uuid */
            user_id: string | null;
        };
        /** @description What a subscription commits to (§13.1). The distinction this exists to hold is the one that costs the most to lose: **the payment period is not the commitment**. A 24-month subscription billed monthly is one 24-month commitment billed 24 times, not 24 one-month subscriptions in a row. */
        SubscriptionTerms: {
            /** @description How long the contract runs. Null is open-ended. */
            term_months: number | null;
            /** @description Never more than `term_months`; a commitment longer than the contract is not a commitment. */
            commitment_months: number;
            /** @enum {string} */
            cancellation_policy: "ANYTIME" | "AT_COMMITMENT_END" | "AT_TERM";
            /** @enum {string} */
            renewal: "AUTO_RENEW" | "ENDS_AT_TERM";
            /** @enum {string} */
            early_termination: "FORBIDDEN" | "CHARGE_REMAINING" | "FREE";
            notice_days: number;
        };
        Subscription: {
            /** Format: uuid */
            id: string;
            /** @enum {string} */
            status: "ACTIVE" | "CANCELLED" | "EXPIRED";
            offer: {
                /** Format: uuid */
                id: string;
                code: string;
                name: string;
                plan: components["schemas"]["Plan"];
                version: components["schemas"]["OfferVersion"];
            };
            /** Format: date-time */
            started_at: string;
            /**
             * Format: date-time
             * @description The *subscription's* period, which §12 keeps distinct from the offer's commercial window.
             */
            current_period_start: string;
            /**
             * Format: date-time
             * @description Null for a CUSTOM billing period, which has no end to be past.
             */
            current_period_end: string | null;
            cancel_at_period_end: boolean;
            /** Format: date-time */
            cancel_effective_at: string | null;
            /** Format: date-time */
            cancelled_at: string | null;
            subscriber: components["schemas"]["Subscriber"];
            terms: components["schemas"]["SubscriptionTerms"];
            /** Format: date-time */
            ended_at: string | null;
        };
        /** @description Why the answer is what it is. What a customer needs is not `cancelled: true` but *when* it takes effect and which rule decided that (§13.1) — so the rule's id travels with the decision and the reasons are in plain words. */
        CancellationDecision: {
            accepted: boolean;
            /**
             * @description Which rule decided. Stable, and the thing to quote in a support conversation.
             * @example cancel.at_commitment_end
             */
            rule_id: string;
            /** @enum {string} */
            effect: "IMMEDIATE" | "AT_PERIOD_END" | "AT_COMMITMENT_END" | "AT_TERM" | "REFUSED";
            /** Format: date-time */
            effective_at: string | null;
            /** @description Months of the commitment still owed. Counted from the end of the period already paid for, not from today. */
            chargeable_months: number;
            reasons: string[];
        };
        /** @description The history (§18). Every transition writes one, including those nobody performed — an expiry has no actor because the clock did it. */
        SubscriptionEvent: {
            /** Format: uuid */
            id: string;
            /**
             * @example SUBSCRIBED
             * @example OFFER_CHANGED
             * @example CANCELLATION_SCHEDULED
             * @example RESUMED
             * @example RENEWED
             * @example EXPIRED
             */
            type: string;
            /** Format: uuid */
            from_offer_version_id: string | null;
            /** Format: uuid */
            to_offer_version_id: string | null;
            /**
             * Format: uuid
             * @description Null when the clock did it rather than a person.
             */
            actor_user_id: string | null;
            detail: {
                [key: string]: unknown;
            };
            /** Format: date-time */
            occurred_at: string;
        };
        Entitlement: {
            feature: string;
            name: string;
            /** @enum {string} */
            kind: "BOOLEAN" | "QUOTA";
            unit: string | null;
            /** @description Null means two different things, so `unlimited` says which. */
            limit: number | null;
            unlimited: boolean;
            /** @description What produced it — a subscription grant, or an override negotiated outside one. */
            source: string;
            /** Format: date-time */
            valid_until: string | null;
        };
        /** @description Supplier or customer **as they were when the document was issued**, copied rather than referenced (§25). A later change of address must not rewrite an invoice already sent, and an RGPD erasure deliberately leaves this standing. */
        PartySnapshot: {
            [key: string]: unknown;
        };
        InvoiceLine: {
            position: number;
            description: string;
            quantity: number;
            unit_price: components["schemas"]["Money"];
            discount: components["schemas"]["Money"];
            net: components["schemas"]["Money"];
            /**
             * @description Basis points, as stored. Sending "20%" would lose 5.5% to whatever the client's parser did with the half.
             * @example 2000
             * @example 550
             */
            vat_rate_basis_points: number;
            vat: components["schemas"]["Money"];
            gross: components["schemas"]["Money"];
            /**
             * Format: uuid
             * @description Which offer version priced this line. Attribution runs through here, never through the subscription's *current* offer, which would credit today's offer with money an older one earned.
             */
            source_offer_version_id: string | null;
        };
        TaxRecord: {
            jurisdiction: string;
            rate_basis_points: number;
            taxable: components["schemas"]["Money"];
            tax: components["schemas"]["Money"];
        };
        Invoice: {
            /** Format: uuid */
            id: string;
            /** @description Null until issued. A draft has no legal number, and inventing a placeholder is how a gap enters a sequence that must not have one. */
            number: string | null;
            /** @enum {string} */
            status: "DRAFT" | "ISSUED" | "PAID" | "CANCELLED";
            /** @description Whether it can still change. A final invoice is corrected by a credit note, never edited. */
            final: boolean;
            /** Format: uuid */
            subscription_id: string | null;
            net: components["schemas"]["Money"];
            vat: components["schemas"]["Money"];
            gross: components["schemas"]["Money"];
            /** Format: date-time */
            issued_at: string | null;
            /** Format: date-time */
            due_at: string | null;
            /** Format: date-time */
            paid_at: string | null;
            /** Format: date-time */
            period_start: string | null;
            /** Format: date-time */
            period_end: string | null;
            payment_terms: string | null;
            supplier: components["schemas"]["PartySnapshot"];
            customer: components["schemas"]["PartySnapshot"];
            lines: components["schemas"]["InvoiceLine"][];
            /** @description One row per jurisdiction and rate. They sum to the invoice's VAT — a test asserts it. */
            taxes: components["schemas"]["TaxRecord"][];
        };
        /** @description How a final invoice is corrected. Its own gapless sequence, separate from the invoices'. */
        CreditNote: {
            /** Format: uuid */
            id: string;
            number: string;
            /** Format: uuid */
            invoice_id: string;
            /**
             * @description Always CREDIT. Present so a client reading a mixed ledger never has to infer the sign.
             * @constant
             */
            direction: "CREDIT";
            reason: string | null;
            net: components["schemas"]["Money"];
            vat: components["schemas"]["Money"];
            gross: components["schemas"]["Money"];
            /** Format: date-time */
            issued_at: string;
            supplier: components["schemas"]["PartySnapshot"];
            customer: components["schemas"]["PartySnapshot"];
            lines: components["schemas"]["InvoiceLine"][];
        };
        Payment: {
            /** Format: uuid */
            id: string;
            /** Format: uuid */
            invoice_id: string | null;
            /** Format: uuid */
            subscription_id: string | null;
            /** @description Which PSP. The domain never names one; this is the adapter that was used. */
            provider: string;
            provider_payment_id: string | null;
            /** @enum {string} */
            status: "PENDING" | "SUCCEEDED" | "FAILED" | "REFUNDED" | "CHARGED_BACK";
            settled: boolean;
            /** @description Whether the status can still move. A webhook arriving after this is ignored rather than applied twice. */
            final: boolean;
            amount: components["schemas"]["Money"];
            /** @description How it was paid. Never card data — §24 forbids storing that here at all. */
            method: string | null;
            failure_code: string | null;
            /** @description The provider's category, never its raw message: that can carry an endpoint or a token, and this field is served over the API (§31). */
            failure_reason: string | null;
            /** Format: date-time */
            succeeded_at: string | null;
            /** Format: date-time */
            failed_at: string | null;
            /** Format: date-time */
            created_at: string;
        };
        /** @description An invoice's journey to a certified e-invoicing platform (§25.1). Four states, and a rejection is a settled outcome rather than an error to retry blindly. */
        Transmission: {
            /** Format: uuid */
            id: string;
            /** Format: uuid */
            invoice_id: string;
            provider: string;
            provider_document_id: string | null;
            /** @enum {string} */
            status: "PENDING" | "SUBMITTED" | "ACCEPTED" | "REJECTED";
            settled: boolean;
            rejection_code: string | null;
            rejection_reason: string | null;
            /** Format: date-time */
            submitted_at: string | null;
            /** Format: date-time */
            settled_at: string | null;
            /** Format: date-time */
            created_at: string;
        };
        /** @description Who the tenant is, for invoicing. Copied onto each document at issue — changing it never rewrites an invoice already sent. */
        BillingProfile: {
            legal_name: string | null;
            vat_number: string | null;
            registration_number: string | null;
            address_line1: string | null;
            address_line2: string | null;
            postal_code: string | null;
            city: string | null;
            /** @description ISO 3166 alpha-2. */
            country_code: string | null;
            /** Format: email */
            billing_email: string | null;
        };
        /** @description A refund against a settled payment. */
        Refund: {
            [key: string]: unknown;
        };
        TaxProfile: {
            /** Format: uuid */
            tenant_id: string;
            /** @enum {string} */
            customer_kind: "B2B" | "B2C";
            country_code: string | null;
            /** @description What the customer *claims*. Claiming it is not the same as proving it — see the status below. */
            taxable_person: boolean;
            location_evidence: {
                [key: string]: unknown;
            };
            vat_number: string | null;
            /**
             * @description UNAVAILABLE is "we asked and got no answer" — not a refusal, and definitely not a verification. It is its own state because §25.2 has to be able to show it as an anomaly.
             * @enum {string|null}
             */
            vat_number_status: "VERIFIED" | "INVALID" | "UNAVAILABLE" | null;
            /**
             * Format: date-time
             * @description Evidence with a date on it. A number is re-checked when this gets old, not on every save.
             */
            vat_number_verified_at: string | null;
            vat_number_country: string | null;
            /** @description Only a *verified* business gets it. Fail-closed: an outage leaves the number unproved rather than trusted (R8). */
            reverse_charge_available: boolean;
        };
        /** @description Rates carry validity windows, so a correction closes one window and opens another instead of restating an invoice already issued (R7). */
        TaxRate: {
            country_code: string;
            rate_kind: string;
            /**
             * @example 2000
             * @example 550
             */
            basis_points: number;
            /** Format: date-time */
            valid_from: string;
            /** Format: date-time */
            valid_until: string | null;
            /** @description Where the figure came from. A paramétrage seed, not a fiscal authority. */
            source: string;
        };
        /** @description Not just a number: which rule produced it, and why. An invoice priced under a regime the customer disputes is a conversation, and `reasons` is what that conversation needs. */
        TaxCalculation: {
            /**
             * @example eu.b2b.reverse_charge
             * @example eu.b2b.unverified
             */
            rule_id: string;
            /** @enum {string} */
            regime: "STANDARD" | "REVERSE_CHARGE" | "EXEMPT" | "OUT_OF_SCOPE";
            country_of_taxation: string;
            rate_basis_points: number;
            taxable_base: number;
            vat_amount: number;
            currency: string;
            reverse_charge: boolean;
            customer_tax_status: string;
            /** @description The wording the invoice must carry when a regime requires one. */
            legal_mention: string | null;
            reasons: string[];
        };
        /** @description The fiscal fact, written in the same transaction as the document that caused it — a sale whose VAT row could be lost is a sale that cannot be declared. */
        VatTransaction: {
            /** Format: uuid */
            id: string;
            /** Format: uuid */
            invoice_id: string | null;
            /** Format: uuid */
            credit_note_id: string | null;
            country: string;
            customer_tax_number: string | null;
            customer_tax_status: string;
            supply_type: string;
            taxable_base: number;
            vat_rate: number;
            vat_amount: number;
            currency: string;
            vat_regime: string;
            rule_id: string;
            reverse_charge: boolean;
            /** Format: date-time */
            transaction_date: string;
        };
        VatPeriod: {
            /** Format: uuid */
            id: string;
            jurisdiction: string;
            period_kind: string;
            /** Format: date */
            starts_on: string;
            /** Format: date */
            ends_on: string;
            /**
             * @description Closure is one-way, and a trigger enforces it: figures somebody has declared do not quietly change underneath them.
             * @enum {string}
             */
            status: "OPEN" | "CLOSED";
            /** Format: date-time */
            closed_at: string | null;
        };
        /** @description What was declared, frozen at closure. Reading a closed period reports this rather than recomputing — a recomputation would answer a different question than the one that was filed. */
        VatDeclaration: {
            /** Format: uuid */
            id: string;
            /** Format: uuid */
            period_id: string;
            currency: string;
            total_base: number;
            total_vat: number;
            transaction_count: number;
            breakdown: {
                [key: string]: unknown;
            };
            /** Format: date-time */
            created_at: string;
        };
        Quote: {
            /** Format: uuid */
            id: string;
            /** @enum {string} */
            status: "DRAFT" | "SENT" | "ACCEPTED" | "REJECTED" | "EXPIRED";
            /** @description Whether it can still be accepted *now*. Derived from the clock, so an expired quote reads as closed without anything having had to sweep it. */
            open: boolean;
            /**
             * Format: uuid
             * @description The version that priced it. Pinned, so a catalogue change cannot silently reprice a quote already sent.
             */
            offer_version_id: string;
            net: components["schemas"]["Money"];
            vat: components["schemas"]["Money"];
            gross: components["schemas"]["Money"];
            /** Format: date-time */
            valid_until: string;
            customer: components["schemas"]["PartySnapshot"];
            /** Format: date-time */
            sent_at: string | null;
            /** Format: date-time */
            decided_at: string | null;
            /** Format: date-time */
            created_at: string;
            lines: components["schemas"]["InvoiceLine"][];
        };
        /** @description The payment gate is readable straight off the document: an `invoice_id` from fulfilment onwards, a `subscription_id` only once that invoice is paid. Both non-null is #20's chain complete. */
        Order: {
            /** Format: uuid */
            id: string;
            /** @enum {string} */
            status: "PENDING" | "AWAITING_PAYMENT" | "COMPLETED" | "CANCELLED";
            /**
             * Format: uuid
             * @description Null for an order placed directly, without a quote.
             */
            quote_id: string | null;
            /** Format: uuid */
            offer_version_id: string;
            /**
             * Format: uuid
             * @description Appears only after the invoice is paid. Nothing is provisioned before the money arrives.
             */
            subscription_id: string | null;
            /** Format: uuid */
            invoice_id: string | null;
            net: components["schemas"]["Money"];
            vat: components["schemas"]["Money"];
            gross: components["schemas"]["Money"];
            /** Format: date-time */
            completed_at: string | null;
            /** Format: date-time */
            created_at: string;
            lines: components["schemas"]["InvoiceLine"][];
        };
        Notification: {
            /** Format: uuid */
            id: string;
            /**
             * @description A dotted event name, so it reads as what happened rather than a label somebody invented at the call site.
             * @example payment.failed
             * @example subscription.renewal_notice
             */
            type: string;
            /** @enum {string} */
            category: "BILLING" | "ACCOUNT" | "SECURITY" | "SUPPORT" | "MARKETING";
            payload: {
                [key: string]: unknown;
            };
            /** @description When true the rendered text is kept as it was sent. "What did we say?" is half the question a pre-renewal notice has to answer, and paraphrasing it later from the payload is not an answer. */
            legal_effect: boolean;
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            read_at: string | null;
        };
        /** @description One attempt on one channel. A failing channel must not lose the notification, and a succeeding one must not make the others look sent. */
        Delivery: {
            /** @enum {string} */
            channel: "SCREEN" | "EMAIL" | "SMS" | "WHATSAPP";
            /**
             * @description SENDING means a runner holds it under a lease. That state is what makes a duplicate send impossible rather than unlikely (R12).
             * @enum {string}
             */
            status: "PENDING" | "SENDING" | "SENT" | "DELIVERED" | "FAILED" | "SUPPRESSED";
            /**
             * @description Present exactly when suppressed. Writing nothing would make "did we tell them?" unanswerable.
             * @enum {string|null}
             */
            suppression_reason: "NO_CONSENT" | "OPTED_OUT" | "NO_ADDRESS" | "CHANNEL_UNAVAILABLE" | null;
            attempts: number;
            /** @description The class of the error, never its message: a provider's message can carry an endpoint or a token, and this field is served over the API (§31). */
            failure_reason: string | null;
            /** Format: date-time */
            sent_at: string | null;
            /** Format: date-time */
            delivered_at: string | null;
        };
        /** @description Revocation is a date, not a deletion. Erasing the row would destroy the record that permission once existed, which is the opposite of proof. */
        Consent: {
            /** Format: uuid */
            id: string;
            channel: string;
            purpose: string;
            /** Format: date-time */
            granted_at: string;
            /** Format: date-time */
            revoked_at: string | null;
            /** @description How it was obtained — the evidence, not just the claim. */
            source: string;
            live: boolean;
        };
        ProjectSummary: {
            /** Format: uuid */
            id: string;
            name: string;
            description: string | null;
            /** @description Which document shape this is. Enforced on write (non-negotiable #10): an unknown version is refused rather than stored and puzzled over later. */
            schema_version: number;
            /** Format: uuid */
            created_by: string | null;
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            updated_at: string;
            /**
             * Format: date-time
             * @description When somebody deleted it, or null while it is live. A deleted project keeps its versions, its assets and every job that referred to it — deletion is a date, not a cascade (R13).
             */
            deleted_at: string | null;
        };
        /** @description The JSONB document. Key order is preserved and insignificant whitespace is gone — what is stored is what comes back, which is the guarantee snapshot and restore rest on. Large assets are refused here and belong in storage (non-negotiable #9). */
        ProjectDocument: {
            [key: string]: unknown;
        };
        Project: components["schemas"]["ProjectSummary"] & {
            document: components["schemas"]["ProjectDocument"];
        };
        ProjectVersionSummary: {
            /** Format: uuid */
            id: string;
            version_number: number;
            label: string | null;
            name: string;
            description: string | null;
            schema_version: number;
            /** Format: uuid */
            created_by: string | null;
            /** Format: date-time */
            created_at: string;
        };
        ProjectVersion: components["schemas"]["ProjectVersionSummary"] & {
            document: components["schemas"]["ProjectDocument"];
        };
        Asset: {
            /** Format: uuid */
            id: string;
            kind: string;
            /** Format: uuid */
            project_id: string | null;
            filename: string;
            /** @description The **sniffed** type, not the one the request claimed. A client that says image/png about a script is not believed. */
            content_type: string;
            byte_size: number;
            /** @description Of the stored bytes, so a corrupted download is detectable. */
            checksum: string;
            /** Format: uuid */
            uploaded_by: string | null;
            /** Format: date-time */
            created_at: string;
        };
        Conversation: {
            /** Format: uuid */
            id: string;
            /**
             * @description A composite foreign key confines platform staff to SUPPORT threads, so staff cannot be added to an internal one even by a bug.
             * @enum {string}
             */
            kind: "INTERNAL" | "SUPPORT";
            subject: string;
            /** @enum {string} */
            status: "OPEN" | "CLOSED";
            /** Format: uuid */
            created_by: string | null;
            /** Format: date-time */
            closed_at: string | null;
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            updated_at: string;
            /** @description For the caller, counted from their own watermark. Absent where the reader is not a participant. */
            unread?: number;
        };
        Message: {
            /** Format: uuid */
            id: string;
            /** @description Per thread and consecutive. A deleted message keeps its place, so the sequence never develops a hole where a reply used to be. */
            seq: number;
            /** Format: uuid */
            author_user_id: string | null;
            /**
             * @description A foreign key ties the author to a participant *of that kind*, so a message from somebody who was never in the thread cannot be written at all.
             * @enum {string}
             */
            author_kind: "MEMBER" | "STAFF" | "SYSTEM";
            /** @description Empty when deleted, or when the author was erased under RGPD. */
            body: string;
            deleted: boolean;
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            edited_at: string | null;
        };
        Participant: {
            /** Format: uuid */
            user_id: string;
            /** @enum {string} */
            kind: "MEMBER" | "STAFF";
            /** @description The watermark. It never goes backwards — marking an older message read cannot un-read a newer one. */
            last_read_seq: number;
            /** Format: date-time */
            joined_at: string;
            /** Format: date-time */
            left_at: string | null;
        };
        Tenant: {
            /** Format: uuid */
            id: string;
            name: string;
            slug: string;
            /** @description Whether the platform has lent this tenant the catalogue. False by default: offers are keyed on product, not tenant, so a tenant administrator editing them changes what every other customer of that product is sold on. When false, `catalog.manage` is not resolved for this tenant's members at all. */
            may_author_offers: boolean;
        };
        /** @description A tenant as the platform sees it: the tenant, and the products it holds (ADR-047). `products` is the platform’s answer — which products it has assigned this tenant, through the console — so it lives on the staff shape and not on the `Tenant` a tenant reads about itself. */
        StaffTenant: components["schemas"]["Tenant"] & {
            /** @description In code order. A retired product a tenant still holds is listed with `active: false`. */
            products: components["schemas"]["PlatformProduct"][];
        };
        Member: {
            /** Format: uuid */
            user_id: string;
            /** Format: email */
            email: string | null;
            display_name: string | null;
            roles: string[];
        };
        /** @description Non-negotiable #21: a staff read of a tenant's data is never silent. Written in the same transaction as the read it records. */
        StaffAccessEntry: {
            /** Format: uuid */
            id: string;
            /** Format: uuid */
            staff_user_id: string;
            /** Format: uuid */
            tenant_id: string | null;
            /** Format: uuid */
            product_id: string | null;
            action: string;
            resource_type: string | null;
            resource_id: string | null;
            permission: string | null;
            detail: {
                [key: string]: unknown;
            };
            /** Format: date-time */
            occurred_at: string;
            /**
             * @description Why the read happened (R14). Null on rows written before R14, and on reads that cross no boundary — listing a queue, reading this log. Null means "not recorded", never "no reason".
             * @enum {string|null}
             */
            purpose: "SUPPORT_REQUEST" | "BILLING_INVESTIGATION" | "INCIDENT" | "SECURITY_REVIEW" | "LEGAL_REQUEST" | null;
            /** @description The specific reference the person gave. */
            reason: string | null;
        };
        Job: {
            /** Format: uuid */
            id: string;
            /**
             * @example export.project
             * @example notify.dispatch
             */
            type: string;
            /** @enum {string} */
            status: "QUEUED" | "RUNNING" | "SUCCEEDED" | "FAILED" | "CANCELLED";
            payload: {
                [key: string]: unknown;
            };
            /** @description What the handler reported — how many rows moved, not the rows. */
            result: {
                [key: string]: unknown;
            } | null;
            attempts: number;
            max_attempts: number;
            /**
             * Format: date-time
             * @description Work waits for this. A delayed run is late rather than wrong.
             */
            run_after: string;
            /** @description A category. The driver's account of what failed goes to the log, never here (§31). */
            failure_reason: string | null;
            /** Format: date-time */
            started_at: string | null;
            /** Format: date-time */
            finished_at: string | null;
            /** Format: date-time */
            created_at: string;
        };
        /** @description §30's trail. Append-only, and the one permitted update clears the actor and nothing else — what happened is kept, who did it can be forgotten. */
        AuditEntry: {
            /** Format: uuid */
            id: string;
            /** Format: date-time */
            occurred_at: string;
            action: string;
            subject_type: string;
            subject_id: string | null;
            /** Format: uuid */
            tenant_id: string | null;
            /** Format: uuid */
            product_id: string | null;
            /** @description Carries both the id and whether it was erased, so an act nobody performed stays distinguishable from one whose performer has since been forgotten. Collapsing them into a bare null would turn every erasure into a system action. */
            actor: {
                [key: string]: unknown;
            };
            /** Format: uuid */
            project_id: string | null;
            request_id: string | null;
            detail: {
                [key: string]: unknown;
            };
        };
        /** @description A GeoJSON Polygon with **exactly one linear ring** and no Z ordinate — narrower than RFC 7946 on purpose. The phase 1 spatial backend is core PostgreSQL (§19), whose `polygon` type has no holes and no third dimension, and the tempting repairs — keep the outer ring, drop the elevation — answer a different question from the one that was asked. A ring with a hole, a MultiPolygon or a 3D position is refused with 400 rather than reduced. */
        GeoJsonPolygon: {
            /** @enum {string} */
            type: "Polygon";
            /** @description One ring, closed: its last position repeats its first, so a triangle has four positions. At most 513 positions, and the ring must be simple — a self-intersecting ring is refused with 422, because PostgreSQL measures a bowtie as zero area rather than as an error. */
            coordinates: number[][][];
        };
        GeoJsonPoint: {
            /** @enum {string} */
            type: "Point";
            /**
             * @example [
             *       3.06,
             *       50.63
             *     ]
             */
            coordinates: number[];
        };
        /**
         * @description Which kind of coordinates the geometry is in. Required, and never inferred: a parcel in Lambert-93 and the same parcel in WGS 84 are both pairs of finite numbers, and guessing from magnitude would report a 1200 m² plot as 0.00000015.
         *
         *     `PROJECTED` — a planar system in linear units (Lambert-93, a UTM zone). Lengths and areas are in those units; the platform never learns which unit and never claims one.
         *
         *     `GEOGRAPHIC` — longitude and latitude in degrees. Topology is answerable, so `/geometry/intersections` accepts it and withholds `distance`. `/geometry/measure` refuses it with 422: a degree of longitude is not a fixed distance, so an area in degrees is not an area.
         * @enum {string}
         */
        CoordinateReference: "PROJECTED" | "GEOGRAPHIC";
        /** @description The axis-aligned extent, in the coordinate units of the input. */
        BoundingBox: {
            min_x: number;
            min_y: number;
            max_x: number;
            max_y: number;
        };
        /** @description Area and perimeter in the coordinate units of the input, squared and plain respectively. There is no unit field because there is no unit to report: the caller chose the projection and knows whether it is metres or feet, and inventing "m²" for a US state plane would be a claim this platform cannot support. */
        Measurement: {
            /**
             * @description Corners of the ring, counting the closing position once.
             * @example 4
             */
            vertices: number;
            /** @example 12 */
            area: number;
            /** @example 14 */
            perimeter: number;
            bounding_box: components["schemas"]["BoundingBox"];
        };
        /** @description How the subject stands to one candidate. Four answers rather than one, because a parcel search needs to separate the plots a footprint merely clips from the ones it swallows whole — and the plot that swallows the footprint is a third answer. */
        SpatialRelation: {
            /** @description The id the caller gave this candidate. */
            id: string;
            /** @description Whether the shapes share any point. Genuinely geometric, not a bounding-box approximation: an L-shaped parcel and a block sitting in its notch have overlapping boxes and this answers false. */
            intersects: boolean;
            /** @description Whether the subject wholly contains the candidate. Always false for a point subject. */
            contains: boolean;
            /** @description Whether the subject lies wholly inside the candidate. */
            within: boolean;
            /** @description Minimum separation in the input's linear units, zero when the shapes touch. **Null when `crs` is GEOGRAPHIC**, where a separation in degrees is not a distance. Null rather than absent, so one response shape serves both references. */
            distance: number | null;
        };
        /** @description The terms of a version being written. Always born `DRAFT` — no field here can say otherwise, because publishing is a separate and deliberate act. */
        OfferDraft: {
            /** @enum {string} */
            billing_period: "MONTHLY" | "YEARLY" | "CUSTOM";
            /**
             * @description Zero is legitimate: a free tier is still an offer.
             * @example 2900
             */
            price_minor_units: number;
            /** @example EUR */
            currency: string;
            /**
             * Format: date-time
             * @description When this version may start being sold. Defaults to now. Not the subscriber's period — §12 keeps the two apart.
             */
            valid_from?: string;
            /**
             * Format: date-time
             * @description When it stops being sellable. Null leaves the window open.
             */
            valid_until?: string | null;
            terms?: components["schemas"]["SubscriptionTerms"];
            /** @description What this version entitles the subscriber to. */
            grants?: {
                /** @description A feature of this product. One belonging to another product is refused, not ignored. */
                feature_id: string;
                /** @description Null means unlimited for a quota, and is the only valid value for a boolean capability. */
                limit?: number | null;
            }[];
        };
        /** @description A version as its author sees it, carrying the status the sale view has no reason to mention. */
        AuthoredOfferVersion: {
            /** Format: uuid */
            id: string;
            /** @description Assigned by the database as `max + 1`, never by the caller. */
            version: number;
            /** @enum {string} */
            status: "DRAFT" | "ACTIVE" | "EXPIRED" | "ARCHIVED";
            /** @enum {string} */
            billing_period: "MONTHLY" | "YEARLY" | "CUSTOM";
            price: components["schemas"]["Money"];
            /** Format: date-time */
            valid_from: string;
            /** Format: date-time */
            valid_until: string | null;
            grants: components["schemas"]["AuthoredOfferGrant"][];
            terms: components["schemas"]["SubscriptionTerms"];
        };
        /** @description An offer with every version it has, drafts included. */
        AuthoredOffer: {
            /** Format: uuid */
            id: string;
            /** @description How documents name this offer. Not editable: an identifier that can change is not an identifier. */
            code: string;
            name: string;
            plan: components["schemas"]["Plan"];
            /** @description Newest first. */
            versions: components["schemas"]["AuthoredOfferVersion"][];
            /** @description Whether the public storefront advertises this offer. Distinct from being on sale: a price negotiated with one reseller is sellable and is nobody else’s business. Only `staff.catalog.manage` may change it. */
            publicly_listed: boolean;
        };
        /** @description One page and how much lies behind it. The total is counted rather than inferred from a short page — an operator needs to know whether they are looking at forty customers or four thousand, and "the page came back short" answers that only on the last one. */
        DirectoryEnvelope: {
            total: number;
            limit: number;
            offset: number;
        };
        AdminTenant: {
            /** Format: uuid */
            id?: string;
            name?: string;
            slug?: string;
            /** Format: date-time */
            created_at?: string;
            members?: number;
            active_subscriptions?: number;
            unpaid_invoices?: number;
        };
        AdminUser: {
            /** Format: uuid */
            id?: string;
            email?: string | null;
            display_name?: string | null;
            /** Format: date-time */
            created_at?: string;
            /**
             * Format: date-time
             * @description Set means anonymised: the identity is gone and the record is kept.
             */
            erased_at?: string | null;
            tenants?: number;
        };
        AdminSubscription: {
            /** Format: uuid */
            id?: string;
            /** Format: uuid */
            tenant_id?: string;
            tenant_name?: string;
            status?: string;
            /** Format: date-time */
            started_at?: string;
            current_period_end?: string | null;
            cancel_at_period_end?: boolean;
            term_ends_at?: string | null;
            commitment_ends_at?: string | null;
            offer_code?: string;
            offer_version?: number;
            price_minor_units?: number;
            currency?: string;
        };
        AdminInvoice: {
            /** Format: uuid */
            id?: string;
            number?: string | null;
            /** Format: uuid */
            tenant_id?: string;
            tenant_name?: string;
            status?: string;
            currency?: string;
            net_minor_units?: number;
            vat_minor_units?: number;
            gross_minor_units?: number;
            issued_at?: string | null;
            due_at?: string | null;
            paid_at?: string | null;
        };
        AdminJob: {
            /** Format: uuid */
            id?: string;
            type?: string;
            status?: string;
            tenant_id?: string | null;
            product_id?: string | null;
            priority?: number;
            attempts?: number;
            max_attempts?: number;
            run_after?: string | null;
            leased_until?: string | null;
            failure_reason?: string | null;
            started_at?: string | null;
            finished_at?: string | null;
            /** Format: date-time */
            created_at?: string;
        };
        /**
         * @description How a tenant wants this product to look. Every field is present and null when unset, rather than absent — a client reading this to decide how to render needs one shape, and null means "use the product's defaults".
         *
         *     Kept per (tenant, product): §12.1 lets one tenant use several products, and a company white-labelling two of them has no reason to want the same shade in both.
         */
        Skin: {
            /**
             * @description Lower-case hex. The database refuses anything else: these values end up in a stylesheet, and a colour column accepting arbitrary text is a stylesheet injection with extra steps.
             * @example #1f4b99
             */
            primary_color: string | null;
            accent_color: string | null;
            /**
             * Format: uuid
             * @description An asset, fetched through the usual signed-link route. Storing the logo as an asset means it goes through the same sniffing and the same SVG refusal as every other upload.
             */
            logo_asset_id: string | null;
        };
        /**
         * @description **A checkout session is an order.** There is no `checkout_sessions` table and no separate lifecycle: `id` is the order's id, and everything a session would hold — is it paid, what was invoiced, did the subscription start — already lives on the order, the invoice and the payment. A second row tracking the same thing is a second answer that can disagree with the first, and the first is the one the money is attached to.
         *
         *     `status` is derived on read for the same reason, never stored.
         */
        CheckoutSession: {
            /** Format: uuid */
            id: string;
            /**
             * Format: uuid
             * @description The same value as `id`, said plainly.
             */
            order_id: string;
            /**
             * @description `PAYMENT_FAILED` means the order still awaits payment and the last attempt is dead — the state a retry exists for. Reporting it as AWAITING_PAYMENT would hide that nothing is coming.
             * @enum {string}
             */
            status: "AWAITING_PAYMENT" | "PAYMENT_FAILED" | "COMPLETED" | "CANCELLED";
            /**
             * Format: uuid
             * @description Null only for an order with nothing to collect — a free offer.
             */
            invoice_id: string | null;
            /**
             * Format: uuid
             * @description Set once the money arrived: activation is payment-gated, so this stays null while the invoice is unpaid.
             */
            subscription_id: string | null;
            /** Format: uuid */
            payment_id: string | null;
            payment_status: string | null;
            net: components["schemas"]["Money"];
            vat: components["schemas"]["Money"];
            gross: components["schemas"]["Money"];
        };
        /** @description The refresh token is deliberately absent: it leaves in an `HttpOnly` cookie that no script can read, and putting it here as well would throw away the reason the cookie exists. */
        Session: {
            /** @description Present it as `Authorization: Bearer <token>`. Hold it in memory; it is short-lived by design. */
            access_token: string;
            /** @example Bearer */
            token_type: string;
            /**
             * @description Seconds from now. A duration rather than an instant, so a browser with a skewed clock still schedules its renewal correctly.
             * @example 3600
             */
            expires_in: number;
        };
        /** @description One person holding a platform role. `email` and `display_name` are null once §26's erasure has emptied them; the row stays, because authority nobody can see is authority nobody can revoke. */
        StaffMember: {
            /** Format: uuid */
            user_id: string;
            email: string | null;
            display_name: string | null;
            roles: string[];
            /** Format: date-time */
            granted_at: string;
        };
        /** @description A grantable platform role, as the database holds it. */
        PlatformRole: {
            code: string;
            name: string;
        };
        /** @description The whole roster and the roles that may be granted. Returned by the two writes as well as the read, so a screen's next state is never guessed from a status code. */
        StaffRoster: {
            members: components["schemas"]["StaffMember"][];
            roles: components["schemas"]["PlatformRole"][];
        };
        /** @description A product as the platform’s own administrator sees it. `active` appears here and nowhere else, because every other product shape has already filtered on it — the context chain treats an inactive product as absent, and so does the storefront. */
        PlatformProduct: {
            /** Format: uuid */
            id: string;
            /** @description What clients send as `X-Product` and the storefront takes as `?product=`. Chosen once, at creation, and never editable: an identifier that can change is not an identifier. */
            code: string;
            name: string;
            /** @description A retired product keeps its tenants, subscriptions and invoices; what changes is that every door into it is closed. */
            active: boolean;
        };
        /** @description What the page needs to *use* a `client_secret` (ADR-048): which provider, the key that loads its own component, and whether any of this moves real money. Null when there is no payment to make (a free offer) or when the provider has no page-side part (the stub). `publishable_key` is designed by the provider to sit in a page and is not a secret — it belongs in the contract rather than in a build variable, which would freeze one deployment’s key into a bundle another deployment reuses. */
        PaymentProviderClient: {
            /**
             * @description The provider’s stable name, as `payments.provider` records it.
             * @example stripe
             */
            name: string;
            /** @example pk_test_51… */
            publishable_key: string | null;
            /** @description True when no real money moves: a test mode, a sandbox, a stub. The screen says so, because a demo on real cards and a production on test cards are the two mistakes that cost the most. */
            sandbox: boolean;
        } | null;
        /** @description The mandatory mentions of an invoice's issuer (§25). Every field is present in a response, null included, so a form binding to it finds the same shape whether the product was configured years ago or never. `legal_name` and `country_code` are the two an invoice cannot be issued without — a supplier not liable for VAT legitimately has no number, and requiring one would exclude a whole class of French business. */
        BillingSupplier: {
            /** @example Atlas SAS */
            legal_name: string | null;
            /**
             * @description Null for a supplier under the franchise en base, which invoices perfectly legally without one.
             * @example FR12345678901
             */
            vat_number: string | null;
            /**
             * @description SIREN or SIRET.
             * @example 123 456 789 00012
             */
            registration_number: string | null;
            address_line1: string | null;
            address_line2: string | null;
            postal_code: string | null;
            city: string | null;
            /**
             * @description Two letters, ISO 3166-1, upper-cased on the way in. It decides the VAT regime the invoice is issued under, so a value that is not a country code is refused rather than normalised — a guessed jurisdiction files somebody's VAT in the wrong country.
             * @example FR
             */
            country_code: string | null;
        };
        /** @description The supplier's own fiscal position. §25.3 requires it to be configured and never derived: whether the supplier is registered for the One Stop Shop is a dated fact about the business, and crossing the distance-selling threshold changes the regime of subsequent sales only — deriving it from turnover would retroactively restate invoices already issued. */
        TaxSettings: {
            /**
             * @description The jurisdiction VAT is filed in. Defaults to the billing supplier's country when the key has never been written.
             * @example FR
             */
            country: string;
            /** @description Decides how a cross-border consumer sale is taxed. */
            oss_registered: boolean;
            /**
             * @description What is being supplied changes the place of taxation, so it is stated rather than assumed.
             * @enum {string}
             */
            supply_type: "GOODS" | "SERVICES" | "DIGITAL_SERVICES";
            /**
             * @description The currency this product sells in.
             * @example EUR
             */
            currency: string;
        };
        /** @description One link in the chain a product has to complete before it can sell. Carries **facts, not sentences**: `key` names the step and `detail` carries what was counted or found missing, so the words belong to whatever renders it, in whatever language that surface speaks. */
        SetupStep: {
            /**
             * @description Stable identifier. The array's order is the dependency order: following it top to bottom never meets a refusal.
             * @enum {string}
             */
            key: "product" | "billing_identity" | "tax" | "plans" | "features" | "offers" | "published" | "advertised" | "payments";
            done: boolean;
            /** @description Whether a sale is impossible until this step is done. `tax` and `features` are listed and are not blocking — a supplier selling at home invoices correctly without a stated tax position, and an offer granting only access to the product is a legitimate offer. */
            blocking: boolean;
            /** @description What was counted or found missing — `count`, `missing`, `code`, `active`, `country` depending on the step. An object even when empty, so a client never has to tell `{}` from `null`. */
            detail: {
                [key: string]: unknown;
            };
        };
        /** @description What the demonstration world contains once it has been seeded — the answer the console shows so somebody can sign back in, since resetting deletes every account including the caller's. The password is not a secret: it is in this platform's source. */
        DemoWorld: {
            products: {
                code: string;
                name: string;
            }[];
            tenants: {
                slug: string;
                name: string;
                /** @description The product codes this organisation holds (ADR-047). */
                holds: string[];
            }[];
            /** @description One person per role the platform defines. */
            people: {
                /** Format: email */
                email: string;
                name: string;
                /** @enum {string} */
                scope: "tenant" | "platform";
                /** @description A tenant role code or a platform role code, by scope. */
                role: string;
                /** @description Slugs of the organisations a tenant-role holder is a member of; empty for platform staff. */
                tenants: string[];
            }[];
            /** @description The one password every seeded person signs in with. */
            password: string;
            /** @description The legal numbers of the invoices the seeded subscriptions raised. */
            invoices: string[];
        };
        /** @description One member of a tenant as the console sees them: who, what they may do, and on which of the tenant's products. A membership is mirrored onto every product the tenant holds (ADR-047), so the roles are the same on each. Name and address are null once the person has been erased (§26). */
        StaffTenantMember: {
            /** Format: uuid */
            user_id: string;
            /** Format: email */
            email: string | null;
            display_name: string | null;
            /** @description Tenant role codes. */
            roles: string[];
            /** @description Codes of the products the person is a member on; one entry when `product` was asked for. */
            products: string[];
        };
    };
    responses: {
        /** @description No credential, or one that did not verify. */
        Unauthenticated: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Error"];
            };
        };
        /** @description Authenticated, but not allowed to do this here. */
        PermissionDenied: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Error"];
            };
        };
        /** @description No such thing — or none this caller may see. The two are deliberately indistinguishable. */
        NotFound: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Error"];
            };
        };
        /** @description The caller has spent its allowance for the current window (§31). */
        TooManyRequests: {
            headers: {
                /** @description Seconds to wait. A client told only "too many" would guess, and a guessing client retries too soon. */
                "Retry-After"?: number;
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Error"];
            };
        };
        /** @description A controlled 500 (§37.9). The diagnostic detail is in the server log against this request_id, never on the wire. */
        InternalError: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Error"];
            };
        };
        /** @description This deployment cannot issue sessions: no signing secret is configured. */
        NotConfigured: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Error"];
            };
        };
    };
    parameters: {
        /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
        ProductHeader: string;
        /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
        TenantHeader: string;
        /** @description How many to return. */
        Limit: number;
        /** @description How many to skip. */
        Offset: number;
        /** @description Page size. A value outside the range is refused with 400 VALIDATION_FAILED rather than clamped. */
        DirectoryLimit: number;
        DirectoryOffset: number;
        /**
         * @description Why this read is happening, as the log can count it (R14). Non-negotiable #21 requires a staff access to be traced, motivated and never silent: the permission is the *authority* for the read, and this is the *reason*.
         *
         *     A small enumeration on purpose. A free-text field alone collects "support" a thousand times and proves nothing; this is the half that can be counted, and X-Access-Reason is the half that is specific.
         */
        AccessPurpose: "SUPPORT_REQUEST" | "BILLING_INVESTIGATION" | "INCIDENT" | "SECURITY_REVIEW" | "LEGAL_REQUEST";
        /** @description The specific thing being looked into — a ticket reference, or a sentence. Eight characters minimum, because "x" is not a reason and a field that accepted it would collect nothing while looking like a control. */
        AccessReason: string;
    };
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    listAudit: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of audit entries. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        entries: components["schemas"]["AuditEntry"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    eraseUser: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    user_id: string;
                };
            };
        };
        responses: {
            /** @description What was erased, and what was kept and why. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        erasure: {
                            /** Format: uuid */
                            user_id: string;
                            erased: {
                                [key: string]: number;
                            };
                            retained: {
                                [key: string]: {
                                    count: number;
                                    /** @enum {string} */
                                    ground: "accounting_record" | "fiscal_record" | "audit_trail" | "legal_notice_given" | "commercial_traceability";
                                };
                            };
                        };
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Already erased. A second run would file a second, emptier account of the same act. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listAdminInvoices: {
        parameters: {
            query?: {
                /** @description One customer. */
                tenant_id?: string;
                /** @description One product — the console's product picker, when a tenant holds several (ADR-047). */
                product_id?: string;
                /** @description DRAFT, ISSUED, PAID, CANCELLED, CREDITED. */
                status?: string;
                /** @description Page size. A value outside the range is refused with 400 VALIDATION_FAILED rather than clamped. */
                limit?: components["parameters"]["DirectoryLimit"];
                offset?: components["parameters"]["DirectoryOffset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DirectoryEnvelope"] & {
                        invoices: components["schemas"]["AdminInvoice"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listAdminJobs: {
        parameters: {
            query?: {
                /** @description PENDING, RUNNING, SUCCEEDED, FAILED, CANCELLED. */
                status?: string;
                /** @description The handler's name. */
                type?: string;
                /** @description Page size. A value outside the range is refused with 400 VALIDATION_FAILED rather than clamped. */
                limit?: components["parameters"]["DirectoryLimit"];
                offset?: components["parameters"]["DirectoryOffset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DirectoryEnvelope"] & {
                        jobs: components["schemas"]["AdminJob"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showMetrics: {
        parameters: {
            query: {
                product_id: string;
                months?: number;
                /** @description Which month the offers are ranked within. Top offers over a year and over last month are different questions. */
                month?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The dashboard. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        product_id: string;
                        months: number;
                        turnover: {
                            [key: string]: unknown;
                        }[];
                        top_offers: {
                            [key: string]: unknown;
                        };
                        renewal: {
                            [key: string]: unknown;
                        }[];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showQueue: {
        parameters: {
            query?: {
                /** @description Seconds. Supplying it asks for a verdict; omitting it asks only for the clock. */
                stale_after?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The liveness signal. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @description Said out loud, because that state is all zeroes and reads exactly like a calm idle queue. */
                        never_ran: boolean;
                        last_run: {
                            [key: string]: unknown;
                        };
                        unfinished_runs: number;
                        oldest_unfinished_seconds: number | null;
                        backlog: {
                            [key: string]: unknown;
                        };
                        stale_after_seconds?: number;
                        /** @description Only present when a threshold was supplied. */
                        stale?: boolean;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listAdminSubscriptions: {
        parameters: {
            query?: {
                /** @description One customer. */
                tenant_id?: string;
                /** @description One product — the console's product picker, when a tenant holds several (ADR-047). */
                product_id?: string;
                /** @description ACTIVE, CANCELLED, ENDED. */
                status?: string;
                /** @description Page size. A value outside the range is refused with 400 VALIDATION_FAILED rather than clamped. */
                limit?: components["parameters"]["DirectoryLimit"];
                offset?: components["parameters"]["DirectoryOffset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DirectoryEnvelope"] & {
                        subscriptions: components["schemas"]["AdminSubscription"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listAdminTenants: {
        parameters: {
            query?: {
                /** @description Matches name or slug. The caller's own `%` and `_` are literal, not wildcards. */
                search?: string;
                /** @description Page size. A value outside the range is refused with 400 VALIDATION_FAILED rather than clamped. */
                limit?: components["parameters"]["DirectoryLimit"];
                offset?: components["parameters"]["DirectoryOffset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DirectoryEnvelope"] & {
                        tenants: components["schemas"]["AdminTenant"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listAdminUsers: {
        parameters: {
            query?: {
                /** @description Matches email or display name. An erased person matches neither, having neither. */
                search?: string;
                /** @description Page size. A value outside the range is refused with 400 VALIDATION_FAILED rather than clamped. */
                limit?: components["parameters"]["DirectoryLimit"];
                offset?: components["parameters"]["DirectoryOffset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DirectoryEnvelope"] & {
                        users: components["schemas"]["AdminUser"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showAsset: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                assetId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The asset. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Asset"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    deleteAsset: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                assetId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Deleted. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createAssetLink: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                assetId: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                "application/json": {
                    /** @description How long the link should last. Clamped to the platform maximum. */
                    ttl_seconds?: number;
                };
            };
        };
        responses: {
            /** @description The link and when it stops working. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uri */
                        url: string;
                        /** Format: date-time */
                        expires_at: string;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    refreshSession: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A session. */
            200: {
                headers: {
                    /** @description The refresh token, as `HttpOnly; SameSite=Strict; Path=/api/v1/auth`. The browser sends it back to the refresh and sign-out endpoints and nothing else can read it. */
                    "Set-Cookie"?: string;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Session"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            /** @description The address or the password is not of an acceptable shape. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
            503: components["responses"]["NotConfigured"];
        };
    };
    signOut: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Signed out. The cookie is cleared. */
            204: {
                headers: {
                    /** @description The same cookie, emptied and expired. */
                    "Set-Cookie"?: string;
                    [name: string]: unknown;
                };
                content?: never;
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    signIn: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: email */
                    email: string;
                    /** @description Not trimmed: a password may legitimately begin or end with a space. The 72-byte ceiling is bcrypt's — it ignores everything past it, so a longer password would have a decorative tail. */
                    password: string;
                };
            };
        };
        responses: {
            /** @description A session. */
            200: {
                headers: {
                    /** @description The refresh token, as `HttpOnly; SameSite=Strict; Path=/api/v1/auth`. The browser sends it back to the refresh and sign-out endpoints and nothing else can read it. */
                    "Set-Cookie"?: string;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Session"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            /** @description The address or the password is not of an acceptable shape. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
            503: components["responses"]["NotConfigured"];
        };
    };
    listCreditNotes: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of credit notes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        credit_notes: components["schemas"]["CreditNote"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listInvoices: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of invoices. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        invoices: components["schemas"]["Invoice"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    issueInvoice: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The issued invoice. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Invoice"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description Nothing to invoice, or the period is already invoiced. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showInvoice: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The invoice. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Invoice"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    cancelInvoice: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The cancelled invoice. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Invoice"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Already final; issue a credit note instead. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    issueCreditNote: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                "application/json": {
                    reason?: string;
                };
            };
        };
        responses: {
            /** @description The credit note. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CreditNote"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description The invoice is not in a state that can be credited. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    markInvoicePaid: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The invoice, now paid. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Invoice"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Not in a state that can be paid — a draft, or already paid. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    startPayment: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The pending payment and its client secret. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Payment"] & {
                        /** @description Hand to the provider's client SDK. Never logged, never stored. */
                        client_secret: string | null;
                        payment_provider?: components["schemas"]["PaymentProviderClient"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description The invoice cannot be paid — a draft, cancelled, or already settled. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description `PAYMENT_PROVIDER_REFUSED` — the provider would not start this payment; `details.provider_code` carries its reason, written for operators. Or `PAYMENT_AMOUNT_UNSUPPORTED` — an amount the provider cannot represent in this currency. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
            /** @description `PAYMENT_PROVIDER_REJECTED_KEY` or `PAYMENT_PROVIDER_UNREACHABLE` — this deployment’s provider credentials were refused, or the provider could not be reached. Configuration, not the caller’s doing. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    showInvoicePdf: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The invoice document, as an attachment. */
            200: {
                headers: {
                    /** @description `attachment`, with a filename built from the legal number. */
                    "Content-Disposition"?: string;
                    /** @description SHA-256 of the stored document. It never changes. */
                    ETag?: string;
                    [name: string]: unknown;
                };
                content: {
                    "application/pdf": string;
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `INVOICE_NOT_RENDERABLE` — the invoice has no legal number yet, so there is no document. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listTransmissions: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The transmissions. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        transmissions: components["schemas"]["Transmission"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    submitInvoice: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                invoiceId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Accepted for transmission. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Transmission"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description The invoice is not issued, or has already been transmitted. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listPayments: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of payments. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        payments: components["schemas"]["Payment"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showPayment: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                paymentId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The payment. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Payment"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    refundPayment: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                paymentId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Why. Upper-cased and stored as a category. */
                    reason: string;
                    /** @description Absent refunds everything still refundable. */
                    amount_minor_units?: number;
                };
            };
        };
        responses: {
            /** @description The refund, accepted by the provider. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Refund"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Not refundable — unsettled, or already fully refunded. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description The amount exceeds what remains. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showBillingProfile: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The profile. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        profile: components["schemas"]["BillingProfile"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    saveBillingProfile: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    legal_name: string;
                    vat_number?: string | null;
                    registration_number?: string | null;
                    address_line1?: string | null;
                    address_line2?: string | null;
                    postal_code?: string | null;
                    city?: string | null;
                    /** @description ISO 3166 alpha-2. */
                    country_code?: string | null;
                    /** Format: email */
                    billing_email?: string | null;
                };
            };
        };
        responses: {
            /** @description The saved profile. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        profile: components["schemas"]["BillingProfile"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description A field was present but unacceptable — a country code that is not two letters, say. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    openCheckoutSession: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /**
                     * Format: uuid
                     * @description An offer on sale in this product right now. A draft, an expired one, or one belonging to another product is not found.
                     */
                    offer_id: string;
                };
            };
        };
        responses: {
            /** @description The session, with the client secret if there is anything to pay. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        session: components["schemas"]["CheckoutSession"] & {
                            /** @description Handed to the payment provider’s client SDK. **Returned here and nowhere else**: it is short-lived and it is a credential, so it is never stored (§31). Null when the provider uses a redirect rather than a secret. A caller who needs a fresh one retries the payment, which is a new attempt and gets its own. */
                            client_secret?: string | null;
                            payment_provider?: components["schemas"]["PaymentProviderClient"];
                        };
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description No such offer on sale in this product. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description `BILLING_PROFILE_REQUIRED` — the tenant has no billing profile, so nothing can be invoiced to it. Refused before any document is raised, because numbering is gapless. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description `PAYMENT_PROVIDER_REFUSED` — the provider would not start this payment; `details.provider_code` carries its reason, written for operators. Or `PAYMENT_AMOUNT_UNSUPPORTED` — an amount the provider cannot represent in this currency. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
            /** @description `PAYMENT_PROVIDER_REJECTED_KEY` or `PAYMENT_PROVIDER_UNREACHABLE` — this deployment’s provider credentials were refused, or the provider could not be reached. Configuration, not the caller’s doing. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    showCheckoutSession: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                /** @description The order id returned when the session was opened. */
                sessionId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The session. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        session: components["schemas"]["CheckoutSession"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description No such session in this tenant and product. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listConversations: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of conversations. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        conversations: components["schemas"]["Conversation"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    startConversation: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @enum {string} */
                    kind: "INTERNAL" | "SUPPORT";
                    subject: string;
                    participants?: string[];
                };
            };
        };
        responses: {
            /** @description The thread. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Conversation"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description A participant outside the tenant, or an unknown kind. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showConversation: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The thread with its participants. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Conversation"] & {
                        participants: components["schemas"]["Participant"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    closeConversation: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The closed thread. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Conversation"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listMessages: {
        parameters: {
            query?: {
                since_seq?: number;
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The messages. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        messages: components["schemas"]["Message"][];
                        since_seq: number;
                        limit: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    postMessage: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    body: string;
                };
            };
        };
        responses: {
            /** @description The message. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Message"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description The thread is closed. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    deleteMessage: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
                messageId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The message, emptied. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Message"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    addParticipant: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    user_id: string;
                };
            };
        };
        responses: {
            /** @description The participant. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Participant"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Not a member of this tenant. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    removeParticipant: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
                userId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Removed. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    markRead: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    seq: number;
                };
            };
        };
        responses: {
            /** @description The new watermark. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        last_read_seq: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    downloadAsset: {
        parameters: {
            query: {
                /** @description The signature minted by /assets/{assetId}/link. It carries its own expiry. */
                token: string;
            };
            header?: never;
            path: {
                assetId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The file. Served as an attachment, with the sniffed content type and a sanitised filename. */
            200: {
                headers: {
                    /** @description The type sniffed at upload, never the one the uploader claimed. */
                    "Content-Type"?: string;
                    /** @description attachment, with the filename sanitised. */
                    "Content-Disposition"?: string;
                    /** @description Stops a browser overriding the sniffed type and running the file as something else. */
                    "X-Content-Type-Options"?: "nosniff";
                    [name: string]: unknown;
                };
                content: {
                    "application/octet-stream": string;
                };
            };
            /** @description The link expired, or its signature did not verify. No detail: a probing caller learns nothing from it. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listEntitlements: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The entitlements in force. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        entitlements: components["schemas"]["Entitlement"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listFeatures: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The features. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        features: components["schemas"]["Feature"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    intersectGeometries: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    crs: components["schemas"]["CoordinateReference"];
                    /** @description The shape being asked about. */
                    subject: components["schemas"]["GeoJsonPolygon"] | components["schemas"]["GeoJsonPoint"];
                    /** @description The parcels to test against. Ids must be distinct — two rows sharing one id would make the response impossible to index by, so a repeat is refused rather than resolved. */
                    candidates: {
                        /** @example parcel-AB-0142 */
                        id: string;
                        geometry: components["schemas"]["GeoJsonPolygon"];
                    }[];
                };
            };
        };
        responses: {
            /** @description One relation per candidate, in the order given. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        relations: components["schemas"]["SpatialRelation"][];
                    };
                };
            };
            /** @description A geometry this backend does not represent, a candidate without an id, or two candidates sharing one. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description `GEOMETRY_NOT_SIMPLE` — a ring crosses itself. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    measureGeometry: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    crs: components["schemas"]["CoordinateReference"];
                    geometry: components["schemas"]["GeoJsonPolygon"];
                };
            };
        };
        responses: {
            /** @description The measurement. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        measurement: components["schemas"]["Measurement"];
                    };
                };
            };
            /** @description The geometry is not one this backend represents: a hole, a MultiPolygon, a Z ordinate, a ring that is not closed, or an unknown `crs`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description `GEOMETRY_NOT_SIMPLE` — the ring crosses itself, which has no well-defined area. Or `PROJECTION_REQUIRED` — `crs` was GEOGRAPHIC. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    health: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The process is up. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example ok */
                        status: string;
                    };
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listJobs: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of jobs. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        jobs: components["schemas"]["Job"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    requestJob: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    type: string;
                    payload?: {
                        [key: string]: unknown;
                    };
                    idempotency_key?: string | null;
                };
            };
        };
        responses: {
            /** @description The queued job. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Job"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description An unknown job type. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showJob: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                jobId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The job. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Job"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    cancelJob: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                jobId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The cancelled job. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Job"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Already running, or already finished. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showMe: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The resolved caller. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        user_id: string;
                        /** Format: email */
                        email: string | null;
                        display_name: string | null;
                        /** Format: uuid */
                        product_id: string;
                        /** Format: uuid */
                        tenant_id: string;
                        roles: string[];
                        permissions: string[];
                        /** @description Feature codes the tenant is entitled to here. */
                        capabilities: string[];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    updateMe: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Absent leaves it alone; null clears it. */
                    display_name?: string | null;
                };
            };
        };
        responses: {
            /** @description The updated profile. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        user_id: string;
                        /** Format: email */
                        email: string | null;
                        display_name: string | null;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description The body was well-formed JSON but not acceptable — a display name past its length, say. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showMyEntitlements: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The capabilities in force. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        capabilities: string[];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showMyPermissions: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Roles and permissions for this product and tenant. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        tenant_id: string;
                        /** Format: uuid */
                        product_id: string;
                        roles: string[];
                        permissions: string[];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listNotifications: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
                /** @description Present narrows to unread. */
                unread?: string;
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of notifications. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        notifications: components["schemas"]["Notification"][];
                        total: number;
                        unread: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listConsents: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The consents. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        consents: components["schemas"]["Consent"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    grantConsent: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @enum {string} */
                    channel: "EMAIL" | "SMS" | "WHATSAPP";
                    purpose: string;
                    /** @description Where the opt-in came from. The evidence, not just the claim. */
                    source: string;
                    evidence?: {
                        [key: string]: unknown;
                    };
                };
            };
        };
        responses: {
            /** @description The consent. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Consent"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description An unknown channel or purpose. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    revokeConsent: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                consentId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The revoked consent. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Consent"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showPreferences: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The preferences, keyed CATEGORY:CHANNEL. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        preferences: {
                            [key: string]: boolean;
                        };
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    savePreference: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @enum {string} */
                    category: "BILLING" | "ACCOUNT" | "SECURITY" | "SUPPORT" | "MARKETING";
                    /** @enum {string} */
                    channel: "SCREEN" | "EMAIL" | "SMS" | "WHATSAPP";
                    /** @default false */
                    enabled?: boolean;
                };
            };
        };
        responses: {
            /** @description The updated preferences. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        preferences: {
                            [key: string]: boolean;
                        };
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description SECURITY notifications cannot be disabled. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    readAll: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description How many were marked. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        marked: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    unreadCount: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The count. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        unread: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showDeliveries: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                notificationId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The deliveries. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        deliveries: components["schemas"]["Delivery"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    readNotification: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                notificationId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The notification, now read. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Notification"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listOffers: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The offers on sale. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offers: components["schemas"]["Offer"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createOffer: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["OfferDraft"] & {
                    /**
                     * @description Unique within the product, and permanent.
                     * @example pro-monthly
                     */
                    code: string;
                    name: string;
                    /**
                     * Format: uuid
                     * @description A plan of this product. One from another product is refused.
                     */
                    plan_id: string;
                };
            };
        };
        responses: {
            /** @description The offer, with its draft version. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description The body is not a valid draft: an unknown billing period, a bad currency, a commitment longer than the term. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description The plan, or a granted feature, does not belong to this product. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description `OFFER_CODE_TAKEN` — another offer in this product uses that code. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showOffer: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The offer. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Offer"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    renameOffer: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                };
            };
        };
        responses: {
            /** @description The offer. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description The name is missing or blank. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description No such offer in this product. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    publishOfferVersion: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    version: number;
                };
            };
        };
        responses: {
            /** @description The offer, with that version now ACTIVE. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description No version given. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description `VERSION_NOT_PUBLISHABLE` — it is not a draft. Or `OFFER_ALREADY_ON_SALE` — another version covers that window. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listOfferVersions: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The offer and all its versions, newest first. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description No such offer in this product. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    addOfferVersion: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["OfferDraft"];
            };
        };
        responses: {
            /** @description The offer, with the new draft. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description The body is not a valid draft. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description No such offer in this product, or a granted feature is not this product's. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    retryPayment: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                /** @description The attempt that failed. */
                paymentId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The new payment — its own attempt, its own provider reference — with its own client secret beside it, exactly as `startPayment` answers. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Payment"] & {
                        /** @description Handed to the payment provider’s client SDK. **Returned here and nowhere else**: it is short-lived and it is a credential, so it is never stored (§31). Null when the provider uses a redirect rather than a secret. A caller who needs a fresh one retries the payment, which is a new attempt and gets its own. */
                        client_secret?: string | null;
                        payment_provider?: components["schemas"]["PaymentProviderClient"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description No such payment in this tenant and product. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description `PAYMENT_STILL_IN_FLIGHT` — it has not settled or failed yet. `PAYMENT_ALREADY_SETTLED` — it succeeded. `INVOICE_NOT_PAYABLE` — the invoice is no longer collectable. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description `PAYMENT_PROVIDER_REFUSED` — the provider would not start this payment; `details.provider_code` carries its reason, written for operators. Or `PAYMENT_AMOUNT_UNSUPPORTED` — an amount the provider cannot represent in this currency. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
            /** @description `PAYMENT_PROVIDER_REJECTED_KEY` or `PAYMENT_PROVIDER_UNREACHABLE` — this deployment’s provider credentials were refused, or the provider could not be reached. Configuration, not the caller’s doing. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    listPlans: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The plans. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        plans: components["schemas"]["Plan"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listProducts: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The products available to this caller. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        products: components["schemas"]["Product"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showProduct: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                productId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The product. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: components["schemas"]["Product"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showProductCatalogue: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                productId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Product, features and configuration. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: components["schemas"]["Product"];
                        features: components["schemas"]["ProductFeature"][];
                        configuration: components["schemas"]["ProductConfiguration"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showProductConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                productId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The configuration. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        configuration: components["schemas"]["ProductConfiguration"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listProductFeatures: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                productId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The product's features. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        features: components["schemas"]["ProductFeature"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listProjects: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
                /** @description Ask for the deleted projects instead of the live ones. Only the exact value "true" does so: a mistyped query string answers the question it looks like, which is "the live ones". */
                deleted?: "true";
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of projects. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        projects: components["schemas"]["ProjectSummary"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createProject: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                    description?: string | null;
                    schema_version: number;
                    document: components["schemas"]["ProjectDocument"];
                };
            };
        };
        responses: {
            /** @description The project. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Project"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description The quota for projects is already spent. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description The document is larger than JSONB will take inline; put large data in storage (non-negotiable #9). */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An unknown schema_version, or a malformed document. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showProject: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The project. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Project"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    deleteProject: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Deleted. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    updateProject: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name?: string;
                    description?: string | null;
                    schema_version?: number;
                    document?: components["schemas"]["ProjectDocument"];
                };
            };
        };
        responses: {
            /** @description The updated project. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Project"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description The document is too large to store inline. */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An unknown schema_version, or a malformed document. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listAssets: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The assets. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        assets: components["schemas"]["Asset"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    uploadAsset: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
                /** @description The original name. Sanitised before it reaches a filesystem or a Content-Disposition header. */
                "X-Filename": string;
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/octet-stream": string;
            };
        };
        responses: {
            /** @description The stored asset. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Asset"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Larger than the platform accepts. */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description A type this platform will not store. */
            415: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    duplicateProject: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The copy. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Project"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description The quota for projects is already spent. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    requestExport: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The queued job. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Job"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    restoreProject: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    version_id: string;
                };
            };
        };
        responses: {
            /** @description The project, as the version had it. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Project"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    undeleteProject: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The project, live again. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Project"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listProjectVersions: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The versions. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        versions: components["schemas"]["ProjectVersionSummary"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createProjectVersion: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                "application/json": {
                    label?: string | null;
                };
            };
        };
        responses: {
            /** @description The new version. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProjectVersion"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showProjectVersion: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                projectId: string;
                versionId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The version. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProjectVersion"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listOrders: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of orders. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        orders: components["schemas"]["Order"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    placeOrder: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    offer_id: string;
                };
            };
        };
        responses: {
            /** @description The order. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Order"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description The offer is not on sale. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showOrder: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                orderId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The order. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Order"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    cancelOrder: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                orderId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The cancelled order. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Order"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Already completed, or already cancelled. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    fulfilOrder: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                orderId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The order, now carrying an invoice. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Order"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Not in a state that can be fulfilled. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listQuotes: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of quotes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        quotes: components["schemas"]["Quote"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createQuote: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    offer_id: string;
                    /** @description Clamped to the platform maximum if larger. */
                    validity_days?: number;
                };
            };
        };
        responses: {
            /** @description The quote. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Quote"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description The offer is not on sale. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description A field was unacceptable — or `QUOTE_REQUIRES_BUSINESS_CUSTOMER`: the tenant's tax profile does not say B2B, and quotes are a business instrument. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showQuote: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                quoteId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The quote. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Quote"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    acceptQuote: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                quoteId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The order the quote became. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Order"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description No longer open — expired, or already decided. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    rejectQuote: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                quoteId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The rejected quote. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Quote"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Already decided. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listAccessLog: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of access entries. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        entries: components["schemas"]["StaffAccessEntry"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listSupportConversations: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of support threads. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        conversations: components["schemas"]["Conversation"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showSupportConversation: {
        parameters: {
            query?: never;
            header: {
                /**
                 * @description Why this read is happening, as the log can count it (R14). Non-negotiable #21 requires a staff access to be traced, motivated and never silent: the permission is the *authority* for the read, and this is the *reason*.
                 *
                 *     A small enumeration on purpose. A free-text field alone collects "support" a thousand times and proves nothing; this is the half that can be counted, and X-Access-Reason is the half that is specific.
                 */
                "X-Access-Purpose": components["parameters"]["AccessPurpose"];
                /** @description The specific thing being looked into — a ticket reference, or a sentence. Eight characters minimum, because "x" is not a reason and a field that accepted it would collect nothing while looking like a control. */
                "X-Access-Reason": components["parameters"]["AccessReason"];
            };
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The thread and its messages. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Conversation"] & {
                        /** Format: uuid */
                        tenant_id: string;
                        /** Format: uuid */
                        product_id: string;
                        messages: components["schemas"]["Message"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description No motive was given, the purpose is not one the platform records, or the reference is too short to mean anything. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    closeSupportConversation: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The closed thread. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Conversation"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    postSupportMessage: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                conversationId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    body: string;
                };
            };
        };
        responses: {
            /** @description The message. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Message"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description The thread is closed. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listPlatformStaff: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The roster, and the roles that may be granted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["StaffRoster"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    grantPlatformRole: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /**
                     * Format: uuid
                     * @description Found through /admin/users.
                     */
                    user_id: string;
                    /** @description A code from the roster's `roles`. */
                    role: string;
                };
            };
        };
        responses: {
            /** @description Granted. The whole roster, as it now stands. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["StaffRoster"];
                };
            };
            /** @description `VALIDATION_FAILED` — no user id or no role given. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    revokePlatformRole: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                userId: string;
                role: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Revoked. The whole roster, as it now stands. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["StaffRoster"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `CANNOT_REVOKE_OWN_ADMIN` — ask another administrator. Or `LAST_PLATFORM_ADMIN` — appoint somebody else first. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showStaffIdentity: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The staff identity. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        staff: {
                            /** Format: uuid */
                            user_id: string;
                            /**
                             * Format: email
                             * @description Null once the person has been erased (§26); the identity outlives the details.
                             */
                            email: string | null;
                            /** @description What the shell shows in the account menu. */
                            display_name: string | null;
                            roles: string[];
                            permissions: string[];
                        };
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listTenantsForStaff: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of tenants. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tenants: components["schemas"]["StaffTenant"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showTenantForStaff: {
        parameters: {
            query?: never;
            header: {
                /**
                 * @description Why this read is happening, as the log can count it (R14). Non-negotiable #21 requires a staff access to be traced, motivated and never silent: the permission is the *authority* for the read, and this is the *reason*.
                 *
                 *     A small enumeration on purpose. A free-text field alone collects "support" a thousand times and proves nothing; this is the half that can be counted, and X-Access-Reason is the half that is specific.
                 */
                "X-Access-Purpose": components["parameters"]["AccessPurpose"];
                /** @description The specific thing being looked into — a ticket reference, or a sentence. Eight characters minimum, because "x" is not a reason and a field that accepted it would collect nothing while looking like a control. */
                "X-Access-Reason": components["parameters"]["AccessReason"];
            };
            path: {
                tenantId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The tenant. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tenant: components["schemas"]["StaffTenant"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description No motive was given, the purpose is not one the platform records, or the reference is too short to mean anything. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    setTenantOfferAuthoring: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                tenantId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    may_author_offers: boolean;
                };
            };
        };
        responses: {
            /** @description The tenant, as it now stands. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tenant: components["schemas"]["StaffTenant"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — `may_author_offers` absent or not a boolean. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    assignTenantProduct: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description The tenant. */
                tenantId: string;
                /** @description The product, by id — the console already holds ids from `listPlatformProducts`, and a code in a path is a second spelling of the same thing. */
                productId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The tenant, as it now stands. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tenant: components["schemas"]["StaffTenant"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `PRODUCT_INACTIVE` — the product is retired. A retired product has every door closed; assigning one hands a customer a locked door. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    unassignTenantProduct: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description The tenant. */
                tenantId: string;
                /** @description The product, by id — the console already holds ids from `listPlatformProducts`, and a code in a path is a second spelling of the same thing. */
                productId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The tenant, as it now stands. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tenant: components["schemas"]["StaffTenant"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `PRODUCT_IN_USE` — a subscription on this tenant and product is still owed service: active, or cancelled with paid time left. Withdrawing the product would cut the customer off from what they paid for. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listTenantMembersForStaff: {
        parameters: {
            query?: {
                /** @description A product code the tenant holds. A code it does not hold lists nobody, which is the true answer. */
                product?: string;
            };
            header: {
                /**
                 * @description Why this read is happening, as the log can count it (R14). Non-negotiable #21 requires a staff access to be traced, motivated and never silent: the permission is the *authority* for the read, and this is the *reason*.
                 *
                 *     A small enumeration on purpose. A free-text field alone collects "support" a thousand times and proves nothing; this is the half that can be counted, and X-Access-Reason is the half that is specific.
                 */
                "X-Access-Purpose": components["parameters"]["AccessPurpose"];
                /** @description The specific thing being looked into — a ticket reference, or a sentence. Eight characters minimum, because "x" is not a reason and a field that accepted it would collect nothing while looking like a control. */
                "X-Access-Reason": components["parameters"]["AccessReason"];
            };
            path: {
                tenantId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The members. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        members: components["schemas"]["StaffTenantMember"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description No motive was given, the purpose is not one the platform records, or the reference is too short to mean anything. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showSubscription: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The subscription, with history. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        subscription: components["schemas"]["Subscription"] | null;
                        history: components["schemas"]["Subscription"][];
                        events: components["schemas"]["SubscriptionEvent"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    subscribe: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    offer_id: string;
                    /**
                     * @description Subscribe the caller personally instead of the tenant.
                     * @default false
                     */
                    seat?: boolean;
                };
            };
        };
        responses: {
            /** @description The new subscription. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Subscription"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description Already subscribed, or the offer is not on sale. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Well-formed JSON, but not acceptable. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    cancelSubscription: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: {
            content: {
                "application/json": {
                    /**
                     * @description Ask to end now rather than at the next boundary. The policy still decides; asking does not make it so.
                     * @default false
                     */
                    immediately?: boolean;
                    /**
                     * @description Cancel the caller's own seat rather than the tenant's subscription.
                     * @default false
                     */
                    seat?: boolean;
                };
            };
        };
        responses: {
            /** @description The subscription and what cancelling did, or would do. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Subscription"] & {
                        cancellation: components["schemas"]["CancellationDecision"] & {
                            /**
                             * Format: uuid
                             * @description The early-termination invoice, when one was raised. Null when leaving cost nothing.
                             */
                            charge_invoice_id?: string | null;
                        };
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    changeOffer: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    offer_id: string;
                };
            };
        };
        responses: {
            /** @description The subscription on its new offer. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Subscription"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description No subscription to change, or the offer is not on sale. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    resumeSubscription: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The subscription, no longer ending. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Subscription"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Nothing was scheduled to end. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showSchedule: {
        parameters: {
            query?: {
                /** @description Present asks about the caller's own seat rather than the tenant's subscription, matching the cancel endpoint it predicts. */
                seat?: string;
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The subscription and the hypothetical decision. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        subscription: components["schemas"]["Subscription"];
                        if_cancelled_now: components["schemas"]["CancellationDecision"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    calculateTax: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    amount_minor_units: number;
                    /** @description Defaults to the product's. */
                    currency?: string;
                    /** @description Goods, services, digital services — what is supplied changes where it is taxed. */
                    supply_type?: string | null;
                };
            };
        };
        responses: {
            /** @description The calculation. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        calculation: components["schemas"]["TaxCalculation"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description The amount or currency was unacceptable. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showTaxProfile: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The profile. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        profile: components["schemas"]["TaxProfile"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    saveTaxProfile: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @enum {string} */
                    customer_kind: "B2B" | "B2C";
                    /** @description ISO 3166 alpha-2. */
                    country_code?: string | null;
                    /** @default false */
                    taxable_person?: boolean;
                    /** @description Normalised before checking; "FR 123 456" is not what a verification service accepts. */
                    vat_number?: string | null;
                };
            };
        };
        responses: {
            /** @description The saved profile, with whatever the check concluded. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        profile: components["schemas"]["TaxProfile"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description A field was unacceptable — a country code that is not two letters, say. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listTaxRates: {
        parameters: {
            query?: {
                /** @description Defaults to now. */
                on?: string;
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The rates, and the moment they were read for. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: date-time */
                        on: string;
                        rates: components["schemas"]["TaxRate"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listVatPeriods: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The periods. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        periods: components["schemas"]["VatPeriod"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showVatPeriod: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                periodId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The period, its totals, and the declaration if it has one. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        period: components["schemas"]["VatPeriod"];
                        totals: {
                            [key: string]: unknown;
                        };
                        declaration: components["schemas"]["VatDeclaration"] | null;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    closeVatPeriod: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                periodId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The frozen declaration. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        declaration: components["schemas"]["VatDeclaration"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description Already closed, not yet ended, or holding more than one currency. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listVatTransactions: {
        parameters: {
            query?: {
                /** @description How many to return. */
                limit?: components["parameters"]["Limit"];
                /** @description How many to skip. */
                offset?: components["parameters"]["Offset"];
            };
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of VAT transactions. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        transactions: components["schemas"]["VatTransaction"][];
                        total: number;
                        limit: number;
                        offset: number;
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showSkin: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The skin, set or not. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        skin: components["schemas"]["Skin"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    updateSkin: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    primary_color?: string | null;
                    accent_color?: string | null;
                };
            };
        };
        responses: {
            /** @description The skin as it now is. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        skin: components["schemas"]["Skin"];
                    };
                };
            };
            /** @description A value that is not a `#rrggbb` colour, and not null. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            /** @description `PERMISSION_DENIED` — the caller may not configure the tenant. Or `ENTITLEMENT_REQUIRED` — the tenant's plan does not include `white_label`. The two are separate checks and neither implies the other. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    uploadSkinLogo: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
                /** @description A label. The stored type comes from the bytes, never from this. */
                "X-Filename"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "image/png": string;
                "image/jpeg": string;
                "image/gif": string;
                "image/webp": string;
            };
        };
        responses: {
            /** @description The skin, now pointing at the new logo. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        skin: components["schemas"]["Skin"];
                    };
                };
            };
            /** @description `UPLOAD_EMPTY` — the body is the file, and there was none. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            /** @description `PERMISSION_DENIED` — the caller may not configure the tenant. Or `ENTITLEMENT_REQUIRED` — the tenant's plan does not include `white_label`. The two are separate checks and neither implies the other. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description `LOGO_NOT_AN_IMAGE` — the bytes sniffed as something else. Or the upload policy refused them outright. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    deleteSkinLogo: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The skin, with no logo. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        skin: components["schemas"]["Skin"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            /** @description `PERMISSION_DENIED` — the caller may not configure the tenant. Or `ENTITLEMENT_REQUIRED` — the tenant's plan does not include `white_label`. The two are separate checks and neither implies the other. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showCurrentTenant: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The tenant. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tenant: components["schemas"]["Tenant"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    updateCurrentTenant: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name?: string;
                };
            };
        };
        responses: {
            /** @description The updated tenant. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tenant: components["schemas"]["Tenant"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description A name that is not acceptable. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listMembers: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The members. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        members: components["schemas"]["Member"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    addMember: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: email */
                    email: string;
                    roles: string[];
                };
            };
        };
        responses: {
            /** @description The member. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Member"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description Already a member, or the seat quota is spent. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description An unknown role, or a malformed email. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    removeMember: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                userId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Removed. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    updateMember: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path: {
                userId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    roles: string[];
                };
            };
        };
        responses: {
            /** @description The member. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Member"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description An unknown role. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showTenantUsage: {
        parameters: {
            query?: never;
            header: {
                /** @description Which product this request is about. Required on everything except discovery and the public surface — a resource endpoint without it is refused rather than guessed at (§12.1). */
                "X-Product": components["parameters"]["ProductHeader"];
                /** @description Which tenant, when the caller belongs to more than one. Checked against membership, never believed on its own. */
                "X-Tenant"?: components["parameters"]["TenantHeader"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Usage against entitlements. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        usage: {
                            [key: string]: unknown;
                        }[];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    einvoiceWebhook: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Which adapter the delivery is for. The domain never names a provider; this selects the one that verifies it. */
                provider: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    [key: string]: unknown;
                };
            };
        };
        responses: {
            /** @description Applied — this delivery changed something. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        outcome: string;
                        applied: boolean;
                        /** Format: uuid */
                        payment_id?: string | null;
                        /** Format: uuid */
                        transmission_id?: string | null;
                    };
                };
            };
            /** @description Understood and ignored — a replay, or an event arriving after the outcome was already final. Deliberately a success: a provider that got an error would keep redelivering something the platform has correctly decided not to apply twice. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        outcome: string;
                        /** @constant */
                        applied: false;
                    };
                };
            };
            /** @description Unreadable, or the signature did not verify. No detail is given: a probing caller learns nothing from it. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    paymentWebhook: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Which adapter the delivery is for. The domain never names a provider; this selects the one that verifies it. */
                provider: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    [key: string]: unknown;
                };
            };
        };
        responses: {
            /** @description Applied — this delivery changed something. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        outcome: string;
                        applied: boolean;
                        /** Format: uuid */
                        payment_id?: string | null;
                        /** Format: uuid */
                        transmission_id?: string | null;
                    };
                };
            };
            /** @description Understood and ignored — a replay, or an event arriving after the outcome was already final. Deliberately a success: a provider that got an error would keep redelivering something the platform has correctly decided not to apply twice. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        outcome: string;
                        /** @constant */
                        applied: false;
                    };
                };
            };
            /** @description Unreadable, or the signature did not verify. No detail is given: a probing caller learns nothing from it. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    getPublicOffers: {
        parameters: {
            query: {
                /** @description The product code the storefront is about. A public route resolves no ambient product — there is no context chain on it — so the filter is named explicitly. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The window. Possibly empty, and never explaining why. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: {
                            code: string;
                            name: string;
                        } | null;
                        offers: components["schemas"]["Offer"][];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — no `product` was named. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    getPublicOffer: {
        parameters: {
            query: {
                /** @description The product code the storefront is about. A public route resolves no ambient product — there is no context chain on it — so the filter is named explicitly. */
                product: string;
            };
            header?: never;
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The offer, with the version on sale today. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["Offer"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — no `product` was named. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listPublicProducts: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The products with something on sale. Possibly empty. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        products: {
                            code: string;
                            name: string;
                        }[];
                    };
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listStorefrontOffers: {
        parameters: {
            query: {
                /** @description The product code. A staff route resolves no product of its own — a platform role grants no membership — so the console names which one it is administering. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The product and all of its offers. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: {
                            /** Format: uuid */
                            id: string;
                            code: string;
                            name: string;
                        };
                        offers: components["schemas"]["AuthoredOffer"][];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — no `product` was named. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    setOfferPublicListing: {
        parameters: {
            query: {
                /** @description The product code. A staff route resolves no product of its own — a platform role grants no membership — so the console names which one it is administering. */
                product: string;
            };
            header?: never;
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    publicly_listed: boolean;
                };
            };
        };
        responses: {
            /** @description The offer, as it now stands. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — `publicly_listed` absent or not a boolean, or no `product` named. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    signUp: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: email */
                    email: string;
                    /** @description Measured in bytes. The ceiling is bcrypt’s, not a preference. */
                    password: string;
                    /** @description The product code this account is being created for. A membership is per product, so an account without one would have no way in. */
                    product: string;
                    display_name?: string | null;
                    /** @description The company name, when there is one. Omitted for a consumer; the tenant is then named after the person. */
                    organisation?: string | null;
                    /** @description ISO 3166-1 alpha-2. Optional, and asked for because VAT depends on it rather than to make the form look complete. */
                    country?: string | null;
                };
            };
        };
        responses: {
            /** @description The account was created and a session issued. The refresh token leaves in a `Set-Cookie` header and never in the body. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        access_token: string;
                        token_type: string;
                        expires_in: number;
                        /**
                         * Format: uuid
                         * @description The organisation just created. Returned because the checkout that follows resolves it anyway.
                         */
                        tenant_id: string;
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — a field is missing, the wrong type, or the password is outside 12–72 bytes. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            404: components["responses"]["NotFound"];
            /** @description `EMAIL_TAKEN` — that address already has an account. Sign in instead. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    verifyEmail: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    token: string;
                };
            };
        };
        responses: {
            /** @description The address is confirmed. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        verified: boolean;
                    };
                };
            };
            /** @description `VERIFICATION_FAILED` — the token is unknown, expired or already used, or `VALIDATION_FAILED` if none was sent. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    listPlatformProducts: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Every product, newest first. Unpaged: a platform hosts a handful. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        products: components["schemas"]["PlatformProduct"][];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createProduct: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Lowercase letters, digits and hyphens. It travels in URLs and headers, so a code with a space in it is a code half the intermediaries mangle. Lowercased before validation. */
                    code: string;
                    name: string;
                };
            };
        };
        responses: {
            /** @description The product, active. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: components["schemas"]["PlatformProduct"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — a field is missing, or the code is not a usable identifier. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description `PRODUCT_CODE_TAKEN` — a product already uses that code. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    updateProduct: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                productId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name?: string;
                    active?: boolean;
                };
            };
        };
        responses: {
            /** @description The product, as it now stands. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: components["schemas"]["PlatformProduct"];
                    };
                };
            };
            /** @description `NOTHING_TO_UPDATE` — neither field was sent — or `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    resetDemoWorld: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The world, rebuilt and verified. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        world: components["schemas"]["DemoWorld"];
                    };
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            /** @description `NOT_A_DEMO_DEPLOYMENT` — the platform hosts products that are not the demonstration's; `details.products` names them. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showStaffCatalogue: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The product, its plans in rank order, and its features. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: {
                            /** Format: uuid */
                            id: string;
                            code: string;
                            name: string;
                        };
                        plans: components["schemas"]["Plan"][];
                        features: components["schemas"]["Feature"][];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — no `product` was named. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createPlan: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Lowercased before validation. Chosen once and never editable. */
                    code: string;
                    name: string;
                    /** @description Orders plans against each other. 0 is legitimate — a free tier at the bottom. */
                    rank: number;
                };
            };
        };
        responses: {
            /** @description The plan. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        plan: components["schemas"]["Plan"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `PLAN_CODE_TAKEN` — a plan of this product already uses that code. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    updatePlan: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path: {
                planId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name?: string;
                    rank?: number;
                };
            };
        };
        responses: {
            /** @description The plan, as it now stands. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        plan: components["schemas"]["Plan"];
                    };
                };
            };
            /** @description `NOTHING_TO_UPDATE` — neither field was sent — or `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createFeature: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    code: string;
                    name: string;
                    /**
                     * @description Uppercased before validation. Not editable afterwards.
                     * @enum {string}
                     */
                    kind: "BOOLEAN" | "QUOTA";
                    /** @description What a quota is counted in — "projects", "GB". Refused on a BOOLEAN. */
                    unit?: string | null;
                };
            };
        };
        responses: {
            /** @description The feature. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        feature: components["schemas"]["Feature"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — an unknown kind, a bad code, or a unit on a BOOLEAN. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `FEATURE_CODE_TAKEN`. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    renameFeature: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path: {
                featureId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                };
            };
        };
        responses: {
            /** @description The feature. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        feature: components["schemas"]["Feature"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createStaffOffer: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["OfferDraft"] & {
                    /**
                     * @description Unique within the product, and permanent.
                     * @example pro-monthly
                     */
                    code: string;
                    name: string;
                    /**
                     * Format: uuid
                     * @description A plan of this product. One from another product is refused.
                     */
                    plan_id: string;
                };
            };
        };
        responses: {
            /** @description The offer and its first DRAFT version. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `OFFER_CODE_TAKEN`. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    renameStaffOffer: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                };
            };
        };
        responses: {
            /** @description The offer. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    createStaffOfferVersion: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["OfferDraft"];
            };
        };
        responses: {
            /** @description The offer with its new DRAFT version. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    publishStaffOfferVersion: {
        parameters: {
            query: {
                /** @description The product code whose catalogue this is. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path: {
                offerId: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    version: number;
                };
            };
        };
        responses: {
            /** @description The offer, with that version ACTIVE. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        offer: components["schemas"]["AuthoredOffer"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED`. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            /** @description `OFFER_ALREADY_ON_SALE` — another version is on sale across the same window — or the version is not a draft. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showStaffConfiguration: {
        parameters: {
            query: {
                /** @description The product code this configuration belongs to. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The product, its billing identity, its tax position, and whether it can invoice. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: {
                            /** Format: uuid */
                            id: string;
                            code: string;
                            name: string;
                        };
                        billing_supplier: components["schemas"]["BillingSupplier"];
                        tax: components["schemas"]["TaxSettings"];
                        /** @description False while any mandatory mention is missing. A checkout against such a product refuses with `BILLING_NOT_CONFIGURED`. */
                        can_invoice: boolean;
                        /** @description The mandatory mentions that are absent, so the screen can say what to fix rather than only that something is wrong. */
                        missing: string[];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — no `product` was named. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    setBillingIdentity: {
        parameters: {
            query: {
                /** @description The product code this configuration belongs to. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["BillingSupplier"];
            };
        };
        responses: {
            /** @description The stored identity. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        billing_supplier: components["schemas"]["BillingSupplier"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — `details.missing` names the mandatory mentions that are absent, or `details.field` a value too long. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    setTaxSettings: {
        parameters: {
            query: {
                /** @description The product code this configuration belongs to. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["TaxSettings"];
            };
        };
        responses: {
            /** @description The stored settings. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        tax: components["schemas"]["TaxSettings"];
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — `details.field` names the field and `details.requirement` what was expected. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
    showProductReadiness: {
        parameters: {
            query: {
                /** @description The product code this chain is about. A staff route resolves no product of its own — a platform role grants no membership. */
                product: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The chain in dependency order, with the first blocking step named. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        product: {
                            /** Format: uuid */
                            id: string;
                            code: string;
                            name: string;
                        };
                        steps: components["schemas"]["SetupStep"][];
                        /** @description True when no blocking step remains. */
                        sellable: boolean;
                        /** @description The key of the first blocking step, or null when nothing blocks — which is what lets a screen say "done" rather than point at nowhere. */
                        next: string | null;
                    };
                };
            };
            /** @description `VALIDATION_FAILED` — no `product` was named. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            401: components["responses"]["Unauthenticated"];
            403: components["responses"]["PermissionDenied"];
            404: components["responses"]["NotFound"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalError"];
        };
    };
}
