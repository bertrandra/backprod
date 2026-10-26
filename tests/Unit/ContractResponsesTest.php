<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\ContractResponses;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The check every integration test runs on its responses, seen failing.
 *
 * A checker that agrees with everything is indistinguishable from one that
 * works, and the integration suite only ever shows it agreeing. These are the
 * drifts it found on its first run (2026-10-26), each written back as the
 * response that was sent.
 */
#[CoversNothing]
final class ContractResponsesTest extends TestCase
{
    public function testAResponseAsDeclaredPasses(): void
    {
        $response = new JsonResponse(['marked' => 3]);

        self::assertSame([], ContractResponses::violations('POST', '/api/v1/notifications/read-all', $response));
    }

    public function testAMissingEnvelopeIsReported(): void
    {
        // What PUT /billing/profile sent: the profile bare, where the
        // contract and the screen read `profile`.
        $response = new JsonResponse(['legal_name' => 'Acme']);

        self::assertContains(
            'PUT /api/v1/billing/profile → 200: $.profile is required and missing',
            ContractResponses::violations('PUT', '/api/v1/billing/profile', $response),
        );
    }

    public function testAWrongTypeIsReported(): void
    {
        $response = new JsonResponse(['url' => '/x', 'expires_at' => 1_790_000_000], 201);

        self::assertContains(
            'POST /api/v1/assets/{assetId}/link → 201: $.expires_at is integer, the contract says string',
            ContractResponses::violations('POST', '/api/v1/assets/7f9c1d1e-0000-4000-8000-000000000000/link', $response),
        );
    }

    public function testAnEmptyMapSentAsAListIsReported(): void
    {
        // json_encode([]) is `[]`; a map the contract declares has to be cast.
        $response = new JsonResponse(['preferences' => []]);

        self::assertContains(
            'GET /api/v1/notifications/preferences → 200: $.preferences is array, the contract says object',
            ContractResponses::violations('GET', '/api/v1/notifications/preferences', $response),
        );
    }

    public function testAValueOutsideTheEnumIsReported(): void
    {
        $response = new JsonResponse(['preferences' => (object) ['BILLING.EMAIL' => 'yes']]);

        self::assertContains(
            'GET /api/v1/notifications/preferences → 200: $.preferences.BILLING.EMAIL is string, the contract says boolean',
            ContractResponses::violations('GET', '/api/v1/notifications/preferences', $response),
        );
    }

    public function testAFieldTheContractClosesIsReported(): void
    {
        $response = new JsonResponse(['blocks' => [['content' => ['headline' => 'Hi', 'colour' => 'red']]]]);

        $violations = ContractResponses::violations('PUT', '/api/v1/staff/products/7f9c1d1e-0000-4000-8000-000000000000/showcase', $response);

        self::assertContains(
            'PUT /api/v1/staff/products/{productId}/showcase → 200: $.blocks[0].content.colour is not in the contract',
            $violations,
        );
    }

    public function testWhatIsNotJsonIsNotChecked(): void
    {
        self::assertSame([], ContractResponses::violations('GET', '/api/v1/billing/profile', new TextResponse('hello')));
    }
}
