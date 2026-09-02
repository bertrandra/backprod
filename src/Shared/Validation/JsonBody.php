<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Exceptions\BadRequestException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads and validates a JSON request body.
 *
 * Validation happens at the boundary and rejects with the documented envelope
 * (§10.4), so handlers work with values that are already the right type.
 * §9 requires validation on both sides — Zod in the browser is a convenience,
 * this is the one that counts.
 */
final class JsonBody
{
    /**
     * @param array<string, mixed> $fields
     */
    private function __construct(private readonly array $fields)
    {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $raw = (string) $request->getBody();

        if (trim($raw) === '') {
            return new self([]);
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BadRequestException('INVALID_BODY', 'The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * A non-empty string, trimmed. Absent, null, wrong type and blank are all
     * rejected — a name of spaces is not a name.
     */
    public function requiredString(string $field, int $maxLength = 255): string
    {
        $value = $this->fields[$field] ?? null;

        if (!is_string($value)) {
            throw $this->invalid($field, 'must be a string');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw $this->invalid($field, 'must not be blank');
        }

        if (mb_strlen($trimmed) > $maxLength) {
            throw $this->invalid($field, sprintf('must be at most %d characters', $maxLength));
        }

        return $trimmed;
    }

    /**
     * A string that may be explicitly cleared: null stays null, and a blank
     * string is treated as clearing rather than as the literal "".
     */
    public function optionalNullableString(string $field, int $maxLength = 255): ?string
    {
        $value = $this->fields[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw $this->invalid($field, 'must be a string or null');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $maxLength) {
            throw $this->invalid($field, sprintf('must be at most %d characters', $maxLength));
        }

        return $trimmed;
    }

    /**
     * A non-empty list of unique non-blank strings.
     *
     * @return list<string>
     */
    public function requiredStringList(string $field): array
    {
        $value = $this->fields[$field] ?? null;

        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid($field, 'must be an array');
        }

        $values = [];

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw $this->invalid($field, 'must contain only non-empty strings');
            }

            $trimmed = trim($item);

            if (!in_array($trimmed, $values, true)) {
                $values[] = $trimmed;
            }
        }

        if ($values === []) {
            throw $this->invalid($field, 'must not be empty');
        }

        return $values;
    }

    private function invalid(string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => $field, 'requirement' => $requirement],
        );
    }
}
