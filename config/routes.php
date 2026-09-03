<?php

declare(strict_types=1);

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
use App\Commerce\Controller\ListEntitlementsController;
use App\Commerce\Controller\ListFeaturesController;
use App\Commerce\Controller\ListOffersController;
use App\Commerce\Controller\ListPlansController;
use App\Commerce\Controller\ResumeSubscriptionController;
use App\Commerce\Controller\ShowOfferController;
use App\Commerce\Controller\ShowSubscriptionController;
use App\Commerce\Controller\SubscribeController;
use App\Commerce\Controller\TenantUsageController;
use App\EInvoice\Controller\EInvoiceWebhookController;
use App\EInvoice\Controller\ListTransmissionsController;
use App\EInvoice\Controller\SubmitInvoiceController;
use App\Health\Controller\HealthController;
use App\Identity\Controller\MeController;
use App\Identity\Controller\MePermissionsController;
use App\Identity\Controller\MyEntitlementsController;
use App\Identity\Controller\UpdateMeController;
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
use App\Staff\Controller\ListAccessLogController;
use App\Staff\Controller\ListTenantsController;
use App\Staff\Controller\ShowTenantController;
use App\Staff\Controller\StaffIdentityController;
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

    // What the tenant subscribed to, and what it consequently may use.
    $routes->addRoute('GET', '/api/v1/subscription', ShowSubscriptionController::class);
    $routes->addRoute('POST', '/api/v1/subscription', SubscribeController::class);
    $routes->addRoute('POST', '/api/v1/subscription/change-offer', ChangeOfferController::class);
    $routes->addRoute('POST', '/api/v1/subscription/cancel', CancelSubscriptionController::class);
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

    $routes->addRoute('GET', '/api/v1/tenants/current/members', ListMembersController::class);
    $routes->addRoute('POST', '/api/v1/tenants/current/members', AddMemberController::class);
    $routes->addRoute('PATCH', '/api/v1/tenants/current/members/{userId}', UpdateMemberController::class);
    $routes->addRoute('DELETE', '/api/v1/tenants/current/members/{userId}', RemoveMemberController::class);
};
