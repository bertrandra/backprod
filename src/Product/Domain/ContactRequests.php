<?php

declare(strict_types=1);

namespace App\Product\Domain;

/** Where the requests sent through product pages are kept (2026-11-23). */
interface ContactRequests
{
    /**
     * Keep one, and answer it as kept — with its id and the moment it arrived.
     */
    public function add(
        string $productId,
        string $name,
        string $email,
        string $message,
        string $consent,
        string $locale,
    ): ContactRequest;

    /**
     * Newest first; those still to answer before those answered.
     *
     * @return array{requests: list<ContactRequest>, total: int, open: int}
     */
    public function page(?string $productId, int $limit, int $offset): array;

    /**
     * Mark one answered, by whom. Null where there is no such request; a
     * request already answered keeps who answered it first.
     */
    public function handle(string $id, string $staffUserId): ?ContactRequest;
}
