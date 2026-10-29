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

    /**
     * Organisations that are not the demonstration's and that a legal
     * document names — an issued invoice, a credit note, a VAT fact or a
     * closed reporting period, as customer or as issuer. A reset must not
     * wipe them: a product code is the demonstration's by name only, and
     * somebody who bought on it has documents the law requires be kept.
     *
     * @return list<string> their slugs
     */
    public function foreignDocuments(): array;

    /**
     * A person's stored credential, read before a reset so the caller's own
     * password survives it — or null when they have none.
     *
     * @return array{email: string, hash: string}|null
     */
    public function credentialOf(string $userId): ?array;

    /**
     * Puts a stored hash back on the credential with that address, if the
     * rebuilt world has one. Returns the address as stored, or null when
     * nobody in the world has it.
     */
    public function restoreCredential(string $email, string $passwordHash): ?string;

    /**
     * Runs the work in one database transaction: all of it, or none.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;

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

    /**
     * Hangs an uploaded picture on a showcase block (2026-10-08).
     *
     * The bytes and the asset row are the showcase module's to write —
     * sniffed, refused when not a picture, put in storage — so the seeder
     * uploads through that door and the fixtures only record which block
     * shows which asset, the one column that is theirs.
     */
    public function attachPicture(string $blockId, string $assetId): void;

    /**
     * The ids of the demonstration's products as they stand, by code —
     * empty where the world is not seeded. What a reset needs before the
     * wipe: the pictures those products uploaded live outside the database,
     * and a truncate would leave the bytes behind with nothing naming them.
     *
     * @return array<string, string>
     */
    public function productIds(): array;
}
