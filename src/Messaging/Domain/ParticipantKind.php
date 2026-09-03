<?php

declare(strict_types=1);

namespace App\Messaging\Domain;

/**
 * Who someone is in a conversation — which is not the same question as who
 * they are on the platform.
 *
 * A message carries the kind its author wrote as, and the database checks it
 * against the participant row: the foreign key names
 * (conversation, user, kind), so a member cannot post as STAFF even by
 * sending the field.
 */
final class ParticipantKind
{
    public const MEMBER = 'MEMBER';
    public const STAFF = 'STAFF';

    /** Not a participant: an author kind only, for what the platform says itself. */
    public const SYSTEM = 'SYSTEM';
}
