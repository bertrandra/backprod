// The English sentences the screens say (2026-09-19, ADR-050): every `t('…')`
// in the source, plus the labels the navigation tree and the error surface
// carry as data and translate at render. What `gate:i18n` compares each
// catalogue against, and what `--write` seeds a catalogue's missing keys with.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import ts from 'typescript';

const SRC = new URL('../src/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);

    if (statSync(path).isDirectory()) {
      if (name === 'generated') continue;
      walk(path, out);
    } else if (/\.tsx?$/.test(path) && !/\.test\.tsx?$/.test(path) && !path.endsWith('test-utils.tsx')) {
      out.push(path);
    }
  }

  return out;
}

// A sentence, as opposed to a code, a class list, a path or an enumeration
// value: it has letters, and either a space and some punctuation or a capital,
// or it is a single capitalised word. Class lists are lower-case tokens.
function isProse(text) {
  if (!/[A-Za-z]/.test(text) || text.length < 2 || text.includes('/')) return false;
  if (/^[a-z0-9\-/:.[\]%_#]+( [a-z0-9\-/:.[\]%_#]+)*$/.test(text)) return false; // classes, codes, paths
  if (/^[A-Z0-9_]+$/.test(text)) return false; // an enumeration value

  return /[A-Z]/.test(text) || /[ ,.;:!?’'—]/.test(text);
}

export function collectKeys() {
  const keys = new Set();

  for (const file of walk(SRC)) {
    const source = readFileSync(file, 'utf8');
    const sf = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, file.endsWith('x') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
    const visit = (node) => {
      if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && node.expression.text === 't') {
        const [first] = node.arguments;

        // `t('…')`, or `t(one ? '{count} step' : '{count} steps')` — a plural
        // is two sentences, chosen before translation.
        const literals =
          first === undefined ? []
          : ts.isConditionalExpression(first) ? [first.whenTrue, first.whenFalse]
          : ts.isBinaryExpression(first) && first.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken ? [first.right] // `t(server ?? 'fallback')`
          : [first];

        for (const literal of literals) {
          if (ts.isStringLiteral(literal) || ts.isNoSubstitutionTemplateLiteral(literal)) {
            keys.add(literal.text);
          }
        }
      }

      // Sentences carried as data — a navigation label, an error's wording,
      // a retention ground — and translated where they are rendered.
      if (ts.isPropertyAssignment(node) && ts.isStringLiteral(node.initializer) && isProse(node.initializer.text)) {
        keys.add(node.initializer.text);
      }

      ts.forEachChild(node, visit);
    };

    visit(sf);
  }

  return [...keys].sort((a, b) => a.localeCompare(b));
}

if (process.argv[1] && process.argv[1].endsWith('i18n-keys.mjs')) {
  const keys = collectKeys();
  console.log(keys.join('\n'));
  console.error(`${keys.length} keys`);
}
