/**
 * U0 ships no screens.
 *
 * The frame, its six regions and the routing arrive in U1
 * (`docs/ui-roadmap.md`). What U0 delivers is the toolchain and the two gates
 * that stop every screen after it from inventing its own contract, so this
 * component exists only to give the build something to compile and the E2E
 * suite something to load.
 */
export function App() {
  return (
    <main className="grid min-h-dvh place-items-center p-6">
      <p data-testid="u0-placeholder" className="text-sm text-neutral-600">
        Backprod — toolchain ready, no screens yet (U0).
      </p>
    </main>
  );
}
