<?php

declare(strict_types=1);

namespace App\Audit\Domain;

/**
 * Where everything worth answering for is written down (§30).
 *
 * A port, and the reason matters more here than for most: an implementation
 * that joins the caller's open transaction is what lets the act and the
 * record of it commit together. An audit that can be lost while its act
 * succeeds is not an audit, it is a log line — the same reasoning that made
 * `applyIssue` and `applyActivate` participating rather than transactional.
 *
 * Reading is deliberately not on this interface. Writing happens everywhere
 * and reading happens on one admin surface, so a module that records
 * something should not, by depending on this, acquire the ability to read
 * the whole platform's trail.
 */
interface AuditLog
{
    /**
     * Records an entry on the caller's transaction, opening none of its own.
     *
     * Named `apply` for the same reason the others are: it is only correct
     * inside a transaction somebody else is holding, and the name is what
     * stops it being called anywhere else.
     */
    public function applyRecord(AuditRecord $record): void;
}
