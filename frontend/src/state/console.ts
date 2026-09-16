import { create } from 'zustand';

import type { AccessMotive } from '@/queries/staff';

/**
 * What the console is looking at: one customer, or the platform itself.
 *
 * Client state, and nothing the server would be asked to keep. The tenant is
 * *named in the URL* (`/console/tenants/$tenantId`), so a link says which
 * customer it opens; what lives here is the part of the choice a URL cannot
 * carry — the reason the person gave for opening that tenant (R14), which
 * every read of it then attaches, and the product they narrowed the view to.
 *
 * The motive is kept **per tenant**: choosing another customer asks again,
 * because a reason given for one customer is not a reason for the next,
 * and coming back to the first reuses what was said. Nothing here survives
 * a reload — a reload asks the reason again, which is the honest cost of
 * keeping the log truthful.
 */
interface ConsoleState {
  /** Motives given so far in this session, by tenant id. */
  readonly motives: Readonly<Record<string, AccessMotive>>;
  /** The product the tenant view is narrowed to, or null for every product it holds. */
  readonly productCode: string | null;
  giveMotive: (tenantId: string, motive: AccessMotive) => void;
  withdrawMotive: (tenantId: string) => void;
  narrowTo: (productCode: string | null) => void;
}

export const useConsoleStore = create<ConsoleState>((set) => ({
  motives: {},
  productCode: null,
  giveMotive: (tenantId, motive) => {
    set((state) => ({ motives: { ...state.motives, [tenantId]: motive } }));
  },
  withdrawMotive: (tenantId) => {
    set((state) => {
      const rest = { ...state.motives };
      delete rest[tenantId];

      return { motives: rest };
    });
  },
  narrowTo: (productCode) => {
    set({ productCode });
  },
}));
