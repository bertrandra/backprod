<?php

declare(strict_types=1);

namespace App\Tests\Support;

use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Checks a response the API produced against what `openapi.json` says it
 * returns (2026-10-26).
 *
 * The contract was checked against the router (gate:openapi: every route is
 * declared, every declaration has a route) and the client against the
 * contract (gate:client), and nothing checked what a handler actually *sent*
 * against either. A field renamed in a presenter left every gate green while
 * the generated client went on reading the old name — the drift CLAUDE.md
 * says a customer finds. Every integration test already drives the real
 * pipeline, so every response it sees is checked here.
 *
 * A JSON Schema subset, the one `openapi.json` uses: `type` (with `null` in a
 * list), `$ref`, `properties`, `required`, `additionalProperties`, `items`,
 * `enum`, `const`, `allOf`, `oneOf`, `anyOf`, `minimum`, `maximum`,
 * `minLength`, `maxLength`, `minItems`, `maxItems`. `format` and `pattern`
 * are not checked: they describe what a value means more than what shape it
 * has, and the client is generated from the shape.
 *
 * Only JSON bodies are checked, and only for a path and status the contract
 * declares; an undeclared path or status is gate:openapi's question.
 */
final class ContractResponses
{
    /** @var array<mixed>|null */
    private static ?array $contract = null;

    /** @var list<array{string, string}>|null pattern => template, most literal first */
    private static ?array $templates = null;

    /**
     * @return list<string> what does not match, empty when the response is as declared
     */
    public static function violations(string $method, string $path, ResponseInterface $response): array
    {
        $contentType = $response->getHeaderLine('Content-Type');

        if (!str_contains($contentType, 'json')) {
            return [];
        }

        $body = (string) $response->getBody();
        $response->getBody()->rewind();

        if ($body === '') {
            return [];
        }

        $template = self::template((string) parse_url($path, PHP_URL_PATH));

        if ($template === null) {
            return [];
        }

        $operation = self::at(self::contract(), 'paths', $template, strtolower($method));

        if (!is_array($operation)) {
            return [];
        }

        $status = (string) $response->getStatusCode();
        $declared = self::at($operation, 'responses', $status) ?? self::at($operation, 'responses', 'default');

        if (!is_array($declared)) {
            return [];
        }

        $declared = self::resolve($declared);
        $schema = self::at($declared, 'content', 'application/json', 'schema')
            ?? self::at($declared, 'content', 'application/problem+json', 'schema');

        if (!is_array($schema)) {
            return [];
        }

        try {
            $value = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [sprintf('%s %s → %s: the body is not JSON', $method, $template, $status)];
        }

        $found = [];
        self::check($schema, $value, '$', $found);

        return array_map(
            static fn (string $v): string => sprintf('%s %s → %s: %s', $method, $template, $status, $v),
            $found,
        );
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $found
     */
    private static function check(array $schema, mixed $value, string $at, array &$found): void
    {
        $schema = self::resolve($schema);

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $part) {
                if (is_array($part)) {
                    self::check($part, $value, $at, $found);
                }
            }
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }

            $matching = 0;

            foreach ($schema[$keyword] as $option) {
                $inner = [];

                if (is_array($option)) {
                    self::check($option, $value, $at, $inner);
                }

                if ($inner === []) {
                    ++$matching;
                }
            }

            if ($matching === 0 || ($keyword === 'oneOf' && $matching > 1)) {
                $found[] = sprintf('%s matches %d of the %s options', $at, $matching, $keyword);
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $found[] = sprintf('%s is %s, the contract says always %s', $at, self::show($value), self::show($schema['const']));
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $found[] = sprintf('%s is %s, not one of %s', $at, self::show($value), self::show($schema['enum']));
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            if (($schema['nullable'] ?? false) === true) {
                $types[] = 'null';
            }

            $actual = self::typeOf($value);

            if (!in_array($actual, $types, true) && !($actual === 'integer' && in_array('number', $types, true))) {
                $found[] = sprintf('%s is %s, the contract says %s', $at, $actual, implode('|', array_filter($types, 'is_string')));

                return;
            }
        }

        if (is_object($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $present = get_object_vars($value);

            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (is_string($name) && !array_key_exists($name, $present)) {
                    $found[] = sprintf('%s.%s is required and missing', $at, $name);
                }
            }

            foreach ($present as $name => $inner) {
                $name = (string) $name;

                if (isset($properties[$name]) && is_array($properties[$name])) {
                    self::check($properties[$name], $inner, $at . '.' . $name, $found);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $found[] = sprintf('%s.%s is not in the contract', $at, $name);
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    self::check($schema['additionalProperties'], $inner, $at . '.' . $name, $found);
                }
            }
        }

        if (is_array($value)) {
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $inner) {
                    self::check($schema['items'], $inner, sprintf('%s[%d]', $at, $index), $found);
                }
            }

            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $found[] = sprintf('%s has %d items, fewer than %s', $at, count($value), self::show($schema['minItems']));
            }

            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $found[] = sprintf('%s has %d items, more than %s', $at, count($value), self::show($schema['maxItems']));
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $found[] = sprintf('%s is shorter than %s', $at, self::show($schema['minLength']));
            }

            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $found[] = sprintf('%s is longer than %s', $at, self::show($schema['maxLength']));
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $found[] = sprintf('%s is %s, below %s', $at, self::show($value), self::show($schema['minimum']));
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $found[] = sprintf('%s is %s, above %s', $at, self::show($value), self::show($schema['maximum']));
            }
        }
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
    }

    private static function show(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?';
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function resolve(array $node): array
    {
        $guard = 0;

        while (isset($node['$ref']) && is_string($node['$ref']) && $guard++ < 32) {
            $target = self::contract();

            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $target = is_array($target) ? ($target[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? null) : null;
            }

            if (!is_array($target)) {
                return [];
            }

            // A $ref beside other keywords (3.1 allows it): the siblings add to
            // the target rather than being dropped.
            unset($node['$ref']);
            $node = $node + $target;
        }

        return $node;
    }

    private static function template(string $path): ?string
    {
        if (self::$templates === null) {
            $paths = self::contract()['paths'] ?? [];
            $templates = [];

            foreach (is_array($paths) ? array_keys($paths) : [] as $template) {
                $template = (string) $template;
                $pattern = '#^' . preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', preg_quote($template, '#')) . '$#';
                $templates[] = [$pattern, $template];
            }

            // A literal segment wins over a parameter: /invoices/export before
            // /invoices/{id}.
            usort($templates, static fn (array $a, array $b): int => substr_count($a[1], '{') <=> substr_count($b[1], '{'));
            self::$templates = $templates;
        }

        foreach (self::$templates as [$pattern, $template]) {
            if (preg_match($pattern, $path) === 1) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Walks nested arrays by key, and answers null where the path stops.
     */
    private static function at(mixed $node, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @return array<mixed>
     */
    private static function contract(): array
    {
        if (self::$contract === null) {
            $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$contract = is_array($decoded) ? $decoded : [];
        }

        return self::$contract;
    }
}
