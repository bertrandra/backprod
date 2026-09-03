<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Gapless legal numbering, for the documents that need it.
 *
 * Invoices and credit notes are both numbered in unbroken per-year sequences,
 * in separate series — sharing one would leave both with gaps, since a gap in
 * a legal sequence is a question from an auditor rather than a cosmetic
 * problem.
 *
 * The mechanism is deliberately not a PostgreSQL sequence. Sequences are fast
 * because they do not participate in transactions, so a rolled-back document
 * burns its number permanently. Here the next number is `max + 1`, read and
 * written while holding a lock on the table, inside the caller's transaction.
 *
 * The table name reaches SQL by interpolation, which is why it comes from a
 * fixed map rather than from an argument: an identifier cannot be a bound
 * parameter, so the only safe source is one that no caller can influence.
 */
final class DocumentNumbering
{
    public const INVOICE = 'invoice';
    public const CREDIT_NOTE = 'credit_note';

    /**
     * @var array<string, array{table: string, prefix: string}>
     */
    private const SERIES = [
        self::INVOICE => ['table' => 'invoices', 'prefix' => ''],
        // "AV" for avoir, the French term, so a credit note is never mistaken
        // for an invoice at a glance in a ledger export.
        self::CREDIT_NOTE => ['table' => 'credit_notes', 'prefix' => 'AV'],
    ];

    /**
     * The next number in a series. Must be called inside a transaction: the
     * lock it takes is released at commit, and without one the read and the
     * write are not the same moment.
     */
    public static function next(Connection $connection, string $series): string
    {
        $definition = self::SERIES[$series] ?? throw new InvalidArgumentException(
            'Unknown document series: ' . $series,
        );

        $table = $definition['table'];
        $year = (new DateTimeImmutable())->format('Y');
        $prefix = $definition['prefix'] . $year;

        // A table-level lock for the shortest possible moment. Issuing is
        // rare and its correctness is legal rather than merely important, so
        // serialising it is the right trade.
        $connection->executeStatement(
            sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', $table),
        );

        $highest = $connection->fetchOne(
            sprintf(
                <<<'SQL'
                    SELECT max(substring(number from '\d+$')::bigint)
                      FROM %s
                     WHERE number LIKE :prefix
                    SQL,
                $table,
            ),
            ['prefix' => $prefix . '-%'],
        );

        $next = (is_numeric($highest) ? (int) $highest : 0) + 1;

        return sprintf('%s-%06d', $prefix, $next);
    }
}
