<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tenant\Domain\TenantMember;
use App\Tenant\Domain\TenantMemberRepository;
use App\Tenant\Service\MemberAdministration;
use App\Tests\Support\InMemoryTenantMemberRepository;
use App\Tests\Support\RecordingProductEvents;
use App\User\Domain\PlatformUser;
use App\User\Infrastructure\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAdministration::class)]
final class MemberAdministrationTest extends TestCase
{
    private const TENANT = 'tenant-1';
    private const PRODUCT = 'product-1';

    public function testAddingSomeoneWhoHasNeverSignedInIsRefused(): void
    {
        $admin = $this->administration();

        try {
            $admin->add(self::TENANT, self::PRODUCT, 'stranger@example.test', ['USER']);
            self::fail('Expected the user to be missing.');
        } catch (NotFoundException $e) {
            self::assertSame('USER_NOT_FOUND', $e->errorCode());
        }
    }

    public function testAddingAnExistingUserGrantsTheRequestedRoles(): void
    {
        $members = $this->members();
        $admin = $this->administration($members, [
            new PlatformUser('u-new', 'sub-new', 'new@example.test'),
        ]);

        $member = $admin->add(self::TENANT, self::PRODUCT, 'new@example.test', ['USER']);

        self::assertSame('u-new', $member->userId);
        self::assertSame(['USER'], $member->roles);
    }

    public function testEmailMatchingIgnoresCase(): void
    {
        $admin = $this->administration(null, [
            new PlatformUser('u-new', 'sub-new', 'New@Example.Test'),
        ]);

        self::assertSame('u-new', $admin->add(self::TENANT, self::PRODUCT, 'new@example.test', ['USER'])->userId);
    }

    public function testUnknownRolesAreRejectedBeforeAnythingIsWritten(): void
    {
        $members = $this->members();
        $admin = $this->administration($members, [
            new PlatformUser('u-new', 'sub-new', 'new@example.test'),
        ]);

        try {
            $admin->add(self::TENANT, self::PRODUCT, 'new@example.test', ['ROOT']);
            self::fail('Expected the role to be rejected.');
        } catch (BadRequestException $e) {
            self::assertSame('UNKNOWN_ROLE', $e->errorCode());
        }

        self::assertNull($members->findMember(self::TENANT, self::PRODUCT, 'u-new'));
    }

    public function testAddingAnExistingMemberTwiceIsAConflict(): void
    {
        $members = $this->members([
            new TenantMember('u-existing', 'existing@example.test', null, ['USER']),
        ]);

        $admin = $this->administration($members, [
            new PlatformUser('u-existing', 'sub-existing', 'existing@example.test'),
        ]);

        $this->expectException(ConflictException::class);
        $admin->add(self::TENANT, self::PRODUCT, 'existing@example.test', ['USER']);
    }

    /**
     * A tenant with no administrator cannot appoint one, so it would be
     * permanently stuck. This is the invariant that prevents it.
     */
    public function testTheLastAdministratorCannotBeRemoved(): void
    {
        $members = $this->members([
            new TenantMember('u-admin', 'admin@example.test', null, ['TENANT_ADMIN']),
            new TenantMember('u-member', 'member@example.test', null, ['USER']),
        ]);

        try {
            $this->administration($members)->remove(self::TENANT, self::PRODUCT, 'u-admin');
            self::fail('Expected the removal to be refused.');
        } catch (ConflictException $e) {
            self::assertSame('LAST_ADMINISTRATOR', $e->errorCode());
        }
    }

    public function testTheLastAdministratorCannotBeDemoted(): void
    {
        $members = $this->members([
            new TenantMember('u-admin', 'admin@example.test', null, ['TENANT_ADMIN']),
        ]);

        $this->expectException(ConflictException::class);
        $this->administration($members)->replaceRoles(self::TENANT, self::PRODUCT, 'u-admin', ['USER']);
    }

    public function testAnAdministratorCanBeRemovedWhileAnotherRemains(): void
    {
        $members = $this->members([
            new TenantMember('u-admin-1', 'a1@example.test', null, ['TENANT_ADMIN']),
            new TenantMember('u-admin-2', 'a2@example.test', null, ['TENANT_ADMIN']),
        ]);

        $this->administration($members)->remove(self::TENANT, self::PRODUCT, 'u-admin-1');

        self::assertNull($members->findMember(self::TENANT, self::PRODUCT, 'u-admin-1'));
        self::assertNotNull($members->findMember(self::TENANT, self::PRODUCT, 'u-admin-2'));
    }

    /**
     * The submitted list is the whole truth, so an omitted role is removed.
     */
    public function testReplacingRolesRemovesOnesNotSubmitted(): void
    {
        $members = $this->members([
            new TenantMember('u-admin-1', 'a1@example.test', null, ['TENANT_ADMIN']),
            new TenantMember('u-both', 'both@example.test', null, ['TENANT_ADMIN', 'USER']),
        ]);

        $updated = $this->administration($members)
            ->replaceRoles(self::TENANT, self::PRODUCT, 'u-both', ['USER']);

        self::assertSame(['USER'], $updated->roles);
    }

    /**
     * Members are addressed within the caller's tenant, so someone else's
     * member is simply not found here.
     */
    public function testAMemberOfAnotherTenantIsNotFound(): void
    {
        $members = $this->members([
            new TenantMember('u-mine', 'mine@example.test', null, ['USER']),
        ]);

        try {
            $this->administration($members)->remove(self::TENANT, self::PRODUCT, 'u-theirs');
            self::fail('Expected the member to be missing.');
        } catch (NotFoundException $e) {
            self::assertSame('MEMBER_NOT_FOUND', $e->errorCode());
        }
    }

    /**
     * @param list<TenantMember> $seed
     */
    private function members(array $seed = []): InMemoryTenantMemberRepository
    {
        return new InMemoryTenantMemberRepository($seed);
    }

    /**
     * @param list<PlatformUser> $users
     */
    private function administration(?TenantMemberRepository $members = null, array $users = []): MemberAdministration
    {
        return new MemberAdministration(
            $members ?? $this->members(),
            new InMemoryUserRepository($users),
            new RecordingProductEvents(),
        );
    }
}
