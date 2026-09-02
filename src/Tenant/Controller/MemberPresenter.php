<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Tenant\Domain\TenantMember;

/**
 * One shape for a member, wherever it is returned.
 */
final class MemberPresenter
{
    /**
     * @return array{user_id: string, email: string|null, display_name: string|null, roles: list<string>}
     */
    public static function one(TenantMember $member): array
    {
        return [
            'user_id' => $member->userId,
            'email' => $member->email,
            'display_name' => $member->displayName,
            'roles' => $member->roles,
        ];
    }

    /**
     * @param list<TenantMember> $members
     *
     * @return list<array{user_id: string, email: string|null, display_name: string|null, roles: list<string>}>
     */
    public static function many(array $members): array
    {
        return array_map(self::one(...), $members);
    }
}
