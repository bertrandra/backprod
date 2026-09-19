// `gate:i18n` (2026-09-19, ADR-050): every catalogue says every sentence the
// screens say, and nothing they no longer say. A missing key would show
// English in a French screen; an orphan is a translation of something that
// no longer exists, which is how a catalogue rots. Both fail the build —
// the same reasoning as `gate:client` for the contract.
//
// `--write` adds the missing keys to each catalogue as `"English": ""`
// (an empty value falls back to English at run time), so a translator can
// see what is left, and removes the orphans.
import { readFileSync, writeFileSync } from 'node:fs';

import { collectKeys } from './i18n-keys.mjs';

const LOCALES = ['fr', 'es', 'de', 'it'];
const write = process.argv.includes('--write');
const keys = collectKeys();
let failed = false;

for (const locale of LOCALES) {
  const path = new URL(`../src/i18n/catalogues/${locale}.json`, import.meta.url);
  const catalogue = JSON.parse(readFileSync(path, 'utf8'));
  const missing = keys.filter((key) => !(key in catalogue));
  const empty = keys.filter((key) => key in catalogue && catalogue[key] === '');
  const orphans = Object.keys(catalogue).filter((key) => !keys.includes(key));
  // A translation says the same `{placeholders}` as its English, or a name
  // or an amount goes missing from one language's screen.
  const placeholders = (text) => [...text.matchAll(/\{([a-zA-Z_]+)\}/g)].map((m) => m[1]).sort().join(',');
  const mismatched = keys.filter((key) => typeof catalogue[key] === 'string' && catalogue[key] !== '' && placeholders(catalogue[key]) !== placeholders(key));

  if (write) {
    const next = {};

    for (const key of keys) {
      next[key] = catalogue[key] ?? '';
    }

    writeFileSync(path, `${JSON.stringify(next, null, 2)}\n`);
    console.log(`${locale}: ${missing.length} added, ${orphans.length} removed, ${empty.length + missing.length} still to translate`);

    continue;
  }

  if (missing.length > 0 || orphans.length > 0 || empty.length > 0 || mismatched.length > 0) {
    failed = true;
    console.error(`${locale}: ${missing.length} missing, ${empty.length} untranslated, ${orphans.length} orphaned, ${mismatched.length} with placeholders that differ`);

    for (const key of missing.slice(0, 10)) console.error(`  missing:  ${JSON.stringify(key)}`);
    for (const key of empty.slice(0, 10)) console.error(`  empty:    ${JSON.stringify(key)}`);
    for (const key of orphans.slice(0, 10)) console.error(`  orphaned: ${JSON.stringify(key)}`);
    for (const key of mismatched.slice(0, 10)) console.error(`  placeholders: ${JSON.stringify(key)} → ${JSON.stringify(catalogue[key])}`);
  } else {
    console.log(`${locale}: ${keys.length} keys, all translated`);
  }
}

if (failed) {
  console.error('FAIL: the catalogues do not match the screens. Run `node scripts/check-i18n.mjs --write` and translate what is empty.');
  process.exit(1);
}

console.log(`OK: ${keys.length} sentences, ${LOCALES.length} catalogues complete.`);
