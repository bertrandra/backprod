/**
 * Which version and build this bundle is, as `vite.config.ts` stamped it
 * (2026-09-27) — so "which one is deployed?" has an answer on screen, at the
 * end of the account menus, without anybody opening a terminal.
 */
export const BUILD = {
  /** The contract's `info.version`. */
  version: __APP_VERSION__,
  /** Short commit hash, `+changes` when built from a tree that was not clean. */
  commit: __BUILD_COMMIT__,
  /** ISO-8601, the moment of the build. */
  builtAt: __BUILD_TIME__,
} as const;
