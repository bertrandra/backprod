<?php

declare(strict_types=1);

namespace App\Theme\Domain;

use App\Shared\Exceptions\BadRequestException;

/**
 * The design system as a document (2026-10-04): the colours, in their groups
 * and in both themes, the font families, and the type scale.
 *
 * ```json
 * {
 *   "format": 1,
 *   "colors": [
 *     {"group": "Surfaces, in levels", "tokens": [
 *       {"name": "canvas", "variable": "--ds-canvas", "light": "#f5f7f9", "dark": "#0a0e14"}
 *     ]}
 *   ],
 *   "fonts": [
 *     {"role": "sans", "variable": "--font-sans", "family": "Geist Variable",
 *      "stack": "'Geist Variable', ui-sans-serif, system-ui, sans-serif"}
 *   ],
 *   "type_scale": [
 *     {"name": "xl", "variable": "--text-xl", "size": "1.25rem",
 *      "line_height": "1.65rem", "letter_spacing": "-0.012em"}
 *   ]
 * }
 * ```
 *
 * **Stored, not applied.** `frontend/src/index.css` is the design system and
 * the console reads it; this is what the console saved of it, under a name.
 * Nothing on the platform paints itself from a stored theme yet — which is
 * exactly why the values are held to CSS's own shape now rather than the day
 * something does: a value that could close a declaration (`;`, `}`) or open
 * markup (`<`) is refused here, so a document already stored never becomes
 * the injection the day it is read into a stylesheet.
 *
 * Validated as a whole and refused as a whole: a theme half-saved is a theme
 * nobody chose.
 */
final class ThemeDocument
{
    public const FORMAT = 1;

    private const NAME = '/^[a-z][a-z0-9-]{0,63}$/';
    private const VARIABLE = '/^--[a-z][a-z0-9-]{0,63}$/';

    /**
     * A CSS value as the stylesheet writes one: `#0b6e99`, `rgb(14 21 32 / 0.4)`,
     * `1.25rem`, `-0.012em`. No quote, no semicolon, no brace, no angle bracket.
     */
    private const VALUE = '/^[#a-zA-Z0-9(),.%\/ +-]{1,100}$/';

    /** A font stack: names, quoted or not, separated by commas. */
    private const STACK = '/^[a-zA-Z0-9 ,\'"._-]{1,500}$/';

    /**
     * @param array<string, mixed> $document already validated
     */
    private function __construct(private readonly array $document)
    {
    }

    /**
     * @param array<mixed> $raw decoded JSON, as the request carried it
     */
    public static function fromArray(array $raw): self
    {
        $format = $raw['format'] ?? null;

        if ($format !== self::FORMAT) {
            self::refuse('format', 'the integer ' . self::FORMAT);
        }

        $names = [];

        $colors = self::list($raw, 'colors', 1, 50);
        $groups = [];

        foreach ($colors as $index => $group) {
            $field = "colors[{$index}]";
            $group = self::object($group, $field);
            $tokens = [];

            foreach (self::list($group, 'tokens', 1, 100, "{$field}.tokens") as $at => $token) {
                $where = "{$field}.tokens[{$at}]";
                $token = self::object($token, $where);
                $name = self::match($token, 'name', self::NAME, $where);

                if (isset($names[$name])) {
                    self::refuse("{$where}.name", 'a colour name not already used in this theme');
                }

                $names[$name] = true;
                $tokens[] = [
                    'name' => $name,
                    'variable' => self::match($token, 'variable', self::VARIABLE, $where),
                    'light' => self::match($token, 'light', self::VALUE, $where),
                    'dark' => self::match($token, 'dark', self::VALUE, $where),
                ];
            }

            $groups[] = ['group' => self::text($group, 'group', 200, $field), 'tokens' => $tokens];
        }

        $fonts = [];

        foreach (self::list($raw, 'fonts', 0, 10) as $index => $font) {
            $where = "fonts[{$index}]";
            $font = self::object($font, $where);
            $fonts[] = [
                'role' => self::match($font, 'role', self::NAME, $where),
                'variable' => self::match($font, 'variable', self::VARIABLE, $where),
                'family' => self::match($font, 'family', self::STACK, $where),
                'stack' => self::match($font, 'stack', self::STACK, $where),
            ];
        }

        $scale = [];

        foreach (self::list($raw, 'type_scale', 0, 40) as $index => $step) {
            $where = "type_scale[{$index}]";
            $step = self::object($step, $where);
            $scale[] = [
                'name' => self::match($step, 'name', self::NAME, $where),
                'variable' => self::match($step, 'variable', self::VARIABLE, $where),
                'size' => self::match($step, 'size', self::VALUE, $where),
                'line_height' => self::optional($step, 'line_height', $where),
                'letter_spacing' => self::optional($step, 'letter_spacing', $where),
            ];
        }

        return new self(['format' => self::FORMAT, 'colors' => $groups, 'fonts' => $fonts, 'type_scale' => $scale]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document;
    }

    /**
     * @param array<mixed> $source
     *
     * @return list<mixed>
     */
    private static function list(array $source, string $key, int $minimum, int $maximum, ?string $field = null): array
    {
        $value = $source[$key] ?? null;

        if (!is_array($value) || !array_is_list($value) || count($value) < $minimum || count($value) > $maximum) {
            self::refuse($field ?? $key, "a list of {$minimum} to {$maximum} entries");
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            self::refuse($field, 'an object');
        }

        return $value;
    }

    /**
     * @param array<mixed> $source
     */
    private static function match(array $source, string $key, string $pattern, string $where): string
    {
        $value = $source[$key] ?? null;

        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            self::refuse("{$where}.{$key}", 'a value in CSS form, without ; { } < > or a backslash');
        }

        return $value;
    }

    /**
     * @param array<mixed> $source
     */
    private static function optional(array $source, string $key, string $where): ?string
    {
        return ($source[$key] ?? null) === null ? null : self::match($source, $key, self::VALUE, $where);
    }

    /**
     * @param array<mixed> $source
     */
    private static function text(array $source, string $key, int $maximum, string $where): string
    {
        $value = $source[$key] ?? null;

        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum) {
            self::refuse("{$where}.{$key}", "text of 1 to {$maximum} characters");
        }

        return $value;
    }

    private static function refuse(string $field, string $requirement): never
    {
        throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => $field, 'requirement' => $requirement]);
    }
}
