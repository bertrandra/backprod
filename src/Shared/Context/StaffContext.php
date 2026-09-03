<?php

declare(strict_types=1);

namespace App\Shared\Context;

use App\Shared\Exceptions\ForbiddenException;
use App\Staff\Domain\StaffIdentity;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * The resolved platform-staff authority of one request (§12.2).
 *
 * A third context type beside {@see IdentityContext} and {@see RequestContext},
 * and the reason is the same one that separates those two: a handler should
 * not be able to read something it was not given.
 *
 *   IdentityContext   who is calling
 *   RequestContext    who is calling, in which tenant and product
 *   StaffContext      who is calling, with what authority across tenants
 *
 * There is no tenant id here and there never will be. Staff routes take the
 * tenant as an explicit parameter, which is the one place this platform lets
 * a client name a tenant — and it is not the exception it looks like, because
 * the parameter only *names* the tenant while the platform role *authorises*
 * the access, and the access is written to the trail either way.
 *
 * The two axes never meet: holding this grants nothing on a tenant route,
 * and a RequestContext grants nothing here.
 */
final class StaffContext
{
    public const ATTRIBUTE = 'staff_context';

    public function __construct(public readonly StaffIdentity $identity)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);

        if (!$context instanceof self) {
            // A programming error: a staff route ran without staff resolution.
            throw new RuntimeException(
                'No staff context: this route ran outside the staff policy.',
            );
        }

        return $context;
    }

    public function userId(): string
    {
        return $this->identity->userId;
    }

    public function can(string $permission): bool
    {
        return $this->identity->can($permission);
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->can($permission)) {
            throw ForbiddenException::permissionDenied($permission);
        }
    }
}
