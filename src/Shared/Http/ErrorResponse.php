<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * The single place the error envelope of Architecture V2 §10.4 is built.
 *
 * Every error response in the platform goes through here, so the contract
 * cannot drift per module:
 *
 *   {"error": {"code", "message", "details", "request_id"}}
 */
final class ErrorResponse
{
    /**
     * @param array<string, mixed> $details
     */
    public static function create(
        int $status,
        string $code,
        string $message,
        array $details,
        string $requestId,
    ): ResponseInterface {
        return new JsonResponse(
            [
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => (object) $details,
                    'request_id' => $requestId,
                ],
            ],
            $status,
        );
    }
}
