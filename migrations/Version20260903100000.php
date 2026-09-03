<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conversations and messages (§12.3).
 *
 * Two needs, one model: a tenant's own members talking to each other, and a
 * tenant talking to the platform. What separates them is `kind`, and the
 * separation is enforced here rather than in a service, because a messaging
 * feature is the first resource two different tenants might plausibly both
 * touch — and the failure mode is somebody reading a conversation that is not
 * theirs.
 *
 * Four invariants live in this file rather than in PHP:
 *
 *   1. a conversation names a (tenant, product) pair, like every other
 *      resource — non-negotiable #8 and §12.1;
 *   2. the author of a message is a participant of the conversation, by
 *      foreign key, so writing into a thread one does not belong to is
 *      refused by the database;
 *   3. `seq` is unique within a conversation, so ordering and paging are
 *      stable and a read watermark means something;
 *   4. a STAFF participant implies a SUPPORT conversation, so platform staff
 *      cannot appear in a tenant's internal thread.
 *
 * The fourth is the one that would be easiest to get wrong in a service and
 * hardest to notice: it is carried by a composite foreign key onto
 * `conversations (id, kind)`, so the participant row physically cannot
 * disagree with the conversation it belongs to.
 */
final class Version20260903100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conversations, participants and messages';
    }

    public function up(Schema $schema): void
    {
        // UNIQUE (id, kind) is redundant as a uniqueness claim — id is
        // already the primary key — and exists so that `kind` can be the
        // target of the composite foreign key below. That is what lets a
        // participant row carry the conversation's kind without being able
        // to lie about it.
        $this->addSql(<<<'SQL'
            CREATE TABLE conversations (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id UUID NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
                kind TEXT NOT NULL,
                subject TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'OPEN',
                created_by UUID REFERENCES users (id) ON DELETE SET NULL,
                closed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT conversations_kind_known CHECK (kind IN ('INTERNAL', 'SUPPORT')),
                CONSTRAINT conversations_status_known CHECK (status IN ('OPEN', 'CLOSED')),
                CONSTRAINT conversations_subject_not_blank CHECK (btrim(subject) <> ''),
                CONSTRAINT conversations_closed_has_moment
                    CHECK (status <> 'CLOSED' OR closed_at IS NOT NULL),
                CONSTRAINT conversations_id_kind_unique UNIQUE (id, kind)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX conversations_tenant_idx
                ON conversations (tenant_id, product_id, updated_at DESC)
            SQL);

        // `conversation_kind` is denormalised from the conversation and held
        // in place by the composite foreign key, so the CHECK below can see
        // both halves of the rule at once. Without it, "a STAFF participant
        // implies a SUPPORT conversation" could only be a query somebody
        // remembers to run.
        //
        // Rows are never deleted. Leaving sets `left_at`, because a departed
        // participant's messages must keep their author, and the foreign key
        // from `messages` would refuse the delete anyway.
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_participants (
                conversation_id UUID NOT NULL,
                user_id UUID NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                conversation_kind TEXT NOT NULL,
                participant_kind TEXT NOT NULL,
                last_read_seq BIGINT NOT NULL DEFAULT 0,
                joined_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                left_at TIMESTAMPTZ,
                PRIMARY KEY (conversation_id, user_id),
                CONSTRAINT conversation_participants_conversation_fk
                    FOREIGN KEY (conversation_id, conversation_kind)
                    REFERENCES conversations (id, kind) ON DELETE CASCADE,
                CONSTRAINT conversation_participants_kind_known
                    CHECK (participant_kind IN ('MEMBER', 'STAFF')),
                CONSTRAINT conversation_participants_staff_only_in_support
                    CHECK (participant_kind <> 'STAFF' OR conversation_kind = 'SUPPORT'),
                CONSTRAINT conversation_participants_watermark_not_negative
                    CHECK (last_read_seq >= 0),
                CONSTRAINT conversation_participants_author_fk_target
                    UNIQUE (conversation_id, user_id, participant_kind)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX conversation_participants_user_idx
                ON conversation_participants (user_id, conversation_id)
            SQL);

        // The author foreign key names three columns, not two. Referencing
        // (conversation_id, user_id) alone would prove the author belongs to
        // the thread; adding `author_kind` also proves they are writing as
        // what they actually are, so a member cannot post as STAFF.
        //
        // A SYSTEM message has no author. The composite key is MATCH SIMPLE,
        // so a NULL author_user_id skips the check entirely — which is why
        // the CHECK below has to say that only SYSTEM may have one.
        //
        // A deleted message keeps its row and loses its body: the thread must
        // keep its order (§12.3), and a message is not an accounting document
        // — RGPD erasure wins here, unlike an invoice.
        $this->addSql(<<<'SQL'
            CREATE TABLE messages (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                conversation_id UUID NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
                seq BIGINT NOT NULL,
                author_user_id UUID,
                author_kind TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                edited_at TIMESTAMPTZ,
                deleted_at TIMESTAMPTZ,
                CONSTRAINT messages_seq_unique UNIQUE (conversation_id, seq),
                CONSTRAINT messages_seq_positive CHECK (seq > 0),
                CONSTRAINT messages_author_kind_known
                    CHECK (author_kind IN ('MEMBER', 'STAFF', 'SYSTEM')),
                CONSTRAINT messages_author_present_unless_system CHECK (
                    (author_kind = 'SYSTEM' AND author_user_id IS NULL)
                    OR (author_kind <> 'SYSTEM' AND author_user_id IS NOT NULL)
                ),
                CONSTRAINT messages_live_body_not_blank
                    CHECK (deleted_at IS NOT NULL OR btrim(body) <> ''),
                CONSTRAINT messages_author_is_a_participant
                    FOREIGN KEY (conversation_id, author_user_id, author_kind)
                    REFERENCES conversation_participants
                        (conversation_id, user_id, participant_kind)
                    ON DELETE RESTRICT
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX messages_thread_idx ON messages (conversation_id, seq)
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('messages.read', 'Read the tenant''s conversations'),
                ('messages.write', 'Start a conversation and post to one')
            SQL);

        // Both roles may talk: messaging is not an administrative act, and a
        // support thread a member cannot answer is not a conversation.
        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE r.code IN ('TENANT_ADMIN', 'USER')
              AND p.code IN ('messages.read', 'messages.write')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
            WHERE permission_id IN (
                SELECT id FROM permissions WHERE code IN ('messages.read', 'messages.write')
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('messages.read', 'messages.write')");
        $this->addSql('DROP TABLE IF EXISTS messages');
        $this->addSql('DROP TABLE IF EXISTS conversation_participants');
        $this->addSql('DROP TABLE IF EXISTS conversations');
    }
}
