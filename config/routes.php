<?php

declare(strict_types=1);

use App\Admin\Controller\EraseUserController;
use App\Admin\Controller\ListAdminInvoicesController;
use App\Admin\Controller\ListAdminJobsController;
use App\Admin\Controller\ListAdminSubscriptionsController;
use App\Admin\Controller\ListAdminTenantsController;
use App\Admin\Controller\ListAdminUsersController;
use App\Admin\Controller\ListAuditController;
use App\Admin\Controller\ShowMetricsController;
use App\Admin\Controller\ShowQueueController;
use App\Auth\Controller\ForgotPasswordController;
use App\Auth\Controller\JwksController;
use App\Auth\Controller\RefreshSessionController;
use App\Auth\Controller\ResetPasswordController;
use App\Auth\Controller\SignInController;
use App\Auth\Controller\SignOutController;
use App\Auth\Controller\SignUpController;
use App\Auth\Controller\VerifyEmailController;
use App\Billing\Controller\CancelInvoiceController;
use App\Billing\Controller\IssueCreditNoteController;
use App\Billing\Controller\IssueInvoiceController;
use App\Billing\Controller\ListCreditNotesController;
use App\Billing\Controller\ListInvoicesController;
use App\Billing\Controller\PayInvoiceController;
use App\Billing\Controller\SaveBillingProfileController;
use App\Billing\Controller\ShowBillingProfileController;
use App\Billing\Controller\ShowInvoiceController;
use App\Billing\Controller\ShowInvoicePdfController;
use App\Checkout\Controller\CancelCheckoutSessionController;
use App\Checkout\Controller\OpenCheckoutSessionController;
use App\Checkout\Controller\RetryPaymentController;
use App\Checkout\Controller\ShowCheckoutSessionController;
use App\Commerce\Controller\AddPersonController;
use App\Commerce\Controller\CancelSubscriptionController;
use App\Commerce\Controller\ChangeOfferController;
use App\Commerce\Controller\CreateOfferController;
use App\Commerce\Controller\CreateOfferVersionController;
use App\Commerce\Controller\ListEntitlementsController;
use App\Commerce\Controller\ListFeaturesController;
use App\Commerce\Controller\ListOffersController;
use App\Commerce\Controller\ListOfferVersionsController;
use App\Commerce\Controller\ListPeopleController;
use App\Commerce\Controller\ListPlansController;
use App\Commerce\Controller\PublicOfferController;
use App\Commerce\Controller\PublicOffersController;
use App\Commerce\Controller\PublicProductsController;
use App\Commerce\Controller\PublicTenantController;
use App\Commerce\Controller\PublishOfferVersionController;
use App\Commerce\Controller\RemovePersonController;
use App\Commerce\Controller\ResumeSubscriptionController;
use App\Commerce\Controller\ShowOfferController;
use App\Commerce\Controller\ShowScheduleController;
use App\Commerce\Controller\ShowSubscriptionController;
use App\Commerce\Controller\SubscribeController;
use App\Commerce\Controller\TenantUsageController;
use App\Commerce\Controller\UpdateOfferController;
use App\Demo\Controller\PublicDemoController;
use App\EInvoice\Controller\EInvoiceWebhookController;
use App\EInvoice\Controller\ListTransmissionsController;
use App\EInvoice\Controller\SubmitInvoiceController;
use App\Geometry\Controller\IntersectGeometriesController;
use App\Geometry\Controller\MeasureGeometryController;
use App\Health\Controller\HealthController;
use App\Identity\Controller\MeContextController;
use App\Identity\Controller\MeController;
use App\Identity\Controller\MePermissionsController;
use App\Identity\Controller\MyEntitlementsController;
use App\Identity\Controller\MyNavigationController;
use App\Identity\Controller\UpdateMeController;
use App\Job\Controller\CancelJobController;
use App\Job\Controller\ListJobsController;
use App\Job\Controller\RequestJobController;
use App\Job\Controller\ShowJobController;
use App\Messaging\Controller\AddParticipantController;
use App\Messaging\Controller\CloseConversationController;
use App\Messaging\Controller\DeleteMessageController;
use App\Messaging\Controller\ListConversationsController;
use App\Messaging\Controller\ListMessagesController;
use App\Messaging\Controller\MarkReadController;
use App\Messaging\Controller\PostMessageController;
use App\Messaging\Controller\RemoveParticipantController;
use App\Messaging\Controller\ShowConversationController;
use App\Messaging\Controller\StartConversationController;
use App\Notification\Controller\GrantConsentController;
use App\Notification\Controller\ListConsentsController;
use App\Notification\Controller\ListNotificationsController;
use App\Notification\Controller\ReadAllNotificationsController;
use App\Notification\Controller\ReadNotificationController;
use App\Notification\Controller\RevokeConsentController;
use App\Notification\Controller\SavePreferenceController;
use App\Notification\Controller\ShowDeliveriesController;
use App\Notification\Controller\ShowPreferencesController;
use App\Notification\Controller\UnreadCountController;
use App\Payment\Controller\ListPaymentsController;
use App\Payment\Controller\PaymentWebhookController;
use App\Payment\Controller\RefundPaymentController;
use App\Payment\Controller\ShowPaymentController;
use App\Payment\Controller\StartPaymentController;
use App\Product\Controller\DeclareCapabilitiesController;
use App\Product\Controller\ListProductsController;
use App\Product\Controller\ProductCatalogueController;
use App\Product\Controller\ProductConfigurationController;
use App\Product\Controller\ProductFeaturesController;
use App\Product\Controller\ProductTenantEntitlementsController;
use App\Product\Controller\ProductTenantMembersController;
use App\Product\Controller\ProductTenantUsageController;
use App\Product\Controller\ShowProductController;
use App\Project\Controller\CreateProjectController;
use App\Project\Controller\CreateProjectVersionController;
use App\Project\Controller\DeleteProjectController;
use App\Project\Controller\DuplicateProjectController;
use App\Project\Controller\ListProjectsController;
use App\Project\Controller\ListProjectVersionsController;
use App\Project\Controller\RequestExportController;
use App\Project\Controller\RestoreProjectController;
use App\Project\Controller\ShowProjectController;
use App\Project\Controller\ShowProjectVersionController;
use App\Project\Controller\UndeleteProjectController;
use App\Project\Controller\UpdateProjectController;
use App\Sales\Controller\AcceptQuoteController;
use App\Sales\Controller\CancelOrderController;
use App\Sales\Controller\CreateQuoteController;
use App\Sales\Controller\FulfilOrderController;
use App\Sales\Controller\ListOrdersController;
use App\Sales\Controller\ListQuotesController;
use App\Sales\Controller\PlaceOrderController;
use App\Sales\Controller\RejectQuoteController;
use App\Sales\Controller\ShowOrderController;
use App\Sales\Controller\ShowQuoteController;
use App\Skin\Controller\DeleteSkinLogoController;
use App\Skin\Controller\ShowSkinController;
use App\Skin\Controller\UpdateSkinController;
use App\Skin\Controller\UploadSkinLogoController;
use App\Staff\Controller\AssignTenantProductController;
use App\Staff\Controller\CloseSupportConversationController;
use App\Staff\Controller\CreateFeatureController;
use App\Staff\Controller\CreatePlanController;
use App\Staff\Controller\CreateProductController;
use App\Staff\Controller\CreateStaffOfferController;
use App\Staff\Controller\CreateStaffOfferVersionController;
use App\Staff\Controller\CreateTenantController;
use App\Staff\Controller\GrantStaffRoleController;
use App\Staff\Controller\GrantTenantEntitlementController;
use App\Staff\Controller\IssueProductCredentialController;
use App\Staff\Controller\IssueWebhookSecretController;
use App\Staff\Controller\ListAccessLogController;
use App\Staff\Controller\ListPlatformFeaturesController;
use App\Staff\Controller\ListPlatformProductsController;
use App\Staff\Controller\ListProductCredentialsController;
use App\Staff\Controller\ListStaffController;
use App\Staff\Controller\ListStorefrontOffersController;
use App\Staff\Controller\ListSupportConversationsController;
use App\Staff\Controller\ListTenantJobsController;
use App\Staff\Controller\ListTenantMembersController;
use App\Staff\Controller\ListTenantOrdersController;
use App\Staff\Controller\ListTenantPaymentsController;
use App\Staff\Controller\ListTenantProjectsController;
use App\Staff\Controller\ListTenantQuotesController;
use App\Staff\Controller\ListTenantsController;
use App\Staff\Controller\ListWebhookDeliveriesController;
use App\Staff\Controller\PostSupportMessageController;
use App\Staff\Controller\PublishStaffOfferVersionController;
use App\Staff\Controller\RenameFeatureController;
use App\Staff\Controller\RenameStaffOfferController;
use App\Staff\Controller\ResetDemoWorldController;
use App\Staff\Controller\RetryWebhookDeliveryController;
use App\Staff\Controller\RevokeProductCredentialController;
use App\Staff\Controller\RevokeStaffRoleController;
use App\Staff\Controller\SendTestMailController;
use App\Staff\Controller\SetBillingIdentityController;
use App\Staff\Controller\SetDemoPageController;
use App\Staff\Controller\SetMailTemplatesController;
use App\Staff\Controller\SetNavigationSetupController;
use App\Staff\Controller\SetOfferAuthoringController;
use App\Staff\Controller\SetPublicListingController;
use App\Staff\Controller\SetStorefrontSettingsController;
use App\Staff\Controller\SetTaxSettingsController;
use App\Staff\Controller\ShowCatalogueController;
use App\Staff\Controller\ShowConfigurationController;
use App\Staff\Controller\ShowDemoPageController;
use App\Staff\Controller\ShowMailTemplatesController;
use App\Staff\Controller\ShowNavigationSetupController;
use App\Staff\Controller\ShowReadinessController;
use App\Staff\Controller\ShowStaffNavigationController;
use App\Staff\Controller\ShowStorefrontSettingsController;
use App\Staff\Controller\ShowSupportConversationController;
use App\Staff\Controller\ShowTenantController;
use App\Staff\Controller\ShowTenantEntitlementController;
use App\Staff\Controller\ShowTenantTaxProfileController;
use App\Staff\Controller\StaffIdentityController;
use App\Staff\Controller\UnassignTenantProductController;
use App\Staff\Controller\UpdatePlanController;
use App\Staff\Controller\UpdateProductController;
use App\Staff\Controller\UpdateStaffProfileController;
use App\Staff\Controller\UpdateTenantController;
use App\Staff\Controller\WithdrawTenantEntitlementController;
use App\Storage\Controller\CreateAssetLinkController;
use App\Storage\Controller\DeleteAssetController;
use App\Storage\Controller\DownloadAssetController;
use App\Storage\Controller\ListAssetsController;
use App\Storage\Controller\ShowAssetController;
use App\Storage\Controller\UploadAssetController;
use App\Tax\Controller\CalculateTaxController;
use App\Tax\Controller\CloseVatPeriodController;
use App\Tax\Controller\ListTaxRatesController;
use App\Tax\Controller\ListVatPeriodsController;
use App\Tax\Controller\ListVatTransactionsController;
use App\Tax\Controller\SaveTaxProfileController;
use App\Tax\Controller\ShowTaxProfileController;
use App\Tax\Controller\ShowVatPeriodController;
use App\Tenant\Controller\AcceptJoinRequestController;
use App\Tenant\Controller\AddMemberController;
use App\Tenant\Controller\CurrentTenantController;
use App\Tenant\Controller\DeclineJoinRequestController;
use App\Tenant\Controller\ListJoinRequestsController;
use App\Tenant\Controller\ListMembersController;
use App\Tenant\Controller\RemoveMemberController;
use App\Tenant\Controller\UpdateCurrentTenantController;
use App\Tenant\Controller\UpdateMemberController;
use FastRoute\RouteCollector;

