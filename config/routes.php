<?php

declare(strict_types=1);

use App\Admin\Controller\EraseUserController;
use App\Admin\Controller\ListAuditController;
use App\Admin\Controller\ShowMetricsController;
use App\Admin\Controller\ShowQueueController;
use App\Billing\Controller\CancelInvoiceController;
use App\Billing\Controller\IssueCreditNoteController;
use App\Billing\Controller\IssueInvoiceController;
use App\Billing\Controller\ListCreditNotesController;
use App\Billing\Controller\ListInvoicesController;
use App\Billing\Controller\PayInvoiceController;
use App\Billing\Controller\SaveBillingProfileController;
use App\Billing\Controller\ShowBillingProfileController;
use App\Billing\Controller\ShowInvoiceController;
use App\Commerce\Controller\CancelSubscriptionController;
use App\Commerce\Controller\ChangeOfferController;
use App\Commerce\Controller\CreateOfferController;
use App\Commerce\Controller\CreateOfferVersionController;
use App\Commerce\Controller\ListEntitlementsController;
use App\Commerce\Controller\ListFeaturesController;
use App\Commerce\Controller\ListOffersController;
use App\Commerce\Controller\ListOfferVersionsController;
use App\Commerce\Controller\ListPlansController;
use App\Commerce\Controller\PublishOfferVersionController;
use App\Commerce\Controller\ResumeSubscriptionController;
use App\Commerce\Controller\ShowOfferController;
use App\Commerce\Controller\ShowScheduleController;
use App\Commerce\Controller\ShowSubscriptionController;
use App\Commerce\Controller\SubscribeController;
use App\Commerce\Controller\TenantUsageController;
use App\Commerce\Controller\UpdateOfferController;
use App\EInvoice\Controller\EInvoiceWebhookController;
use App\EInvoice\Controller\ListTransmissionsController;
use App\EInvoice\Controller\SubmitInvoiceController;
use App\Geometry\Controller\IntersectGeometriesController;
use App\Geometry\Controller\MeasureGeometryController;
use App\Health\Controller\HealthController;
use App\Identity\Controller\MeController;
use App\Identity\Controller\MePermissionsController;
use App\Identity\Controller\MyEntitlementsController;
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
use App\Product\Controller\ListProductsController;
use App\Product\Controller\ProductCatalogueController;
use App\Product\Controller\ProductConfigurationController;
use App\Product\Controller\ProductFeaturesController;
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
use App\Staff\Controller\CloseSupportConversationController;
use App\Staff\Controller\ListAccessLogController;
use App\Staff\Controller\ListSupportConversationsController;
use App\Staff\Controller\ListTenantsController;
use App\Staff\Controller\PostSupportMessageController;
use App\Staff\Controller\ShowSupportConversationController;
use App\Staff\Controller\ShowTenantController;
use App\Staff\Controller\StaffIdentityController;
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
use App\Tenant\Controller\AddMemberController;
use App\Tenant\Controller\CurrentTenantController;
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

    $routes->addRoute('GET', '/api/v1/me', MeController::class);
    $routes->addRoute('PATCH', '/api/v1/me', UpdateMeController::class);
    $routes->addRoute('GET', '/api/v1/me/permissions', MePermissionsController::class);
    $routes->addRoute('GET', '/api/v1/me/entitlements', MyEntitlementsController::class);

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
    $routes->addRoute('POST', '/api/v1/billing/invoices/{invoiceId}/pay', PayInvoiceController::class);
    $routes->addRoute('POST', '/api/v1/billing/invoices/{invoiceId}/cancel', CancelInvoiceController::class);

    // Money, as opposed to the documents about it. Starting a payment takes
    // no amount and no instrument: the amount is the invoice's, and the card
    // goes to the provider, never here (§24).
    $routes->addRoute('GET', '/api/v1/billing/payments', ListPaymentsController::class);
    $routes->addRoute('GET', '/api/v1/billing/payments/{paymentId}', ShowPaymentController::class);
    $routes->addRoute('POST', '/api/v1/billing/invoices/{invoiceId}/payments', StartPaymentController::class);
    $routes->addRoute('POST', '/api/v1/billing/payments/{paymentId}/refund', RefundPaymentController::class);

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

    // Platform staff (§12.2). Everything under /staff requires a platform
    // role, which no tenant membership grants — and grants nothing on the
    // tenant routes above. The tenant is named in the path here, the only
    // place in the platform where a client may do that, because there is no
    // membership to derive one from; the role authorises, and the read is
    // recorded.
    $routes->addRoute('GET', '/api/v1/staff/me', StaffIdentityController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants', ListTenantsController::class);
    $routes->addRoute('GET', '/api/v1/staff/tenants/{tenantId}', ShowTenantController::class);
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
};
