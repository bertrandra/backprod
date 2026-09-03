<?php

declare(strict_types=1);

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
use App\Health\Controller\HealthController;
use App\Identity\Controller\MeController;
use App\Identity\Controller\MePermissionsController;
use App\Identity\Controller\MyEntitlementsController;
use App\Identity\Controller\UpdateMeController;
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

    $routes->addRoute('GET', '/api/v1/tenants/current/members', ListMembersController::class);
    $routes->addRoute('POST', '/api/v1/tenants/current/members', AddMemberController::class);
    $routes->addRoute('PATCH', '/api/v1/tenants/current/members/{userId}', UpdateMemberController::class);
    $routes->addRoute('DELETE', '/api/v1/tenants/current/members/{userId}', RemoveMemberController::class);
};
