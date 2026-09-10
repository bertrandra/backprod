/**
 * Generates the TypeScript view of the API contract.
 *
 * `openapi.json` at the repository root is the source (§8.1). This writes
 * `src/api/generated/schema.d.ts` and nothing else: the output is types only,
 * with no runtime, so there is no generated code anyone could be tempted to
 * edit "just this once". The client that uses these types is
 * `src/api/client.ts`, which is written by hand because it configures — it
 * does not define contracts.
 *
 * Run it with `npm run generate`. CI does not run it to produce the file; it
 * runs it to check the committed file still matches (see
 * check-generated-client.mjs).
 */

import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import openapiTS, { astToString } from 'openapi-typescript';

const here = dirname(fileURLToPath(import.meta.url));

export const CONTRACT = resolve(here, '../../openapi.json');
export const OUTPUT = resolve(here, '../src/api/generated/schema.d.ts');

const BANNER = `/**
 * GENERATED FILE — DO NOT EDIT.
 *
 * Produced from openapi.json by \`npm run generate\`.
 *
 * A missing field is a field missing from the contract: fix openapi.json and
 * regenerate. Editing this file produces a change the next generation
 * overwrites, and a fix that vanishes silently is worse than no fix (§8.1).
 */

`;

/**
 * @returns {Promise<string>} the file contents, banner included.
 */
export async function renderSchema() {
  const ast = await openapiTS(new URL(`file://${CONTRACT}`), {
    // The contract is the authority on what is optional. Turning unspecified
    // fields into `unknown` rather than `any` keeps a missing schema visible
    // at the call site instead of silently type-checking.
    emptyObjectsUnknown: true,
    // A response the contract does not describe should not become `any`.
    defaultNonNullable: false,
  });

  return BANNER + astToString(ast);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const contents = await renderSchema();

  await mkdir(dirname(OUTPUT), { recursive: true });
  await writeFile(OUTPUT, contents, 'utf8');

  console.log(`Generated ${OUTPUT} (${contents.length} bytes) from openapi.json`);
}
