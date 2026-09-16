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
use App\Sales\Domain\Order;
use App\Sales\Domain\Quote;
use App\Sales\Domain\SalesRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\AccessMotive;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;
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
 * parameter, are authorised by the platform role, and every one writes an
 * access-log row with the motive R14 asks for — the miss included, because
 * somebody probing for ids is exactly who would rather it were not kept.
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
        private readonly StaffAccessLog $trail,
    ) {
    }

    /** @return list<Payment> */
    public function payments(StaffIdentity $staff, string $tenantId, ?string $productCode, AccessMotive $motive): array
    {
        return $this->across($staff, $tenantId, $productCode, $motive, 'payments', fn (Product $product): array => $this->payments->listForTenant($tenantId, $product->id, self::PAGE, 0));
    }

    /** @return list<Order> */
    public function orders(StaffIdentity $staff, string $tenantId, ?string $productCode, AccessMotive $motive): array
    {
        return $this->across($staff, $tenantId, $productCode, $motive, 'orders', fn (Product $product): array => $this->sales->listOrders($tenantId, $product->id, self::PAGE, 0));
    }

    /** @return list<Quote> */
    public function quotes(StaffIdentity $staff, string $tenantId, ?string $productCode, AccessMotive $motive): array
    {
        return $this->across($staff, $tenantId, $productCode, $motive, 'quotes', fn (Product $product): array => $this->sales->listQuotes($tenantId, $product->id, self::PAGE, 0));
    }

    /** @return list<Project> */
    public function projects(StaffIdentity $staff, string $tenantId, ?string $productCode, AccessMotive $motive): array
    {
        return $this->across($staff, $tenantId, $productCode, $motive, 'projects', fn (Product $product): array => $this->projects->listForTenant($tenantId, $product->id, self::PAGE, 0));
    }

    /**
     * Jobs are the one read whose repository already answers across products
     * — a job may belong to a tenant and no product — so it is asked once.
     *
     * @return list<Job>
     */
    public function jobs(StaffIdentity $staff, string $tenantId, ?string $productCode, AccessMotive $motive): array
    {
        $tenant = $this->open($staff, $tenantId, 'jobs', $motive);
        $narrowed = $this->narrow($tenant, $productCode);

        $this->recordRead($staff, $tenant, $narrowed, 'jobs', $productCode, $motive);

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
    public function taxProfile(StaffIdentity $staff, string $tenantId, AccessMotive $motive): CustomerTaxProfile
    {
        $tenant = $this->open($staff, $tenantId, 'tax_profile', $motive);

        $this->recordRead($staff, $tenant, null, 'tax_profile', null, $motive);

        return $this->taxation->profileFor($tenant->id);
    }

    /**
     * The read, once per product the tenant holds — or once, on the one the
     * code names — with the access recorded first. A code the tenant does
     * not hold reads nothing: nobody is on it here, which is the true
     * answer, and never somebody else's rows.
     *
     * @template T
     *
     * @param callable(Product): list<T> $read
     *
     * @return list<T>
     */
    private function across(StaffIdentity $staff, string $tenantId, ?string $productCode, AccessMotive $motive, string $resource, callable $read): array
    {
        $tenant = $this->open($staff, $tenantId, $resource, $motive);
        $narrowed = $this->narrow($tenant, $productCode);

        $this->recordRead($staff, $tenant, $narrowed, $resource, $productCode, $motive);

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

    private function open(StaffIdentity $staff, string $tenantId, string $resource, AccessMotive $motive): \App\Tenant\Domain\Tenant
    {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            $this->trail->record(new StaffAccess(
                $staff->userId,
                null,
                null,
                'READ_MISS',
                $resource,
                $tenantId,
                StaffPermission::TENANTS_READ,
                [],
                $motive,
            ));

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

    private function recordRead(StaffIdentity $staff, \App\Tenant\Domain\Tenant $tenant, ?Product $narrowed, string $resource, ?string $productCode, AccessMotive $motive): void
    {
        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            $narrowed?->id,
            'READ',
            $resource,
            $tenant->id,
            StaffPermission::TENANTS_READ,
            $productCode === null ? [] : ['product' => $productCode],
            $motive,
        ));
    }
}
