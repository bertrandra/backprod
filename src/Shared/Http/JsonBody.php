<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exceptions\BadRequestException;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/**
 * Reads and validates a JSON request body.
 *
 * Validation happens at the boundary and rejects with the documented envelope
 * (§10.4), so handlers work with values that are already the right type.
 * §9 requires validation on both sides — Zod in the browser is a convenience,
 * this is the one that counts.
 *
 * The body is decoded into objects rather than associative arrays. A PHP
 * array cannot represent the difference between {"0":"a"} and ["a"], so
 * decoding a document into one and encoding it back changes its shape: a
 * project whose layers are keyed by numeric ids would be silently rewritten
 * into a list. Scalars read the same either way; nested structure does not.
 */
final class JsonBody
{
    private function __construct(private readonly stdClass $fields)
    {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $raw = (string) $request->getBody();

        if (trim($raw) === '') {
            return new self(new stdClass());
        }

        $decoded = json_decode($raw, false);

        if (!$decoded instanceof stdClass) {
            throw new BadRequestException('INVALID_BODY', 'The request body must be a JSON object.');
        }

        return new self($decoded);
    }

    public function has(string $field): bool
    {
        return property_exists($this->fields, $field);
    }

    /**
     * A non-empty string, trimmed. Absent, null, wrong type and blank are all
     * rejected — a name of spaces is not a name.
     */
    public function requiredString(string $field, int $maxLength = 255): string
    {
        $value = $this->value($field);

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
        $value = $this->value($field);

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
     * A whole number at or above $minimum.
     *
     * Rejects "2" and 2.0 rather than coercing them: a client that sends a
     * schema version as a string has a serialisation bug, and quietly
     * accepting it means the bug ships.
     */
    public function requiredInt(string $field, int $minimum = 1): int
    {
        $value = $this->value($field);

        if (!is_int($value)) {
            throw $this->invalid($field, 'must be an integer');
        }

        if ($value < $minimum) {
            throw $this->invalid($field, sprintf('must be at least %d', $minimum));
        }

        return $value;
    }

    /**
     * A real boolean, or the default when absent.
     *
     * Rejects "true" and 1 rather than coercing them. A flag that decides
     * whether a subscription ends today or in three weeks is not the place
     * to be generous about types.
     */
    public function optionalBool(string $field, bool $default = false): bool
    {
        $value = $this->value($field);

        if ($value === null) {
            return $default;
        }

        if (!is_bool($value)) {
            throw $this->invalid($field, 'must be true or false');
        }

        return $value;
    }

    /**
     * A JSON object. Arrays and scalars are refused: the caller was asked for
     * a structure with named fields, and a list is not one.
     */
    public function requiredObject(string $field): stdClass
    {
        $value = $this->value($field);

        if (!$value instanceof stdClass) {
            throw $this->invalid($field, 'must be a JSON object');
        }

        return $value;
    }

    /**
     * A non-empty list of unique non-blank strings.
     *
     * @return list<string>
     */
    public function requiredStringList(string $field): array
    {
        $value = $this->value($field);

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

    /**
     * Absent and null both read as null here; callers that need to tell them
     * apart ask has() first.
     */
    /**
     * A list of unique non-blank strings, or none at all.
     *
     * Absent and `[]` mean the same thing — nobody was named — because a
     * client with no ids to send should not have to choose between omitting
     * the field and sending an empty array, and answering 400 to the second
     * teaches it a rule that serves nothing.
     *
     * Anything else goes through the same validation as a required list, so
     * `[""]` and `"nope"` are still refused.
     *
     * @return list<string>
     */
    public function optionalStringList(string $field): array
    {
        $value = $this->value($field);

        if ($value === null || $value === []) {
            return [];
        }

        return $this->requiredStringList($field);
    }

    private function value(string $field): mixed
    {
        return $this->has($field) ? $this->fields->{$field} : null;
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
