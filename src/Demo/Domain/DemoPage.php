<?php

declare(strict_types=1);

namespace App\Demo\Domain;

/**
 * The public demonstration page (2026-09-18): a switch, and what it shows.
 *
 * What it shows is what everywhere else is a membership's answer —
 * which products exist, which organisations, who is in them and as what.
 * So the page is off until a platform administrator turns it on, and the
 * read below is refused while it is off rather than answered empty: an
 * empty page would still say the switch exists.
 */
interface DemoPage
{
    public function isPublished(): bool;

    public function publish(bool $published): void;

    /**
     * Everything the page shows, in one read: the products with the offers
     * on sale right now, and the organisations with their holdings, their
     * live subscriptions and their people. People are the ACTIVE members,
     * with the roles they hold in the organisation.
     *
     * @return array{
     *     products: list<array{
     *         code: string, name: string,
     *         offers: list<array{code: string, name: string, plan: string, billing_period: string, price: array{minor_units: int, currency: string}, publicly_listed: bool}>
     *     }>,
     *     tenants: list<array{
     *         slug: string, name: string, is_default: bool, join_policy: string,
     *         products: list<string>,
     *         subscriptions: list<array{product: string, offer: string, plan: string, status: string}>,
     *         members: list<array{display_name: string|null, email: string|null, roles: list<string>}>
     *     }>
     * }
     */
    public function contents(): array;
}
