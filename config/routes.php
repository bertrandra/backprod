<?php

declare(strict_types=1);

use App\Health\Controller\HealthController;
use FastRoute\RouteCollector;

/**
 * Route table.
 *
 * Every path is versioned under /api/v1 (Architecture V2 §10.3). A route is
 * added only after its OpenAPI operation exists (CLAUDE.md "When adding an
 * API": contract first).
 */
return static function (RouteCollector $routes): void {
    $routes->addRoute('GET', '/api/v1/health', HealthController::class);
};
