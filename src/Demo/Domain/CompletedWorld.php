<?php

declare(strict_types=1);

namespace App\Demo\Domain;

use App\Billing\Domain\Invoice;
use App\Commerce\Domain\Subscription;

/**
 * What completing a seeded deployment added, and whether it holds
 * (2026-11-17).
 *
 * The answer to `DemoSeeder::complete()`, read by the installer's upgrade
 * page and `bin/demo-upgrade.php` alike, so both say the same thing from the
 * same source. Empty when the deployment already held everything the world
 * names — which is the second run's answer, and the ordinary one.
 */
final class CompletedWorld
{
    /**
     * @param list<string>        $tenants       the organisations added, by slug
     * @param list<string>        $people        the people created, by key
     * @param list<string>        $keptPeople    the people whose address already had
     *                                           an account, reused with their own password
     * @param list<Subscription>  $subscriptions
     * @param list<Invoice>       $invoices
     * @param array<string, bool> $checks        check => holds
     */
    public function __construct(
        public readonly array $tenants,
        public readonly array $people,
        public readonly array $keptPeople,
        public readonly array $subscriptions,
        public readonly array $invoices,
        public readonly array $checks,
    ) {
    }

    public static function nothing(): self
    {
        return new self([], [], [], [], [], []);
    }

    public function addedNothing(): bool
    {
        return $this->tenants === [];
    }

    public function holds(): bool
    {
        return $this->failures() === [];
    }

    /** @return list<string> the checks that did not hold */
    public function failures(): array
    {
        return array_keys(array_filter($this->checks, static fn (bool $ok): bool => !$ok));
    }

    /**
     * One line per thing added, for a page or a terminal.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $lines = [];

        foreach ($this->tenants as $slug) {
            $lines[] = DemoWorld::describeAddition($slug);
        }

        foreach ($this->people as $key) {
            $lines[] = 'created ' . DemoWorld::email($key) . ' (' . DemoWorld::PEOPLE[$key]['role'] . ')';
        }

        foreach ($this->keptPeople as $key) {
            $lines[] = 'kept ' . DemoWorld::email($key) . ', which already had an account, with its own password';
        }

        foreach ($this->invoices as $invoice) {
            $lines[] = 'invoice ' . ($invoice->number ?? '(no number)') . ' ' . $invoice->status;
        }

        return $lines;
    }
}
