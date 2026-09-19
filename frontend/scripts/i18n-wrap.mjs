// One-off codemod (2026-09-19, ADR-050): wraps the English on every screen
// in `t()`, so it can be translated. Idempotent — a text already inside
// `t(...)` is left alone — and conservative: only JSX text, a handful of
// prose attributes, and string literals that are the branches of a
// conditional inside JSX. Anything that could be data (an enum, a code, a
// path, a class name) is left as it is. Run with `node scripts/i18n-wrap.mjs`.
import { readFileSync, writeFileSync } from 'node:fs';
import { readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import ts from 'typescript';

const ROOT = new URL('../src/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const PROSE_ATTRIBUTES = new Set(['label', 'title', 'description', 'hint', 'placeholder', 'aria-label', 'caption', 'alt']);
const ENTITIES = { '&rsquo;': '’', '&lsquo;': '‘', '&rdquo;': '”', '&ldquo;': '“', '&mdash;': '—', '&ndash;': '–', '&hellip;': '…', '&nbsp;': ' ', '&amp;': '&', '&times;': '×', '&euro;': '€', '&apos;': "'", '&quot;': '"' };

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);

    if (statSync(path).isDirectory()) {
      if (name === 'generated' || name === 'i18n') continue;
      walk(path, out);
    } else if (path.endsWith('.tsx') && !path.endsWith('.test.tsx') && !path.endsWith('test-utils.tsx')) {
      out.push(path);
    }
  }

  return out;
}

function decode(text) {
  return text.replace(/&[a-z]+;/g, (entity) => ENTITIES[entity] ?? entity);
}

function isProse(text) {
  return /[A-Za-z]/.test(text) && text.length >= 2 && !/^\{.*\}$/.test(text);
}

