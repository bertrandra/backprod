<?php

declare(strict_types=1);

namespace App\Privacy\Infrastructure;

use App\Audit\Domain\AuditLog;
use App\Audit\Domain\AuditRecord;
use App\Privacy\Domain\ErasureOutcome;
use App\Privacy\Domain\ErasureRepository;
use App\Privacy\Domain\RetentionGround;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\NotFoundException;
use Doctrine\DBAL\Connection;

/**
 * The erasure, as the schema permits it to be done.
 *
 * Order matters inside the transaction. The audit log is anonymised *before*
 * the user row, because its trigger accepts exactly one shape of update —
 * clear `user_id`, stamp `actor_forgotten_at`, touch nothing else — and it
 * finds those rows by the id that is about to stop meaning anything.
 */
final class PostgresErasureRepository implements ErasureRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLog $audit,
    ) {
    }

    public function alreadyErased(string $userId): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }

        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM users WHERE id = CAST(:id AS uuid) AND erased_at IS NOT NULL)',
            ['id' => $userId],
        );
    }

    public function erase(
        string $subjectUserId,
        string $requestedByUserId,
        ?string $requestId,
    ): ErasureOutcome {
        return $this->connection->transactional(
            function () use ($subjectUserId, $requestedByUserId, $requestId): ErasureOutcome {
                $retained = $this->countWhatIsKept($subjectUserId);
                $erased = $this->clearWhatMayGo($subjectUserId);

                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO erasure_requests
                            (subject_user_id, requested_by, erased, retained)
                        VALUES (CAST(:subject AS uuid), CAST(:by AS uuid),
                                CAST(:erased AS jsonb), CAST(:retained AS jsonb))
                        SQL,
                    [
                        'subject' => $subjectUserId,
                        'by' => $requestedByUserId,
                        'erased' => self::encode($erased),
                        'retained' => self::encode($retained),
                    ],
                );

                // Participating, and joined to this transaction on purpose.
                // The UPDATE above forgets rows whose actor is the *subject*;
                // this INSERT names the administrator, so the two do not
                // collide — and the erasure cannot commit unattributed.
                $this->audit->applyRecord(AuditRecord::of(
                    ErasureRepository::ACTION_ERASED,
                    'user',
                    $subjectUserId,
                    null,
                    null,
                    $requestedByUserId,
                    null,
                    $requestId,
                    ['erased' => $erased, 'retained' => $retained],
                ));

                return new ErasureOutcome($subjectUserId, $erased, $retained);
            },
        );
    }

    /**
     * Counted before anything is cleared, because afterwards some of these
     * rows can no longer be found by this person's id — that being the point.
     *
     * @return array<string, array{count: int, ground: string}>
     */
    private function countWhatIsKept(string $userId): array
    {
        return [
            'audit_entries' => [
                'count' => $this->countBy('audit_log', 'user_id', $userId),
                'ground' => RetentionGround::AUDIT,
            ],
            'financial_events' => [
                'count' => $this->countBy('financial_events', 'actor_user_id', $userId),
                'ground' => RetentionGround::ACCOUNTING,
            ],
            'subscription_events' => [
                'count' => $this->countBy('subscription_events', 'actor_user_id', $userId),
                'ground' => RetentionGround::TRACEABILITY,
            ],
            'orders_placed' => [
                'count' => $this->countBy('orders', 'placed_by', $userId),
                'ground' => RetentionGround::TRACEABILITY,
            ],
            'quotes_raised' => [
                'count' => $this->countBy('quotes', 'created_by', $userId),
                'ground' => RetentionGround::TRACEABILITY,
            ],
            'vat_periods_closed' => [
                'count' => $this->countBy('vat_reporting_periods', 'closed_by', $userId),
                'ground' => RetentionGround::FISCAL,
            ],
            'legal_notices_sent' => [
                'count' => $this->countLegalNotices($userId),
                'ground' => RetentionGround::LEGAL_NOTICE,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function clearWhatMayGo(string $userId): array
    {
        // The audit log first: its trigger permits one update and finds its
        // rows by an id that is about to name nobody.
        $auditActors = (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE audit_log
                   SET user_id = NULL, actor_forgotten_at = now()
                 WHERE user_id = CAST(:id AS uuid)
                SQL,
            ['id' => $userId],
        );

        // Their own words. The same shape a deleted message already takes —
        // the body goes, the message keeps its place in the sequence, and the
        // conversation does not develop a hole where a reply used to be.
        $messages = (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE messages
                   SET body = '', deleted_at = coalesce(deleted_at, now())
                 WHERE author_user_id = CAST(:id AS uuid)
                   AND deleted_at IS NULL
                SQL,
            ['id' => $userId],
        );

        // And the identity itself. The tombstone is the person's own id, so
        // it is unique without a second thought and can never be presented by
        // a token: a real subject is the identity provider's, never ours.
        $identity = (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE users
                   SET erased_at = now(),
                       email = NULL,
                       display_name = NULL,
                       auth_subject = 'erased:' || id,
                       updated_at = now()
                 WHERE id = CAST(:id AS uuid)
                   AND erased_at IS NULL
                SQL,
            ['id' => $userId],
        );

        if ($identity !== 1) {
            // Either the person does not exist or somebody erased them while
            // this was running. Either way the transaction rolls back, which
            // is the only honest answer: the audit entries above must not be
            // forgotten on behalf of an erasure that did not happen.
            throw new NotFoundException('There is no such person to erase.');
        }

        return [
            'identity' => $identity,
            'message_bodies' => $messages,
            'audit_actors' => $auditActors,
        ];
    }

    private function countBy(string $table, string $column, string $userId): int
    {
        // The table and column are literals from the map above, never input.
        return (int) $this->connection->fetchOne(
            sprintf('SELECT count(*) FROM %s WHERE %s = CAST(:id AS uuid)', $table, $column),
            ['id' => $userId],
        );
    }

    private function countLegalNotices(string $userId): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*)
                  FROM notification_deliveries d
                  JOIN notifications n ON n.id = d.notification_id
                 WHERE n.recipient_user_id = CAST(:id AS uuid)
                   AND n.legal_effect
                   AND d.rendered_body IS NOT NULL
                SQL,
            ['id' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
