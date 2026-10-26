<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Two ratchets on the modules (2026-10-26): which module uses which, and which
 * modules write which table.
 *
 * Deptrac governs layers — a Domain never reaches SQL — and says nothing about
 * modules, so the graph between them grew one convenient `use` at a time: 111
 * edges between modules, `Shared` aside, and thirteen pairs that depend on
 * each other both ways when this was written. And a table written by three modules has
 * three places its invariants must hold, of which the database enforces only
 * the ones somebody wrote down as a constraint.
 *
 * Neither is fixed by this test. It records the graph as it stands in
 * `tests/Fixtures/module-boundaries.json` and refuses it growing: a new edge,
 * or a new module writing a table, fails until somebody adds it to the file —
 * a line a reviewer sees, rather than an import nobody does. An entry the code
 * no longer needs fails too, so the file only ever shrinks by being kept
 * honest. `MODULE_BOUNDARIES_WRITE=1` rewrites it from the code.
 *
 * Edges into `Shared` are not counted: it is the kernel everybody may use.
 */
#[CoversNothing]
final class ModuleBoundariesTest extends TestCase
{
    private const BASELINE = __DIR__ . '/../Fixtures/module-boundaries.json';

    /** Words that follow UPDATE without naming a table. */
    private const NOT_TABLES = ['set', 'of', 'skip', 'nowait', 'on', 'where'];

    public function testNoModuleGainsADependency(): void
    {
        $this->compare('dependencies', $this->dependencies(), 'uses');
    }

    public function testNoTableGainsAWriter(): void
    {
        $this->compare('writers', $this->writers(), 'is written by');
    }

    /**
     * @param array<string, list<string>> $actual
     */
    private function compare(string $section, array $actual, string $verb): void
    {
        $baseline = $this->baseline();

        if (getenv('MODULE_BOUNDARIES_WRITE') === '1') {
            $baseline[$section] = $actual;
            ksort($baseline);
            file_put_contents(
                self::BASELINE,
                json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }

        $recorded = $baseline[$section] ?? [];
        $added = [];
        $gone = [];

        foreach ($actual as $from => $targets) {
            foreach (array_diff($targets, $recorded[$from] ?? []) as $to) {
                $added[] = sprintf('%s %s %s', $from, $verb, $to);
            }
        }

        foreach ($recorded as $from => $targets) {
            foreach (array_diff($targets, $actual[$from] ?? []) as $to) {
                $gone[] = sprintf('%s %s %s', $from, $verb, $to);
            }
        }

        self::assertSame(
            [],
            $added,
            'New, and not in tests/Fixtures/module-boundaries.json. Remove it, or add it there '
            . "where a reviewer will see it:\n  " . implode("\n  ", $added),
        );
        self::assertSame(
            [],
            $gone,
            'No longer true; remove it from tests/Fixtures/module-boundaries.json so it '
            . "cannot come back unseen:\n  " . implode("\n  ", $gone),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function dependencies(): array
    {
        $graph = [];

        foreach ($this->sources() as $module => $source) {
            preg_match_all('/\\\\?App\\\\([A-Z][A-Za-z0-9]*)\\\\/', $source, $matches);

            foreach ($matches[1] as $target) {
                if ($target !== $module && $target !== 'Shared' && $target !== 'Tests') {
                    $graph[$module][$target] = true;
                }
            }
        }

        return self::sorted($graph);
    }

    /**
     * @return array<string, list<string>>
     */
    private function writers(): array
    {
        $tables = [];

        foreach ($this->sources() as $module => $source) {
            preg_match_all('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+"?([a-z_][a-z0-9_]*)/', $source, $matches);

            foreach ($matches[1] as $table) {
                if (!in_array(strtolower($table), self::NOT_TABLES, true)) {
                    $tables[$table][$module] = true;
                }
            }
        }

        return self::sorted($tables);
    }

    /**
     * Every PHP file under src/, concatenated by module. Comments are kept out:
     * a docblock explaining why a module does *not* write a table is not a
     * write.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $src = dirname(__DIR__, 2) . '/src';
        $byModule = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($src) + 1);
            $module = explode('/', $relative)[0];
            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            $byModule[$module] = ($byModule[$module] ?? '') . $code . "\n";
        }

        ksort($byModule);

        return $byModule;
    }

    /**
     * @param array<string, array<string, true>> $sets
     *
     * @return array<string, list<string>>
     */
    private static function sorted(array $sets): array
    {
        $sorted = [];

        foreach ($sets as $key => $members) {
            $list = array_map('strval', array_keys($members));
            sort($list);
            $sorted[(string) $key] = $list;
        }

        ksort($sorted);

        return $sorted;
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function baseline(): array
    {
        if (!is_file(self::BASELINE)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents(self::BASELINE), true, 512, JSON_THROW_ON_ERROR);
        $baseline = [];

        foreach (is_array($decoded) ? $decoded : [] as $section => $entries) {
            foreach (is_array($entries) ? $entries : [] as $from => $targets) {
                $baseline[(string) $section][(string) $from] = array_values(array_filter(
                    is_array($targets) ? $targets : [],
                    'is_string',
                ));
            }
        }

        return $baseline;
    }
}
