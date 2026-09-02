<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Context\RequestContext;
use App\Shared\Exceptions\ForbiddenException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContext::class)]
final class RequestContextTest extends TestCase
{
    public function testCapabilitiesAnswerAuthorisationQuestions(): void
    {
        $context = $this->context(['projects.read']);

        self::assertTrue($context->allows('projects.read'));
        self::assertFalse($context->allows('projects.write'));
    }

    public function testRequireCapabilityPassesSilentlyWhenGranted(): void
    {
        $this->expectNotToPerformAssertions();

        $this->context(['projects.write'])->requireCapability('projects.write');
    }

    public function testRequireCapabilityRefusesWithTheDocumentedCode(): void
    {
        try {
            $this->context([])->requireCapability('advanced_3d');
            self::fail('Expected the capability to be required.');
        } catch (ForbiddenException $e) {
            // The code and shape come straight from the §10.4 example.
            self::assertSame(403, $e->statusCode());
            self::assertSame('ENTITLEMENT_REQUIRED', $e->errorCode());
            self::assertSame(['capability' => 'advanced_3d'], $e->details());
        }
    }

    public function testRoleChecksAreExact(): void
    {
        $context = $this->context([], ['TENANT_ADMIN']);

        self::assertTrue($context->hasRole('TENANT_ADMIN'));
        self::assertFalse($context->hasRole('SUPER_ADMIN'));
        self::assertFalse($context->hasRole('tenant_admin'));
    }

    public function testPermissionsAnswerAuthorisationRatherThanRoleNames(): void
    {
        $context = $this->context([], ['USER'], ['members.read']);

        self::assertTrue($context->can('members.read'));
        self::assertFalse($context->can('members.manage'));
    }

    /**
     * A permission failure and an entitlement failure are different problems
     * with different remedies — one is a role change, the other a
     * subscription — so they must not share an error code.
     */
    public function testPermissionDenialIsDistinctFromAnEntitlementFailure(): void
    {
        try {
            $this->context([], ['USER'], ['members.read'])->requirePermission('members.manage');
            self::fail('Expected the permission to be required.');
        } catch (ForbiddenException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertSame('PERMISSION_DENIED', $e->errorCode());
            self::assertSame(['permission' => 'members.manage'], $e->details());
        }
    }

    /**
     * Holding a permission is not the same as the tenant having bought the
     * feature: an administrator with every permission is still refused a
     * capability the subscription does not include.
     */
    public function testPermissionsDoNotGrantCapabilities(): void
    {
        $context = $this->context([], ['TENANT_ADMIN'], ['members.manage', 'tenant.manage']);

        self::assertTrue($context->can('tenant.manage'));
        self::assertFalse($context->allows('advanced_3d'));
    }

    /**
     * @param list<string> $capabilities
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function context(array $capabilities, array $roles = ['USER'], array $permissions = []): RequestContext
    {
        return new RequestContext('u1', 'p1', 't1', $roles, $permissions, $capabilities);
    }
}
