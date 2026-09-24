<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Validation\Locale;
use stdClass;

/**
 * What an operator wrote in the four other languages, read off a request
 * body (2026-09-24, `docs/translatable-fields-spec.md`).
 *
 * ```json
 * "translations": {
 *   "fr": { "name": "Moteur de terrasse", "description": "…" },
 *   "de": { "name": "Terrassenmodul" }
 * }
 * ```
 *
 * Here rather than in each controller because every translated thing —
 * features today, offers and showcase blocks next — sends the same shape,
 * and three copies of this validation would be three places for `en` to
 * quietly become writable.
 *
 * **English is refused, not ignored.** It lives on the row itself and is
 * the fallback; accepting it here would give a name two homes and let them
 * disagree. A caller sending it has misunderstood something, and saying so
 * is cheaper than the bug they are about to write.
 *
 * Two fields, named rather than configurable. A generic `array<string,int>`
 * of allowed fields reads as flexibility and is really a way to lose the
 * shape: what comes back is then `array<string, array<string, ?string>>`,
 * which no caller can rely on. `name` and `description` are what a
 * catalogue translates; a third one is a change to this class, on purpose.
 */
final class Translations
{
    /**
     * @param bool $withDescription whether a description may be translated at all — an offer has only a name
     *
     * @return array<string, array{name: ?string, description: ?string}>
     */
    public static function of(JsonBody $body, string $field, bool $withDescription = true): array
    {
        $sent = get_object_vars($body->requiredObject($field));
        $translations = [];

        foreach ($sent as $locale => $values) {
            // The match is the validation *and* the key: PHP turns "0" into
            // an integer key, so a locale that came back as a variable
            // string would leave this map typed by `int|string` and the
            // callers unable to rely on it.
            $known = match ($locale) {
                'fr' => 'fr',
                'es' => 'es',
                'de' => 'de',
                'it' => 'it',
                default => throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', [
                    'field' => $field . '.' . $locale,
                    'requirement' => 'one of ' . implode(', ', Locale::TRANSLATABLE)
                        . ($locale === Locale::DEFAULT ? ' — the English is the value itself, not a translation of it' : ''),
                ]),
            };

            if (!$values instanceof stdClass) {
                throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', [
                    'field' => $field . '.' . $known,
                    'requirement' => 'an object of translated values',
                ]);
            }

            $translations[$known] = self::values($field . '.' . $known, $values, $withDescription);
        }

        return $translations;
    }

    /**
     * @return array{name: ?string, description: ?string}
     */
    private static function values(string $where, stdClass $values, bool $withDescription): array
    {
        $allowed = $withDescription ? ['name', 'description'] : ['name'];

        foreach (array_keys(get_object_vars($values)) as $sent) {
            if (!in_array((string) $sent, $allowed, true)) {
                throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', [
                    'field' => $where . '.' . $sent,
                    'requirement' => 'one of ' . implode(', ', $allowed),
                ]);
            }
        }

        return [
            'name' => self::text($where . '.name', $values->name ?? null, 200),
            'description' => $withDescription ? self::text($where . '.description', $values->description ?? null, 500) : null,
        ];
    }

    private static function text(string $where, mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', [
                'field' => $where,
                'requirement' => sprintf('a string of at most %d characters', $maxLength),
            ]);
        }

        // Trimmed like every other name on this platform, and an empty one
        // is nothing rather than an empty translation: a value that exists
        // and says nothing would read as "deliberately blank" to the next
        // person, and fall back to English anyway.
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
