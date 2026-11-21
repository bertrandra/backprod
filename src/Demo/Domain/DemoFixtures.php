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
     * The organisations the world names that this deployment does not hold,
     * by slug, in the world's order (2026-11-17).
     *
     * @return list<string>
     */
    public function missingTenants(): array;

    /**
     * Writes the organisations named — their rows, holdings and palettes,
     * people with their credential, memberships, billing and tax profiles —
     * onto a deployment that already holds the demonstration (2026-11-17).
     *
     * Products, catalogues and offers are read back by code and never
     * written; a person whose address already has an account is reused as
     * they are and named in {@see DemoStructure::$existingPeople}. Nothing
     * belonging to another organisation is touched.
     *
     * @param list<string> $slugs
     * @param string       $passwordHash what every new credential stores
     */
    public function extend(array $slugs, string $passwordHash): DemoStructure;

    /**
     * What the story the world tells has and this deployment's page lacks, as
     * keys {@see DemoWorld::describeAddition()} can say aloud (2026-11-21).
     *
     * Three kinds of thing, each **absent** rather than different:
     *
     *  - `story:<product>:rows:<BAND>` — the world writes rows for this band
     *    and the product has none of that kind;
     *  - `story:<product>:heading:<BAND>` — the world titles this band and the
     *    product has no heading row for it;
     *  - `story:<product>:layout:<BAND>` — the world lays this band out and
     *    the product's heading row says nothing about its layout.
     *
     * A band with rows is never offered more rows, and a heading or a layout
     * somebody wrote is never offered another: completing adds what was never
     * said and corrects nothing. Bands whose rows name a picture are left to
     * a seed — the bytes go through the showcase module's upload, which is
     * the seeder's business on a build and nobody's here.
     *
     * Empty where the product is not on this deployment.
     *
     * @return list<string>
     */
    public function missingStory(): array;

    /**
     * Writes what {@see missingStory()} named, and nothing else (2026-11-21).
     *
     * Each key is re-checked against the database as it is written, so a key
     * that stopped being true between the question and the answer — somebody
     * wrote that band in the console meanwhile — is skipped rather than
     * written over.
     *
     * @param list<string> $keys
     */
    public function extendStory(array $keys): void;

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
