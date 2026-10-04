import { create } from 'zustand';

/**
 * What the console is looking at: one customer, or the platform itself.
 *
 * Client state, and nothing the server would be asked to keep. The tenant is
 * *named in the URL* (`/console/tenants/$tenantId`), so a link says which
 * customer it opens; what lives here is the part of the choice a URL cannot
 * carry — the product the person narrowed the view to.
 *
 * It used to keep, per tenant, the reason given for opening it (R14). The
 * console reads without a reason since 2026-10-05 (ADR-069).
 */
interface ConsoleState {
  /** The product the tenant view is narrowed to, or null for every product it holds. */
  readonly productCode: string | null;
  narrowTo: (productCode: string | null) => void;
}

export const useConsoleStore = create<ConsoleState>((set) => ({
  productCode: null,
  narrowTo: (productCode) => {
    set({ productCode });
  },
}));