function isDataLike(value) {
  return (
    /^[A-Z0-9_]+$/.test(value) || // an enum
    /^[a-z0-9_.-]+$/.test(value) || // a code, a class, a path segment
    /^[a-z0-9\-/:.[\]%_# ]+$/.test(value) || // a class list
    value.includes('/') ||
    value.startsWith('#') ||
    /^\s*$/.test(value)
  );
}

function quote(key) {
  return JSON.stringify(key);
}

function transform(source, file) {
  const sf = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  const edits = [];

  function insideT(node) {
    let n = node.parent;

    while (n) {
      if (ts.isCallExpression(n) && ts.isIdentifier(n.expression) && n.expression.text === 't') return true;
      n = n.parent;
    }

    return false;
  }

  function visit(node) {
    if (ts.isJsxText(node)) {
      const raw = node.getText(sf);
      const collapsed = raw.replace(/\s*\n\s*/g, ' ');
      const key = decode(collapsed.trim());

      if (isProse(key) && !insideT(node)) {
        const lead = /^\s/.test(collapsed) && !/^\s*\n/.test(raw) ? "{' '}" : '';
        const trail = /\s$/.test(collapsed) && !/\n\s*$/.test(raw) ? "{' '}" : '';
        edits.push({ start: node.getStart(sf), end: node.getEnd(), text: `${lead}{t(${quote(key)})}${trail}` });
      }
    } else if (ts.isJsxAttribute(node) && node.initializer !== undefined) {
      const name = node.name.getText(sf);

      if (PROSE_ATTRIBUTES.has(name)) {
        const init = node.initializer;

        if (ts.isStringLiteral(init) && isProse(init.text) && !isDataLike(init.text)) {
          edits.push({ start: init.getStart(sf), end: init.getEnd(), text: `{t(${quote(init.text)})}` });
        } else if (ts.isJsxExpression(init) && init.expression !== undefined && ts.isStringLiteral(init.expression) && isProse(init.expression.text) && !isDataLike(init.expression.text)) {
          edits.push({ start: init.expression.getStart(sf), end: init.expression.getEnd(), text: `t(${quote(init.expression.text)})` });
        }
      }
    } else if (ts.isTemplateExpression(node) && !insideT(node) && !node.templateSpans.some((span) => ts.isTemplateExpression(span.expression))) {
      // `Rank of ${plan.name}` → t("Rank of {name}", { name: plan.name }), in
      // the same JSX positions as a literal. A nested template is left alone.
      const parent = node.parent;
      const attribute = ts.isJsxExpression(parent) && ts.isJsxAttribute(parent.parent) ? parent.parent.name.getText(sf) : null;
      const positioned =
        ts.isJsxExpression(parent) ||
        (ts.isConditionalExpression(parent) && (parent.whenTrue === node || parent.whenFalse === node)) ||
        (ts.isBinaryExpression(parent) && [ts.SyntaxKind.AmpersandAmpersandToken, ts.SyntaxKind.QuestionQuestionToken].includes(parent.operatorToken.kind) && parent.right === node);
      const prose = [node.head.text, ...node.templateSpans.map((span) => span.literal.text)].join('').trim();

      if (positioned && (attribute === null || PROSE_ATTRIBUTES.has(attribute)) && /[A-Za-z]{2,}/.test(prose) && !prose.includes('/') && !/^[a-z0-9-]+$/.test(prose)) {
        const vars = [];
        const used = new Set();
        let key = decode(node.head.text);

        for (const span of node.templateSpans) {
          const expr = span.expression;
          let name = ts.isIdentifier(expr) ? expr.text : ts.isPropertyAccessExpression(expr) ? expr.name.text : 'value';
          name = name.replace(/[^a-zA-Z_]/g, '') || 'value';
          while (used.has(name)) name = `${name}_`;
          used.add(name);
          vars.push(`${name}: ${expr.getText(sf)}`);
          key += `{${name}}${decode(span.literal.text)}`;
        }

        edits.push({ start: node.getStart(sf), end: node.getEnd(), text: `t(${quote(key)}, { ${vars.join(', ')} })` });
      }
    } else if (ts.isStringLiteral(node) && !insideT(node)) {
      // The branches of a conditional, or a bare `{'text'}`, inside JSX.
      const parent = node.parent;
      const inJsx = (n) => {
        let p = n;

        while (p) {
          if (ts.isJsxExpression(p) && ts.isJsxAttribute(p.parent) && !PROSE_ATTRIBUTES.has(p.parent.name.getText(sf))) return false;
          if (ts.isJsxExpression(p)) return true;
          if (ts.isJsxAttribute(p) || ts.isCallExpression(p) || ts.isPropertyAssignment(p) || ts.isBinaryExpression(p) && ![ts.SyntaxKind.QuestionQuestionToken, ts.SyntaxKind.AmpersandAmpersandToken].includes(p.operatorToken.kind)) return false;
          p = p.parent;
        }

        return false;
      };
      const branch = ts.isConditionalExpression(parent) && (parent.whenTrue === node || parent.whenFalse === node);
      const bare = ts.isJsxExpression(parent);
      const nullish = ts.isBinaryExpression(parent) && parent.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken && parent.right === node;

      if ((branch || bare || nullish) && inJsx(node) && isProse(node.text) && !isDataLike(node.text)) {
        edits.push({ start: node.getStart(sf), end: node.getEnd(), text: `t(${quote(node.text)})` });
      }
    }

    ts.forEachChild(node, visit);
  }

  visit(sf);

  if (edits.length === 0) return null;

  // One edit per position: a literal inside an attribute expression matches
  // two rules, and applying both would write it twice.
  const unique = new Map();
  for (const edit of edits) if (!unique.has(edit.start)) unique.set(edit.start, edit);
  edits.length = 0;
  edits.push(...unique.values());
  edits.sort((a, b) => b.start - a.start);
  let out = source;

  for (const edit of edits) {
    out = out.slice(0, edit.start) + edit.text + out.slice(edit.end);
  }

  if (!/import \{[^}]*\bt\b[^}]*\} from '@\/i18n'/.test(out)) {
    // After the last import line.
    const lines = out.split('\n');
    let last = -1;

    for (let i = 0; i < lines.length; i += 1) {
      if (/^import /.test(lines[i]) || (last >= 0 && /^\s+[^\s].*from '/.test(lines[i]))) last = i;
      if (/^import [^;]*$/.test(lines[i])) {
        // multi-line import: advance to its end
        let j = i;
        while (j < lines.length && !/;\s*$/.test(lines[j])) j += 1;
        last = Math.max(last, j);
        i = j;
      }
    }

    lines.splice(last + 1, 0, "import { t } from '@/i18n';");
    out = lines.join('\n');
  }

  return out;
}

let touched = 0;

for (const file of walk(ROOT)) {
  const source = readFileSync(file, 'utf8');
  const out = transform(source, file);

  if (out !== null && out !== source) {
    writeFileSync(file, out);
    touched += 1;
    console.log('wrapped', relative(ROOT, file));
  }
}

console.log(`${touched} files`);
