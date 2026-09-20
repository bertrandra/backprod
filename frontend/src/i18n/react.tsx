import { Fragment, type ReactNode } from 'react';

import { t } from './index';

/**
 * `t` for a sentence with markup inside it (ADR-050):
 *
 *     tx('Changing this needs {permission}, which an administrator holds.', {
 *       permission: <code>staff.tenants.manage</code>,
 *     })
 *
 * The sentence is translated whole, so a language can put the pieces in its
 * own order; the nodes are spliced in afterwards. Three `t()` fragments
 * around a `<code>` were three keys a translator saw in isolation, and a
 * sentence cut where English cuts it does not survive German.
 */
export function tx(english: string, vars: Readonly<Record<string, ReactNode>>): ReactNode {
  // No string vars: the placeholders come back as written, to be split on.
  const text = t(english);
  const parts = text.split(/(\{[a-zA-Z_]+\})/g);

  return parts.map((part, index) => {
    const placeholder = /^\{([a-zA-Z_]+)\}$/.exec(part);
    const name = placeholder?.[1];

    // Keyed by position: the parts of one sentence never reorder among themselves.
    if (name !== undefined && Object.hasOwn(vars, name)) {
      return <Fragment key={index}>{vars[name]}</Fragment>;
    }

    return part === '' ? null : <Fragment key={index}>{part}</Fragment>;
  });
}
