<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Auth\Domain\JoinDecision;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\NotFoundException;
use App\Tenant\Domain\JoinRequests;
use App\Tenant\Domain\TenantMember;

/**
 * The organisation's side of people arriving by themselves (2026-09-17):
 * who is waiting, letting them in or not, and the policy that decides who
 * waits at all.
 *
 * Accepting is the whole of what an administrator does to a request; the
 * membership was written at sign-up, as a USER on every product the
 * organisation holds, and accepting turns it live. Declining removes it —
 * the person keeps their account and may ask again, or be invited.
 */
final class Joining
{
    public function __construct(private readonly JoinRequests $requests)
    {
    }

    /** @return list<TenantMember> */
    public function requests(string $tenantId): array
    {
        return $this->requests->pendingIn($tenantId);
    }

    public function accept(string $tenantId, string $userId): void
    {
        if (!$this->requests->accept($tenantId, $userId)) {
            throw new NotFoundException('Nobody with that id is waiting to join.', [], 'JOIN_REQUEST_NOT_FOUND');
        }
    }

    public function decline(string $tenantId, string $userId): void
    {
        if (!$this->requests->decline($tenantId, $userId)) {
            throw new NotFoundException('Nobody with that id is waiting to join.', [], 'JOIN_REQUEST_NOT_FOUND');
        }
    }

    /** @return array{policy: string, domains: list<string>} */
    public function policy(string $tenantId): array
    {
        return $this->requests->policyOf($tenantId);
    }

    /**
     * @param list<string> $domains
     *
     * @return array{policy: string, domains: list<string>}
     */
    public function setPolicy(string $tenantId, string $policy, array $domains): array
    {
        if (!in_array($policy, JoinDecision::policies(), true)) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'join_policy', 'requirement' => 'must be one of ' . implode(', ', JoinDecision::policies())],
            );
        }

        $clean = [];

        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));

            if (preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/', $domain) !== 1) {
                throw new BadRequestException(
                    'VALIDATION_FAILED',
                    'The request body is not valid.',
                    ['field' => 'join_domains', 'requirement' => 'must be domain names, such as acme.example'],
                );
            }

            $clean[] = $domain;
        }

        if ($policy === JoinDecision::DOMAIN && $clean === []) {
            throw new BadRequestException(
                'JOIN_DOMAINS_REQUIRED',
                'Admitting by domain needs at least one domain.',
                ['field' => 'join_domains', 'requirement' => 'must name at least one domain when the policy is DOMAIN'],
            );
        }

        $this->requests->setPolicy($tenantId, $policy, array_values(array_unique($clean)));

        return $this->requests->policyOf($tenantId);
    }
}