/**
 * Route table.
 *
 * Every path is versioned under /api/v1 (Architecture V2 §10.3). A route is
 * added only after its OpenAPI operation exists (CLAUDE.md "When adding an
 * API": contract first).
 *
 * Routes are protected by default: only paths listed in the public-routes
 * definition in config/container.php bypass the request context chain.
 *
 * There is no /tenants/{id}: the tenant is whichever one the context chain
 * resolved, so a caller cannot address another (ADR-015).
 */
return static function (RouteCollector $routes): void {
    $routes->addRoute('GET', '/api/v1/health', HealthController::class);

    // --- Signing in (U12) -------------------------------------------------
    // Three public routes, and the only ones besides /health and the two
    // signature-verifying prefixes. This platform issues its own tokens now
    // (superseding ADR-014's "verify, never issue"), because the deployment
    // target offers PHP, PostgreSQL and JavaScript and an external issuer was
    // the one thing reaching outside all three.
    $routes->addRoute('POST', '/api/v1/auth/token', SignInController::class);
    $routes->addRoute('POST', '/api/v1/auth/refresh', RefreshSessionController::class);
    $routes->addRoute('POST', '/api/v1/auth/sign-out', SignOutController::class);

    // The way in for somebody who has no account at all. Creates the user,
    // their organisation and a TENANT_ADMIN membership of the named product in
    // one transaction, and answers with a session — so the purchase that
    // follows is an ordinary authenticated checkout rather than a second
    // anonymous flow with rules of its own.
    $routes->addRoute('POST', '/api/v1/auth/sign-up', SignUpController::class);

    // Public because the person following the link may be on a device that has
    // never signed in, which is half the point of confirming an address. It
    // never issues a session: a link that did would be a credential living in
    // an inbox.
    $routes->addRoute('POST', '/api/v1/auth/verify-email', VerifyEmailController::class);
    // A forgotten password, and the link that sets a new one (2026-09-19).
    // Both public and both authenticate the request itself — an address that
    // is only ever answered 202, and a single-use token from a mail.
    $routes->addRoute('POST', '/api/v1/auth/password/forgot', ForgotPasswordController::class);
    $routes->addRoute('POST', '/api/v1/auth/password/reset', ResetPasswordController::class);
    // The public keys sessions are signed with (ADR-051 milestone E), for a
    // product beside the platform that verifies bearers itself.
    $routes->addRoute('GET', '/api/v1/auth/jwks', JwksController::class);

    // --- The shop window --------------------------------------------------
    // The only reads on this platform that answer somebody with no account.
    // Everything under /api/v1/public is unauthenticated by policy, so what
    // is mounted here must be safe to show a stranger by construction rather
    // than by a permission check: these two show offers the platform has
    // explicitly marked `publicly_listed`, and nothing else.
    //
    // The product arrives as `?product=`, not `X-Product`: there is no
    // context chain on a public route to resolve a header, and the filter is
    // what the caller is choosing between.
    $routes->addRoute('GET', '/api/v1/public/offers', PublicOffersController::class);
    $routes->addRoute('GET', '/api/v1/public/offers/{offerId}', PublicOfferController::class);
    // The shop windows a stranger may choose between: only products that
    // advertise something, so the list says nothing the windows do not.
    $routes->addRoute('GET', '/api/v1/public/products', PublicProductsController::class);
    // The organisation at a URL root (2026-09-17), before any session.
    $routes->addRoute('GET', '/api/v1/public/tenant', PublicTenantController::class);
    // The demonstration page (2026-09-18): 404 until the console switches it on.
    $routes->addRoute('GET', '/api/v1/public/demo', PublicDemoController::class);

    $routes->addRoute('GET', '/api/v1/me', MeController::class);
    $routes->addRoute('PATCH', '/api/v1/me', UpdateMeController::class);
    $routes->addRoute('GET', '/api/v1/me/permissions', MePermissionsController::class);
    $routes->addRoute('GET', '/api/v1/me/navigation', MyNavigationController::class);
    $routes->addRoute('GET', '/api/v1/me/entitlements', MyEntitlementsController::class);
    $routes->addRoute('GET', '/api/v1/me/context', MeContextController::class);

    // A product's own server, with a key and no person (ADR-051 §4). The
    // product is the key's; the tenant is a parameter the gate checks and
    // records.
    $routes->addRoute('GET', '/api/v1/product/tenants/{tenantId}/entitlements', ProductTenantEntitlementsController::class);
    $routes->addRoute('POST', '/api/v1/product/tenants/{tenantId}/usage', ProductTenantUsageController::class);
    $routes->addRoute('GET', '/api/v1/product/tenants/{tenantId}/members', ProductTenantMembersController::class);
    // The one that names no tenant (2026-09-24): a product saying what it
    // gates on is saying something about itself. Every code is checked
    // against the platform's one list of features and none is added to it —
    // a program says what it gates on, a person adds to the vocabulary.
    $routes->addRoute('PUT', '/api/v1/product/capabilities', DeclareCapabilitiesController::class);

    // Identity-only: discovery cannot require the product context it supplies.
    $routes->addRoute('GET', '/api/v1/products', ListProductsController::class);
    $routes->addRoute('GET', '/api/v1/products/{productId}', ShowProductController::class);
    $routes->addRoute('GET', '/api/v1/products/{productId}/catalog', ProductCatalogueController::class);
    $routes->addRoute('GET', '/api/v1/products/{productId}/features', ProductFeaturesController::class);
    $routes->addRoute('GET', '/api/v1/products/{productId}/configuration', ProductConfigurationController::class);

    // The catalogue: what this product sells. Product-scoped through the
    // resolved context, so two products never see each other's commercial
    // terms.
    $routes->addRoute('GET', '/api/v1/plans', ListPlansController::class);
    $routes->addRoute('GET', '/api/v1/features', ListFeaturesController::class);
    $routes->addRoute('GET', '/api/v1/offers', ListOffersController::class);
    $routes->addRoute('GET', '/api/v1/offers/{offerId}', ShowOfferController::class);

    // Authoring the catalogue, behind `catalog.manage` rather than
    // `catalog.read` (§10.2). Reading what is on sale is something every
    // member does; deciding what it costs is not.
    //
    // There is no way to edit a published version's price here, and that is
    // §12 rather than an omission: terms change by adding a version, because
    // a subscription points at the version it was sold on.
    $routes->addRoute('POST', '/api/v1/offers', CreateOfferController::class);
    $routes->addRoute('PATCH', '/api/v1/offers/{offerId}', UpdateOfferController::class);
    $routes->addRoute('GET', '/api/v1/offers/{offerId}/versions', ListOfferVersionsController::class);
    $routes->addRoute('POST', '/api/v1/offers/{offerId}/versions', CreateOfferVersionController::class);
    $routes->addRoute('POST', '/api/v1/offers/{offerId}/publish', PublishOfferVersionController::class);

    // What the tenant subscribed to, and what it consequently may use.
    $routes->addRoute('GET', '/api/v1/subscription', ShowSubscriptionController::class);
    $routes->addRoute('POST', '/api/v1/subscription', SubscribeController::class);
    $routes->addRoute('POST', '/api/v1/subscription/change-offer', ChangeOfferController::class);
    $routes->addRoute('POST', '/api/v1/subscription/cancel', CancelSubscriptionController::class);

    // What a customer asks before they cancel: until when is it paid, until
    // when am I committed, and when may I leave (§13.1). No side effect, and
    // the same decision the cancel route acts on.
    $routes->addRoute('GET', '/api/v1/subscription/schedule', ShowScheduleController::class);
    // The people a subscription covers (2026-09-19): read by anybody who may
    // read the subscription, changed by its owner alone.
    $routes->addRoute('GET', '/api/v1/subscription/people', ListPeopleController::class);
    $routes->addRoute('POST', '/api/v1/subscription/people', AddPersonController::class);
    $routes->addRoute('DELETE', '/api/v1/subscription/people/{userId}', RemovePersonController::class);
    $routes->addRoute('POST', '/api/v1/subscription/resume', ResumeSubscriptionController::class);

    $routes->addRoute('GET', '/api/v1/entitlements', ListEntitlementsController::class);

    // What the tenant is charged, and who it is charged as. Both take the
    // full context chain: an invoice is the most sensitive thing a tenant
    // owns here, and every read is scoped by tenant *and* product.
    $routes->addRoute('GET', '/api/v1/billing/profile', ShowBillingProfileController::class);
    $routes->addRoute('PUT', '/api/v1/billing/profile', SaveBillingProfileController::class);

    $routes->addRoute('GET', '/api/v1/billing/invoices', ListInvoicesController::class);
    $routes->addRoute('POST', '/api/v1/billing/invoices', IssueInvoiceController::class);
    $routes->addRoute('GET', '/api/v1/billing/invoices/{invoiceId}', ShowInvoiceController::class);
    $routes->addRoute('GET', '/api/v1/billing/invoices/{invoiceId}/pdf', ShowInvoicePdfController::class);
    $routes->addRoute('POST', '/api/v1/billing/invoices/{invoiceId}/pay', PayInvoiceController::class);
    $routes->addRoute('POST', '/api/v1/billing/invoices/{invoiceId}/cancel', CancelInvoiceController::class);

    // Money, as opposed to the documents about it. Starting a payment takes
    // no amount and no instrument: the amount is the invoice's, and the card
    // goes to the provider, never here (§24).
    $routes->addRoute('GET', '/api/v1/billing/payments', ListPaymentsController::class);
    $routes->addRoute('GET', '/api/v1/billing/payments/{paymentId}', ShowPaymentController::class);
    $routes->addRoute('POST', '/api/v1/billing/invoices/{invoiceId}/payments', StartPaymentController::class);
    $routes->addRoute('POST', '/api/v1/billing/payments/{paymentId}/refund', RefundPaymentController::class);

    // Checkout (§7). A session is an order — there is no checkout_sessions
    // table and no second lifecycle to keep in step: everything a session
    // would hold is already on the order, the invoice and the payment.
    //
    // It composes the existing chain rather than adding a path beside it, so
    // payment-gated activation still holds: the subscription starts when the
    // money arrives, not when the session opens.
    $routes->addRoute('POST', '/api/v1/checkout/sessions', OpenCheckoutSessionController::class);
    $routes->addRoute('GET', '/api/v1/checkout/sessions/{sessionId}', ShowCheckoutSessionController::class);

    // A retry is a new attempt with its own provider reference, never a
    // resurrection: PaymentStatus is one-way, because the customer may have
    // used a different instrument and the two must be told apart.
    $routes->addRoute('POST', '/api/v1/checkout/sessions/{sessionId}/cancel', CancelCheckoutSessionController::class);
    $routes->addRoute('POST', '/api/v1/payments/{paymentId}/retry', RetryPaymentController::class);

    // How a finalised invoice is corrected. Never by editing it: it has a
    // legal number in an unbroken sequence, and editing or deleting leaves a
    // hole. Both documents are kept.
    $routes->addRoute('GET', '/api/v1/billing/credit-notes', ListCreditNotesController::class);
    $routes->addRoute('POST', '/api/v1/billing/invoices/{invoiceId}/credit', IssueCreditNoteController::class);

    // Notifications (§27.1). Every route is scoped by the *resolved* user:
    // a notification is addressed to one person, and asking for somebody
    // else's is not something this API can express.
    //
    // The literal paths are registered before the {notificationId} one so
    // that /read-all and /unread-count are not read as ids.
    $routes->addRoute('GET', '/api/v1/notifications', ListNotificationsController::class);
    $routes->addRoute('GET', '/api/v1/notifications/unread-count', UnreadCountController::class);
    $routes->addRoute('POST', '/api/v1/notifications/read-all', ReadAllNotificationsController::class);

    $routes->addRoute('GET', '/api/v1/notifications/preferences', ShowPreferencesController::class);
    $routes->addRoute('PUT', '/api/v1/notifications/preferences', SavePreferenceController::class);

    // SMS and WhatsApp are attempted only against a recorded, revocable
    // opt-in. A revoked consent is dated, never deleted: it is the record
    // that permission once existed.
    $routes->addRoute('GET', '/api/v1/notifications/consents', ListConsentsController::class);
    $routes->addRoute('POST', '/api/v1/notifications/consents', GrantConsentController::class);
    $routes->addRoute('DELETE', '/api/v1/notifications/consents/{consentId}', RevokeConsentController::class);

    $routes->addRoute('POST', '/api/v1/notifications/{notificationId}/read', ReadNotificationController::class);
    $routes->addRoute('GET', '/api/v1/notifications/{notificationId}/deliveries', ShowDeliveriesController::class);

    // Fiscalité (§25.3). Tax is a separate surface from Billing because the
    // two produce different things: Billing produces a document, Tax
    // produces the declarable fact behind it.
    //
    // /tax/calculate has no side effect. It answers what would be applied
    // *and why*, through the same code invoicing uses — which is what makes
    // it the diagnostic tool when an invoice surprises its recipient.
    $routes->addRoute('GET', '/api/v1/tax/profile', ShowTaxProfileController::class);
    $routes->addRoute('PUT', '/api/v1/tax/profile', SaveTaxProfileController::class);
    $routes->addRoute('GET', '/api/v1/tax/rates', ListTaxRatesController::class);
    $routes->addRoute('POST', '/api/v1/tax/calculate', CalculateTaxController::class);
    $routes->addRoute('GET', '/api/v1/tax/transactions', ListVatTransactionsController::class);

    // Closing is one-way and audited, so it takes tax.manage while the reads
    // take tax.read.
    $routes->addRoute('GET', '/api/v1/tax/reports', ListVatPeriodsController::class);
    $routes->addRoute('GET', '/api/v1/tax/reports/{periodId}', ShowVatPeriodController::class);
    $routes->addRoute('POST', '/api/v1/tax/reports/{periodId}/close', CloseVatPeriodController::class);

    // The one unauthenticated write in the platform, and the source of truth
    // for whether money moved (§24). It authenticates itself: the provider's
    // signature over the raw body, checked before a field is read.
    $routes->addRoute('POST', '/api/v1/webhooks/payments/{provider}', PaymentWebhookController::class);
    $routes->addRoute('POST', '/api/v1/webhooks/einvoice/{provider}', EInvoiceWebhookController::class);

    // §20's chain, left half: what was proposed, and what was committed to.
    // A quote lapses on the clock rather than on a sweep, so accepting one
    // asks the date and not the status column.
    $routes->addRoute('GET', '/api/v1/sales/quotes', ListQuotesController::class);
    $routes->addRoute('POST', '/api/v1/sales/quotes', CreateQuoteController::class);
    $routes->addRoute('GET', '/api/v1/sales/quotes/{quoteId}', ShowQuoteController::class);
    $routes->addRoute('POST', '/api/v1/sales/quotes/{quoteId}/accept', AcceptQuoteController::class);
    $routes->addRoute('POST', '/api/v1/sales/quotes/{quoteId}/reject', RejectQuoteController::class);

    $routes->addRoute('GET', '/api/v1/sales/orders', ListOrdersController::class);
    $routes->addRoute('POST', '/api/v1/sales/orders', PlaceOrderController::class);
    $routes->addRoute('GET', '/api/v1/sales/orders/{orderId}', ShowOrderController::class);
    $routes->addRoute('POST', '/api/v1/sales/orders/{orderId}/fulfil', FulfilOrderController::class);
    $routes->addRoute('POST', '/api/v1/sales/orders/{orderId}/cancel', CancelOrderController::class);

    // Transmission to an approved platform (§25.1). Every attempt is listed,
    // not just the latest: proving what happened is the point of keeping them.
    $routes->addRoute(
        'POST',
        '/api/v1/billing/invoices/{invoiceId}/transmit',
        SubmitInvoiceController::class,
    );
    $routes->addRoute(
        'GET',
        '/api/v1/billing/invoices/{invoiceId}/transmissions',
        ListTransmissionsController::class,
    );

    // Projects take the full context chain: unlike discovery, they are
    // tenant data, and every one of these resolves product *and* tenant
    // before a handler sees the request.
    $routes->addRoute('GET', '/api/v1/projects', ListProjectsController::class);
    $routes->addRoute('POST', '/api/v1/projects', CreateProjectController::class);
    $routes->addRoute('GET', '/api/v1/projects/{projectId}', ShowProjectController::class);
    $routes->addRoute('PATCH', '/api/v1/projects/{projectId}', UpdateProjectController::class);
    $routes->addRoute('DELETE', '/api/v1/projects/{projectId}', DeleteProjectController::class);

    $routes->addRoute('GET', '/api/v1/projects/{projectId}/versions', ListProjectVersionsController::class);
    $routes->addRoute('POST', '/api/v1/projects/{projectId}/versions', CreateProjectVersionController::class);
    $routes->addRoute(
        'GET',
        '/api/v1/projects/{projectId}/versions/{versionId}',
        ShowProjectVersionController::class,
    );
    $routes->addRoute('POST', '/api/v1/projects/{projectId}/duplicate', DuplicateProjectController::class);
    $routes->addRoute('POST', '/api/v1/projects/{projectId}/restore', RestoreProjectController::class);
    // Restoring *to a version* and undeleting are different operations that
    // share a word in English and nothing else (R13).
    $routes->addRoute('POST', '/api/v1/projects/{projectId}/undelete', UndeleteProjectController::class);

    // Assets (§15). The bytes live outside PostgreSQL; these move the record
    // of them. Upload takes the file as the raw body — the request is the
    // file — and the stored type comes from sniffing those bytes, never from
    // the Content-Type the request claimed.
    $routes->addRoute('GET', '/api/v1/projects/{projectId}/assets', ListAssetsController::class);
    $routes->addRoute('POST', '/api/v1/projects/{projectId}/assets', UploadAssetController::class);
    $routes->addRoute('POST', '/api/v1/projects/{projectId}/exports', RequestExportController::class);

    $routes->addRoute('GET', '/api/v1/assets/{assetId}', ShowAssetController::class);
    $routes->addRoute('DELETE', '/api/v1/assets/{assetId}', DeleteAssetController::class);
    $routes->addRoute('POST', '/api/v1/assets/{assetId}/link', CreateAssetLinkController::class);

    // The download itself is under /api/v1/downloads so that the public
    // prefix covers exactly one route. Mounting it at /assets/... would put
    // the whole asset surface behind a prefix reachable with no credential.
    $routes->addRoute('GET', '/api/v1/downloads/{assetId}/content', DownloadAssetController::class);

    // Geometry (§19). Two routes, not the three §7 sketches: core PostgreSQL
    // has no buffer, and §19 names buffers among the things a spatial
    // extension is for. An endpoint that returned an approximation of one
    // would be harder to remove than an endpoint that does not exist yet.
    //
    // These read no tenant row and write none. The door is the `gis.access`
    // capability rather than a permission — what a plan bought, not what a
    // role allows (§10.2, §13).
    $routes->addRoute('POST', '/api/v1/geometry/measure', MeasureGeometryController::class);
    $routes->addRoute('POST', '/api/v1/geometry/intersections', IntersectGeometriesController::class);

    $routes->addRoute('GET', '/api/v1/tenants/current', CurrentTenantController::class);
    $routes->addRoute('PATCH', '/api/v1/tenants/current', UpdateCurrentTenantController::class);

    $routes->addRoute('GET', '/api/v1/tenants/current/usage', TenantUsageController::class);

    // White label (§7). Reading needs only membership — a client has to know
    // how to render itself before it knows what the tenant bought — while
    // writing needs both the skin.manage permission and the `white_label`
    // entitlement, which do not imply each other.
    //
    // The logo is bytes, so it takes the raw body like an asset upload, and
    // its type is sniffed before anything is stored.
    $routes->addRoute('GET', '/api/v1/tenant/skin', ShowSkinController::class);
    $routes->addRoute('PATCH', '/api/v1/tenant/skin', UpdateSkinController::class);
    $routes->addRoute('POST', '/api/v1/tenant/skin/logo', UploadSkinLogoController::class);
    $routes->addRoute('DELETE', '/api/v1/tenant/skin/logo', DeleteSkinLogoController::class);

    // Platform staff (§12.2). Everything under /staff requires a platform
    // role, which no tenant membership grants — and grants nothing on the
    // tenant routes above. The tenant is named in the path here, the only
    // place in the platform where a client may do that, because there is no
    // membership to derive one from; the role authorises, and the read is
    // recorded.
    $routes->addRoute('GET', '/api/v1/staff/me', StaffIdentityController::class);
    // A staff member's own name and language (2026-09-22): the profile a
    // person with no membership has nowhere else.
    $routes->addRoute('PATCH', '/api/v1/staff/me', UpdateStaffProfileController::class);
    $routes->addRoute('GET', '/api/v1/staff/me/navigation', ShowStaffNavigationController::class);

    // Who holds platform authority, and the two writes that change it. Behind
    // `staff.grant`, which PLATFORM_ADMIN alone holds — the rest of /staff is
    // reachable by every platform role, and appointing staff is the one act
    // that could turn any of them into all of them.
    //
    // The role is in the path on the revoke rather than the body, because
    // `platform_staff` is keyed on (user, role): the thing being deleted is
    // that pair, and naming it in the URL is what makes the request
    // addressable and the refusal explicable.
    $routes->addRoute('GET', '/api/v1/staff/members', ListStaffController::class);
    $routes->addRoute('POST', '/api/v1/staff/members', GrantStaffRoleController::class);
    $routes->addRoute(
        'DELETE',
        '/api/v1/staff/members/{userId}/roles/{role}',
        RevokeStaffRoleController::class,
    );

    $routes->addRoute('GET', '/api/v1/staff/tenants', ListTenantsController::class);
    // Organisations are made and re-addressed here and nowhere else (2026-09-17).
    $routes->addRoute('POST', '/api/v1/staff/tenants', CreateTenantController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}', ShowTenantController::class);
    $routes->addRoute('PATCH', '/api/v1/staff/tenants/{tenantId}', UpdateTenantController::class);

    // Lending the platform's catalogue to one tenant. A write on a tenant
    // rather than a read of one, so it carries no motive header and its own
    // permission: `staff.tenants.manage`, PLATFORM_ADMIN only.
    $routes->addRoute(
        'PUT',
        '/api/v1/staff/tenants/{tenantId}/offer-authoring',
        SetOfferAuthoringController::class,
    );
    // Which products a tenant holds (ADR-047). PUT states that it does and
    // DELETE that it no longer does; both are the platform deciding what a
    // customer may reach, so `staff.tenants.manage` and no motive, like the
    // delegation above.
    $routes->addRoute(
        'PUT',
        '/api/v1/staff/tenants/{tenantId}/products/{productId}',
        AssignTenantProductController::class,
    );
    $routes->addRoute(
        'DELETE',
        '/api/v1/staff/tenants/{tenantId}/products/{productId}',
        UnassignTenantProductController::class,
    );
    // What the platform gives a tenant on a product it holds, without a sale
    // (docs/tenant-roots.md §2.8).
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement', ShowTenantEntitlementController::class);
    $routes->addRoute('PUT', '/api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement', GrantTenantEntitlementController::class);
    $routes->addRoute('DELETE', '/api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement', WithdrawTenantEntitlementController::class);

    // Who belongs to a tenant, read-only, with a motive and on the record
    // (R14). The console's tenant workspace reads it; nothing writes here —
    // a platform role never edits a membership (non-negotiable #22).
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/members', ListTenantMembersController::class);

    // The rest of what a customer has, the same way: read-only, per product
    // it holds, with a motive, on the record. TenantReads says why each.
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/payments', ListTenantPaymentsController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/orders', ListTenantOrdersController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/quotes', ListTenantQuotesController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/projects', ListTenantProjectsController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/jobs', ListTenantJobsController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}/tax-profile', ShowTenantTaxProfileController::class);
    // Products, which until now only the installer could create — and which
    // `GET /api/v1/products` cannot even list for an administrator, because
    // that route resolves through membership and a platform role grants none.
    // There is no DELETE: a product carries tenants, subscriptions and
    // invoices, and an invoice is a legal document. Retiring is `active`.
    $routes->addRoute('GET', '/api/v1/staff/products', ListPlatformProductsController::class);
    $routes->addRoute('POST', '/api/v1/staff/products', CreateProductController::class);
    $routes->addRoute('PATCH', '/api/v1/staff/products/{productId}', UpdateProductController::class);
    $routes->addRoute('GET', '/api/v1/staff/products/{productId}/credentials', ListProductCredentialsController::class);
    $routes->addRoute('POST', '/api/v1/staff/products/{productId}/credentials', IssueProductCredentialController::class);
    $routes->addRoute('DELETE', '/api/v1/staff/products/{productId}/credentials/{credentialId}', RevokeProductCredentialController::class);
    // What the platform tells the product (ADR-051 §5): the secret that
    // signs it, and what was sent.
    $routes->addRoute('POST', '/api/v1/staff/products/{productId}/webhook-secret', IssueWebhookSecretController::class);
    $routes->addRoute('GET', '/api/v1/staff/products/{productId}/webhook-deliveries', ListWebhookDeliveriesController::class);
    $routes->addRoute('POST', '/api/v1/staff/products/{productId}/webhook-deliveries/{deliveryId}/retry', RetryWebhookDeliveryController::class);

    // The demonstration world, rebuilt from the console. Behind a permission
    // of its own, and refused while a product that is not the demo's exists.
    $routes->addRoute('POST', '/api/v1/staff/demo/reset', ResetDemoWorldController::class);
    $routes->addRoute('GET', '/api/v1/staff/demo/page', ShowDemoPageController::class);
    $routes->addRoute('PUT', '/api/v1/staff/demo/page', SetDemoPageController::class);

    // The platform's own catalogue, authored by the platform. ADR-040 made
    // `catalog.manage` a delegation to a tenant, which left the platform able
    // to price its own product only by lending it away and acting as the
    // borrower. These are the door that should have existed first; the tenant
    // routes keep the meaning ADR-040 gave them.
    //
    // Plans especially: nothing on this platform could create one, so an
    // installation had a product and no way to build a catalogue on it at
    // all.
    //
    // Features are no longer here (2026-09-24). They are the platform's one
    // list, below, under a permission of their own — a product's catalogue
    // picks a code from it rather than inventing one.
    $routes->addRoute('GET', '/api/v1/staff/catalogue', ShowCatalogueController::class);
    $routes->addRoute('POST', '/api/v1/staff/catalogue/plans', CreatePlanController::class);
    $routes->addRoute('PATCH', '/api/v1/staff/catalogue/plans/{planId}', UpdatePlanController::class);
    $routes->addRoute('POST', '/api/v1/staff/catalogue/offers', CreateStaffOfferController::class);
    $routes->addRoute(
        'PATCH',
        '/api/v1/staff/catalogue/offers/{offerId}',
        RenameStaffOfferController::class,
    );
    $routes->addRoute(
        'POST',
        '/api/v1/staff/catalogue/offers/{offerId}/versions',
        CreateStaffOfferVersionController::class,
    );
    $routes->addRoute(
        'POST',
        '/api/v1/staff/catalogue/offers/{offerId}/publish',
        PublishStaffOfferVersionController::class,
    );

    // The platform's one list of features (2026-09-24). Not under
    // `/staff/catalogue`, which is a *product's* price list and takes
    // `?product=`: a feature is a word the platform and a product's code have
    // agreed on, and `max_projects` existed once per product until this moved.
    $routes->addRoute('GET', '/api/v1/staff/features', ListPlatformFeaturesController::class);
    $routes->addRoute('POST', '/api/v1/staff/features', CreateFeatureController::class);
    $routes->addRoute('PATCH', '/api/v1/staff/features/{featureId}', RenameFeatureController::class);

    // What a product needs configured before it can take money. ADR-042 gave
    // the console a way to create a product and ADR-043 a way to price it, and
    // a checkout against one created that way still refused with
    // `BILLING_NOT_CONFIGURED`: an invoice must name its issuer, and the issuer
    // lives in `product_configuration`, which only the demo seeder ever wrote.
    //
    // Two named keys rather than a JSONB editor. Every key in that table is read
    // by code that names it, so an arbitrary-key writer would let a typo store
    // configuration nothing reads — indistinguishable, on screen, from
    // configuration that did not save.
    //
    // PUT rather than PATCH: the mandatory mentions of an invoice are one
    // document, and a supplier that stops being liable for VAT has to be able to
    // remove its VAT number — which "omitted means leave it" makes impossible.
    $routes->addRoute('GET', '/api/v1/staff/configuration', ShowConfigurationController::class);
    $routes->addRoute(
        'PUT',
        '/api/v1/staff/configuration/billing-identity',
        SetBillingIdentityController::class,
    );
    $routes->addRoute('PUT', '/api/v1/staff/configuration/tax', SetTaxSettingsController::class);

    // The platform's own menu setup (2026-09-17): what the shell shows each
    // kind of person. Platform-wide, so no product on the address.
    $routes->addRoute('GET', '/api/v1/staff/navigation', ShowNavigationSetupController::class);
    $routes->addRoute('PUT', '/api/v1/staff/navigation', SetNavigationSetupController::class);

    // The chain, in the order it has to be completed. A fresh installation used
    // to be navigable only by walking into its refusals — no plan, so no offer;
    // published but not advertised, so an empty shop window; all of it correct,
    // and together a maze. This is the map.
    $routes->addRoute('GET', '/api/v1/staff/readiness', ShowReadinessController::class);

    // What the public page advertises, and who decided. `staff.catalog.manage`
    // rather than `catalog.manage`: ADR-040 lets the platform lend the latter
    // to a tenant, and a tenant authoring its own offers must not thereby
    // decide what a stranger is shown.
    $routes->addRoute('GET', '/api/v1/staff/storefront/offers', ListStorefrontOffersController::class);
    $routes->addRoute(
        'PUT',
        '/api/v1/staff/storefront/offers/{offerId}',
        SetPublicListingController::class,
    );
    // How a self-service sign-up ends (2026-09-18): pay right there, or the
    // application first. Platform-wide, on the storefront console.
    $routes->addRoute('GET', '/api/v1/staff/storefront/settings', ShowStorefrontSettingsController::class);
    $routes->addRoute('PUT', '/api/v1/staff/storefront/settings', SetStorefrontSettingsController::class);
    // The words the platform's mails say, and a test of the mail host (2026-09-19).
    $routes->addRoute('GET', '/api/v1/staff/mail/templates', ShowMailTemplatesController::class);
    $routes->addRoute('PUT', '/api/v1/staff/mail/templates', SetMailTemplatesController::class);
    $routes->addRoute('POST', '/api/v1/staff/mail/test', SendTestMailController::class);

    $routes->addRoute('GET', '/api/v1/staff/access-log', ListAccessLogController::class);

    // Admin / operations (§10.1). Same platform identity as /staff, a
    // different audience and a different permission: support reads the staff
    // access trail, operations reads the platform's audit trail. Nothing
    // under here is reachable with a tenant membership.
    $routes->addRoute('GET', '/api/v1/admin/audit', ListAuditController::class);

    // §25.2's dashboard, behind its own permission: finance and sales hold
    // it, support does not. The product is named in the query because an
    // admin surface resolves none of its own — which product is the question,
    // not the context.
    $routes->addRoute('GET', '/api/v1/admin/metrics', ShowMetricsController::class);

    // R10: a cron that stopped firing and a queue with nothing to do produce
    // the same silence. No product in the query — the runner is one process
    // for the whole platform, so there is no per-product answer to give.
    $routes->addRoute('GET', '/api/v1/admin/queue', ShowQueueController::class);

    // §31 and non-negotiables #14/#15. A POST because it is irreversible and
    // creates a record of itself; the response is the receipt naming what was
    // kept, which is the half of an erasure somebody has to be able to prove.
    $routes->addRoute('POST', '/api/v1/admin/erasures', EraseUserController::class);

    // The operational listings §7 names. Cross-tenant by definition — that is
    // what makes them admin surfaces — and each returns records *about*
    // tenant data rather than the data itself: that a subscription exists and
    // what it is worth, never what is inside anybody's project.
    //
    // Three permissions, not one, because these are not one audience: money
    // is admin.finance.read, the queue is admin.health.read, and the customer
    // directory is admin.directory.read, which PLATFORM_ADMIN alone holds
    // because /admin/users returns personal data.
    $routes->addRoute('GET', '/api/v1/admin/tenants', ListAdminTenantsController::class);
    $routes->addRoute('GET', '/api/v1/admin/users', ListAdminUsersController::class);
    $routes->addRoute('GET', '/api/v1/admin/subscriptions', ListAdminSubscriptionsController::class);
    $routes->addRoute('GET', '/api/v1/admin/invoices', ListAdminInvoicesController::class);
    $routes->addRoute('GET', '/api/v1/admin/jobs', ListAdminJobsController::class);

    // Support: the platform's side of §12.3. Only SUPPORT threads are
    // reachable — the repository filters on kind in SQL, so a tenant's
    // internal conversations are not excluded here by remembering to.
    $routes->addRoute('GET', '/api/v1/staff/conversations', ListSupportConversationsController::class);
    $routes->addRoute(
        'GET',
        '/api/v1/staff/conversations/{conversationId}',
        ShowSupportConversationController::class,
    );
    $routes->addRoute(
        'POST',
        '/api/v1/staff/conversations/{conversationId}/messages',
        PostSupportMessageController::class,
    );
    $routes->addRoute(
        'POST',
        '/api/v1/staff/conversations/{conversationId}/close',
        CloseSupportConversationController::class,
    );

    // Jobs (§27). Requesting work returns 202 and a job id; nothing here
    // runs anything — bin/run-jobs.php is the only thing that executes, and
    // cron is the only thing that calls it (D3).
    $routes->addRoute('GET', '/api/v1/jobs', ListJobsController::class);
    $routes->addRoute('POST', '/api/v1/jobs', RequestJobController::class);
    $routes->addRoute('GET', '/api/v1/jobs/{jobId}', ShowJobController::class);
    $routes->addRoute('POST', '/api/v1/jobs/{jobId}/cancel', CancelJobController::class);

    // Conversations: the tenant's side. Full context chain, scoped to the
    // caller's tenant and product and further to the threads they are in.
    $routes->addRoute('GET', '/api/v1/conversations', ListConversationsController::class);
    $routes->addRoute('POST', '/api/v1/conversations', StartConversationController::class);
    $routes->addRoute('GET', '/api/v1/conversations/{conversationId}', ShowConversationController::class);
    $routes->addRoute('POST', '/api/v1/conversations/{conversationId}/close', CloseConversationController::class);
    $routes->addRoute(
        'GET',
        '/api/v1/conversations/{conversationId}/messages',
        ListMessagesController::class,
    );
    $routes->addRoute(
        'POST',
        '/api/v1/conversations/{conversationId}/messages',
        PostMessageController::class,
    );
    $routes->addRoute(
        'DELETE',
        '/api/v1/conversations/{conversationId}/messages/{messageId}',
        DeleteMessageController::class,
    );
    $routes->addRoute('POST', '/api/v1/conversations/{conversationId}/read', MarkReadController::class);
    $routes->addRoute(
        'POST',
        '/api/v1/conversations/{conversationId}/participants',
        AddParticipantController::class,
    );
    $routes->addRoute(
        'DELETE',
        '/api/v1/conversations/{conversationId}/participants/{userId}',
        RemoveParticipantController::class,
    );

    $routes->addRoute('GET', '/api/v1/tenants/current/members', ListMembersController::class);
    $routes->addRoute('POST', '/api/v1/tenants/current/members', AddMemberController::class);
    $routes->addRoute('PATCH', '/api/v1/tenants/current/members/{userId}', UpdateMemberController::class);
    $routes->addRoute('DELETE', '/api/v1/tenants/current/members/{userId}', RemoveMemberController::class);
    // People who asked to join by themselves and are waiting (2026-09-17).
    $routes->addRoute('GET', '/api/v1/tenants/current/members/requests', ListJoinRequestsController::class);
    $routes->addRoute('POST', '/api/v1/tenants/current/members/{userId}/decline', DeclineJoinRequestController::class);
    $routes->addRoute('POST', '/api/v1/tenants/current/members/{userId}/accept', AcceptJoinRequestController::class);
};
