<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobRepository;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentRepository;
use App\Product\Domain\Product;
use App\Project\Domain\Project;
use App\Project\Domain\ProjectRepository;
use App\Project\Domain\Reach;
use App\Sales\Domain\Order;
use App\Sales\Domain\Quote;
use App\Sales\Domain\SalesRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\TenantDirectory;
use App\Staff\Domain\TenantProducts;
use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Service\Taxation;

/**
 * What a customer has, read by the platform — payments, orders, quotes, the
 * tax profile, projects, jobs — across the products it holds, or on one.
 *
 * **Read-only by construction.** Every method here reads; there is no
 * write beside any of them. What staff may change about a customer lives
 * in {@see StaffDesk} and is short. A platform role never becomes a member
 * (non-negotiable #22): these reads take the tenant as an explicit
 * parameter and are authorised by the platform role.
 *
 * **Through the tenant's own repositories**, with the tenant named rather
 * than resolved from a membership. Each of those reads is per (tenant,
 * product), which is how every resource on this platform is keyed; "every
 * product the customer holds" is that read once per product the platform
 * assigned it (ADR-047), concatenated. The page size is per product, and
 * generous: a console reads a customer, it does not page through one.
 */
final class TenantReads
{
    private const PAGE = 100;

    public function __construct(
        private readonly TenantDirectory $tenants,
        private readonly TenantProducts $holdings,
        private readonly PaymentRepository $payments,
        private readonly SalesRepository $sales,
        private readonly Taxation $taxation,
        private readonly ProjectRepository $projects,
        private readonly JobRepository $jobs,
    ) {
    }

    /** @return list<Payment> */
    public function payments(StaffIdentity $staff, string $tenantId, ?string $productCode): array
    {
        return $this->across($tenantId, $productCode, fn (Product $product): array => $this->payments->listForTenant($tenantId, $product->id, self::PAGE, 0));
    }

    /** @return list<Order> */
    public function orders(StaffIdentity $staff, string $tenantId, ?string $productCode): array
    {
        return $this->across($tenantId, $productCode, fn (Product $product): array => $this->sales->listOrders($tenantId, $product->id, self::PAGE, 0));
    }

    /** @return list<Quote> */
    public function quotes(StaffIdentity $staff, string $tenantId, ?string $productCode): array
    {
        return $this->across($tenantId, $productCode, fn (Product $product): array => $this->sales->listQuotes($tenantId, $product->id, self::PAGE, 0));
    }

    /** @return list<Project> */
    public function projects(StaffIdentity $staff, string $tenantId, ?string $productCode): array
    {
        return $this->across($tenantId, $productCode, fn (Product $product): array => $this->projects->listForTenant($tenantId, $product->id, Reach::everything(), self::PAGE, 0));
    }

    /**
     * Jobs are the one read whose repository already answers across products
     * — a job may belong to a tenant and no product — so it is asked once.
     *
     * @return list<Job>
     */
    public function jobs(StaffIdentity $staff, string $tenantId, ?string $productCode): array
    {
        $tenant = $this->open($tenantId);
        $narrowed = $this->narrow($tenant, $productCode);

        if ($productCode !== null && $narrowed === null) {
            return [];
        }

        return $this->jobs->listFor($tenant->id, $narrowed?->id, self::PAGE, 0);
    }

    /**
     * The fiscal identity: who the customer is for VAT. Not per product — a
     * tenant has one — so the product picker does not apply, and is not
     * pretended to.
     */
    public function taxProfile(StaffIdentity $staff, string $tenantId): CustomerTaxProfile
    {
        $tenant = $this->open($tenantId);

        return $this->taxation->profileFor($tenant->id);
    }

    /**
     * The read, once per product the tenant holds — or once, on the one the
     * code names. A code the tenant does
     * not hold reads nothing: nobody is on it here, which is the true
     * answer, and never somebody else's rows.
     *
     * @template T
     *
     * @param callable(Product): list<T> $read
     *
     * @return list<T>
     */
    private function across(string $tenantId, ?string $productCode, callable $read): array
    {
        $tenant = $this->open($tenantId);
        $narrowed = $this->narrow($tenant, $productCode);

        if ($productCode !== null) {
            return $narrowed === null ? [] : $read($narrowed);
        }

        $rows = [];

        foreach ($this->holdings->of($tenant->id) as $product) {
            foreach ($read($product) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function open(string $tenantId): \App\Tenant\Domain\Tenant
    {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            throw new NotFoundException('Tenant not found.', [], 'TENANT_NOT_FOUND');
        }

        return $tenant;
    }

    private function narrow(\App\Tenant\Domain\Tenant $tenant, ?string $productCode): ?Product
    {
        if ($productCode === null) {
            return null;
        }

        foreach ($this->holdings->of($tenant->id) as $product) {
            if ($product->code === $productCode) {
                return $product;
            }
        }

        return null;
    }
}
