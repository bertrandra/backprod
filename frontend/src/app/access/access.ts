/**
 * Whether the shell offers something, decided from data.
 *
 * §13 forbids branching on a plan or tier name, and non-negotiable #25 carries
 * that into the frontend: a navigation entry gated on `roles.includes('PRO')`
 * is the same defect as `if ($plan === 'PRO')` in PHP, moved somewhere the
 * backend gates cannot see it.
 *
 * So the questions are only ever "does this person hold this permission" and
 * "is this tenant entitled to this capability". Both are strings the API
 * returned; neither is a role or a plan.
 *
 * **Hiding is courtesy, not security** (non-negotiable #6). The API refuses
 * regardless. A screen that only hides is still safe; a frontend that believed
 * it was deciding would be wrong.
 */

export interface Access {
  readonly permissions: readonly string[];
  readonly capabilities: readonly string[];
}

export function can(access: Access | undefined, permission: string): boolean {
  return access?.permissions.includes(permission) ?? false;
}

export function isEntitled(access: Access | undefined, capability: string): boolean {
  return access?.capabilities.includes(capability) ?? false;
}

/**
 * Both, for the surfaces that need both and where the two refusals are
 * answered by different people: an administrator grants a permission, an
 * upgrade grants a capability (see `tenant.branding` in ui-spec.md §3.4).
 */
export function canAndEntitled(
  access: Access | undefined,
  permission: string,
  capability: string,
): boolean {
  return can(access, permission) && isEntitled(access, capability);
}
