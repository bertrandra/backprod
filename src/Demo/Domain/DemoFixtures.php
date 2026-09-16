<?php

declare(strict_types=1);

namespace App\Demo\Domain;

/**
 * The rows of the demonstration world — the part of seeding that is plain
 * structure with no invariant behind it: a product's name, a plan's rank, a
 * membership. What *has* an invariant — a subscription, an invoice — is not
 * written here; the seeder takes it through the services that own it.
 */
interface DemoFixtures
{
    /**
     * Product codes on this platform that are not the demonstration's. A
     * reset must not wipe them: those are somebody's real products.
     *
     * @return list<string>
     */
    public function foreignProducts(): array;

    /** Whether any of the demonstration's product codes already exists. */
    public function isSeeded(): bool;

    /**
     * Empties every business table, in one statement, leaving reference data
     * — permissions, roles, the platform's roles, EU VAT rates — untouched.
     * Returns how many tables were emptied.
     */
    public function wipe(): int;

    /**
     * Writes the structure — products, organisations, people with their
     * credential, memberships and staff grants, one catalogue per product,
     * the legal identities — in one transaction.
     *
     * @param string $passwordHash what every person's credential stores
     */
    public function write(string $passwordHash): DemoStructure;

    /**
     * What the rows say about themselves, read back: a seeder whose output
     * nobody checks is a fixture that drifts from the schema silently.
     *
     * @return array<string, bool> check => holds
     */
    public function verify(DemoStructure $structure): array;
}
