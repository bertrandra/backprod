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

    /**
     * @param list<string> $capabilities
     * @param list<string> $roles
     */
    private function context(array $capabilities, array $roles = ['USER']): RequestContext
    {
        return new RequestContext('u1', 'p1', 't1', $roles, $capabilities);
    }
}
