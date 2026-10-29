<?php

declare(strict_types=1);

namespace App\Demo\Domain;

use App\Billing\Domain\Invoice;
use App\Commerce\Domain\Subscription;

/**
 * What seeding made, and whether it holds.
 *
 * Returned rather than printed, so the command line, the installer's success
 * page and the console's reset answer all describe the same thing from the
 * same source — a second copy of the password or the addresses anywhere else
 * is an instruction that goes wrong the day this one changes.
 */
final class SeededWorld
{
    /**
     * @param list<Subscription>  $subscriptions
     * @param list<Invoice>       $invoices
     * @param array<string, bool> $checks        check => holds
     * @param list<string>        $ownPassword   the accounts a reset left
     *                                           with the password they had
     *                                           before it — the caller's
     */
    public function __construct(
        public readonly DemoStructure $structure,
        public readonly array $subscriptions,
        public readonly array $invoices,
        public readonly array $checks,
        public readonly array $ownPassword = [],
    ) {
    }

    /** @param list<string> $emails */
    public function keepingOwnPassword(array $emails): self
    {
        return new self($this->structure, $this->subscriptions, $this->invoices, $this->checks, $emails);
    }

    /**
     * The accounts that sign in with {@see DemoWorld::PASSWORD}, which is in
     * this platform's source: everybody the world made, except whoever kept
     * their own.
     *
     * @return list<string>
     */
    public function withPublicPassword(): array
    {
        $kept = array_map(strtolower(...), $this->ownPassword);

        return array_values(array_filter(
            array_column($this->people(), 'email'),
            static fn (string $email): bool => !in_array(strtolower($email), $kept, true),
        ));
    }

    public function holds(): bool
    {
        return array_filter($this->checks, static fn (bool $ok): bool => !$ok) === [];
    }

    /** @return list<string> the checks that did not hold */
    public function failures(): array
    {
        return array_keys(array_filter($this->checks, static fn (bool $ok): bool => !$ok));
    }

    /**
     * The people, as somebody about to sign in needs them.
     *
     * @return list<array{key: string, name: string, email: string, scope: string, role: string, tenants: list<string>}>
     */
    public function people(): array
    {
        $people = [];

        foreach (DemoWorld::PEOPLE as $key => $person) {
            $people[] = [
                'key' => $key,
                'name' => $person['name'],
                'email' => DemoWorld::email($key),
                'scope' => $person['scope'],
                'role' => $person['role'],
                'tenants' => $person['tenants'],
            ];
        }

        return $people;
    }
}
