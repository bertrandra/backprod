<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\AdminPermission;
use App\Admin\Service\FinancialDashboard;
use App\Finance\Domain\OfferRevenue;
use App\Finance\Domain\RenewalPeriod;
use App\Finance\Domain\RevenuePeriod;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/admin/metrics?product_id=…
 *
 * The three §25.2 figures this starts with: turnover month by month, the
 * offers that earned it, and renewal.
 *
 * The product is named explicitly because an admin surface resolves no
 * product of its own — it looks across all of them, and which one is being
 * asked about is the question, not the context. That is the same reason
 * /staff takes a tenant in the path.
 *
 * `?months=` sets the window and `?month=` picks which month the offers are
 * ranked within.
 */
final class ShowMetricsController implements RouteHandler
{
    public function __construct(private readonly FinancialDashboard $dashboard)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::FINANCE_READ);

        $query = AdminRoute::query($request);
        $productId = AdminRoute::filter($query, 'product_id') ?? '';

        $view = $this->dashboard->overview(
            $productId,
            PageRequest::bounded($query, 'months', 12, 1, 60),
            AdminRoute::filter($query, 'month'),
        );

        return new JsonResponse([
            'product_id' => $productId,
            'months' => $view['months'],
            // Named "turnover", not "revenue": it is what was invoiced, and
            // the word that means collected is somebody else's.
            'turnover' => array_map(
                static fn (RevenuePeriod $p): array => AdminPresenter::turnover($p),
                $view['turnover'],
            ),
            'top_offers' => [
                'month' => $view['top_offers_month'],
                'offers' => array_map(
                    static fn (OfferRevenue $o): array => AdminPresenter::offerRevenue($o),
                    $view['top_offers'],
                ),
            ],
            'renewal' => array_map(
                static fn (RenewalPeriod $p): array => AdminPresenter::renewal($p),
                $view['renewal'],
            ),
        ], 200);
    }
}
