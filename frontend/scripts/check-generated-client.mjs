/**
 * Proves the generated types still match the contract.
 *
 * This is the gate `docs/ui-spec.md` §7 and CLAUDE.md's gate chain both name
 * as missing. Without it §8.1 is enforced by reading: the backend renames a
 * field, `gate:openapi` stays green because the contract and the router still
 * agree, and the frontend keeps compiling against the old shape until a
 * customer's browser finds the difference.
 *
 * **The check is the regeneration.** It does not compare timestamps, hashes of
 * inputs, or a recorded version — all of which can agree while the output is
 * stale. It regenerates from `openapi.json` and compares the result with the
 * committed file, because the only thing that proves the output current is
 * producing it again.
 *
 * Nothing is written. A failure is fixed by `npm run generate`, which is what
 * the message says.
 */

import { readFile } from 'node:fs/promises';
import { relative } from 'node:path';

import { CONTRACT, OUTPUT, renderSchema } from './generate-api-client.mjs';

const cwd = process.cwd();
const shown = (path) => relative(cwd, path) || path;

let committed;

try {
  committed = await readFile(OUTPUT, 'utf8');
} catch {
  console.error(`FAIL: ${shown(OUTPUT)} does not exist.\n`);
  console.error('The generated types are committed so that a checkout compiles');
  console.error('without a generation step. Run: npm run generate\n');
  process.exit(1);
}

const expected = await renderSchema();

if (committed === expected) {
  const lines = expected.split('\n').length;

  console.log(
    `OK: the generated client matches ${shown(CONTRACT)} (${lines} lines, regenerated and compared).`,
  );
  process.exit(0);
}

// Say *where* it drifted. "Run npm run generate" is the fix, but a reviewer
// reading a CI log wants to know what changed in the contract.
const committedLines = committed.split('\n');
const expectedLines = expected.split('\n');
const width = Math.max(committedLines.length, expectedLines.length);

const drift = [];

for (let i = 0; i < width && drift.length < 12; i += 1) {
  const was = committedLines[i];
  const now = expectedLines[i];

  if (was !== now) {
    drift.push({ line: i + 1, was, now });
  }
}

console.error('FAIL: the generated client no longer matches the contract.\n');
console.error(`  contract : ${shown(CONTRACT)}`);
console.error(`  generated: ${shown(OUTPUT)}`);
console.error(
  `  ${committedLines.length} committed lines vs ${expectedLines.length} regenerated\n`,
);
console.error('First differences:\n');

for (const { line, was, now } of drift) {
  console.error(`  line ${line}`);
  console.error(`    committed:   ${was === undefined ? '(end of file)' : was.trim()}`);
  console.error(`    regenerated: ${now === undefined ? '(end of file)' : now.trim()}`);
}

if (drift.length === 12) {
  console.error('\n  … more differences not shown.');
}

console.error('\nThe contract is the source (§8.1). Run: npm run generate');
console.error('Do not edit the generated file — the next generation overwrites it.\n');
process.exit(1);
