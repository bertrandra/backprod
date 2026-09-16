<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Demo\Domain\DemoWorld;
use App\Demo\Domain\SeededWorld;
use App\Demo\Service\DemoSeeder;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * POST /api/v1/staff/demo/reset — empty every business table and seed the
 * demonstration world afresh.
 *
 * The widest destructive act on this platform, so three things fence it.
 * `staff.demo.reset` is a permission of its own, held by PLATFORM_ADMIN
 * alone, rather than a side of `staff.products.manage` — creating a product
 * and destroying every one are not the same trust. The seeder refuses while
 * a product that is not the demonstration's exists, whatever the caller
 * says. And the answer names who to sign in as, because the caller has just
 * deleted themselves: their token names a `users` row that is gone, and the
 * next request they make is refused. The page knows to sign them out.
 *
 * No audit row, and honestly so: `audit_log` is one of the tables emptied.
 * The request log still has the request id, the identity and the path.
 */
final class ResetDemoWorldController implements RouteHandler
{
    public function __construct(private readonly DemoSeeder $seeder)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::DEMO_RESET);

        $world = $this->seeder->reset();

        if (!$world->holds()) {
            // The seeder verifies what it made. A world that does not hold is
            // worse than none — somebody would demonstrate it — and a 500 with
            // the failed checks in the log is the truthful answer.
            throw new RuntimeException(
                'The demonstration world was reseeded but a check failed: ' . implode(', ', $world->failures()) . '.',
            );
        }

        return new JsonResponse(['world' => self::present($world)], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(SeededWorld $world): array
    {
        $products = [];

        foreach (DemoWorld::PRODUCTS as $code => $product) {
            $products[] = ['code' => $code, 'name' => $product['name']];
        }

        $tenants = [];

        foreach (DemoWorld::TENANTS as $slug => $tenant) {
            $tenants[] = ['slug' => $slug, 'name' => $tenant['name'], 'holds' => $tenant['holds']];
        }

        return [
            'products' => $products,
            'tenants' => $tenants,
            'people' => array_map(
                static fn (array $person): array => [
                    'email' => $person['email'],
                    'name' => $person['name'],
                    'scope' => $person['scope'],
                    'role' => $person['role'],
                    'tenants' => $person['tenants'],
                ],
                $world->people(),
            ),
            // Not a secret: it is in this platform's source, and the whole
            // point of the answer is that somebody can sign back in.
            'password' => DemoWorld::PASSWORD,
            'invoices' => array_values(array_filter(
                array_map(static fn ($invoice): ?string => $invoice->number, $world->invoices),
                is_string(...),
            )),
        ];
    }
}
