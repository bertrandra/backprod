import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

/**
 * The only file in this project that knows how to reach the API is
 * `src/api/client.ts`. Architecture V2 §8.1 says a single contract is not
 * defended by the discipline of whoever writes the `fetch()` — it is defended
 * by there being nowhere to write one. This is that nowhere.
 */
const API_CLIENT = 'src/api/client.ts';

const NO_DIRECT_HTTP =
  'Reach the API through the generated client (src/api/client.ts). ' +
  'A hand-written request is a second contract that will drift from OpenAPI — see architecture-v2.md §8.1.';

export default tseslint.config(
  {
    // Generated output is not reviewed, formatted or linted. It is replaced.
    ignores: ['dist', 'coverage', 'playwright-report', 'test-results', 'src/api/generated'],
  },

  js.configs.recommended,
  ...tseslint.configs.recommendedTypeChecked,

  {
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
      globals: { ...globals.browser },
    },
  },

  // --- The §8.1 rules -------------------------------------------------------
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: [API_CLIENT],
    rules: {
      'no-restricted-globals': [
        'error',
        { name: 'fetch', message: NO_DIRECT_HTTP },
        { name: 'XMLHttpRequest', message: NO_DIRECT_HTTP },
        { name: 'EventSource', message: NO_DIRECT_HTTP },
      ],
      'no-restricted-syntax': [
        'error',
        {
          // `no-restricted-globals` only catches a bare reference, so the two
          // spellings that reach the same function are named explicitly.
          selector:
            "MemberExpression[object.name=/^(window|globalThis|self)$/][property.name=/^(fetch|XMLHttpRequest|EventSource)$/]",
          message: NO_DIRECT_HTTP,
        },
        {
          selector: "NewExpression[callee.name='XMLHttpRequest']",
          message: NO_DIRECT_HTTP,
        },
      ],
      'no-restricted-imports': [
        'error',
        {
          paths: [
            { name: 'axios', message: NO_DIRECT_HTTP },
            { name: 'ky', message: NO_DIRECT_HTTP },
            { name: 'superagent', message: NO_DIRECT_HTTP },
            {
              name: 'openapi-fetch',
              message: `Only ${API_CLIENT} builds the client. Import the configured client from '@/api/client'.`,
            },
          ],
          patterns: [
            {
              group: ['**/api/generated/*'],
              message:
                'Import types from @/api/client, which re-exports them. Reaching into the generated directory couples call sites to its layout.',
            },
          ],
        },
      ],
    },
  },

  // --- React ---------------------------------------------------------------
  {
    files: ['src/**/*.{ts,tsx}'],
    plugins: { 'react-hooks': reactHooks },
    rules: {
      ...reactHooks.configs.recommended.rules,
    },
  },

  // --- Node-side files: build scripts and configuration ---------------------
  {
    files: ['scripts/**/*.mjs', '*.config.ts', 'e2e/**/*.ts'],
    languageOptions: {
      globals: { ...globals.node },
    },
    rules: {
      // A build script legitimately reads the filesystem and spawns a process.
      'no-restricted-globals': 'off',
      'no-restricted-syntax': 'off',
    },
  },

  // Neither the flat config nor a build script is part of the TypeScript
  // program, so the type-aware rules have no types to work from.
  {
    files: ['scripts/**/*.mjs', 'eslint.config.js'],
    ...tseslint.configs.disableTypeChecked,
  },
);
