import { t } from '@/i18n';

/**
 * What each colour of the design system paints (2026-10-05), one line each —
 * what the palette editor shows beside a token and searches by.
 *
 * Keyed by the utility root (`accent-wash`, used as `bg-accent-wash`), which is
 * the `name` a theme document gives a token. The design system's own list is
 * the `--color-*` runs of `src/index.css`; a test holds this one equal to it,
 * both ways, so a token added there without a role here fails the build, and
 * a role left behind for a token gone does too.
 *
 * Taken from Plan's palette screen, whose every token says what it colours:
 * somebody choosing `well` is helped more by "a well inside a card" than by
 * the word.
 */
export const TOKEN_ROLES: Readonly<Record<string, string>> = {
  canvas: 'The page ground, under everything',
  surface: 'Cards and panels that sit on the page',
  well: 'A recess inside a card: inputs, code, quiet tiles',
  raised: 'What floats: menus, popovers, the command palette',
  ink: 'Text being read, and headings',
  muted: 'Text that explains: labels, hints, secondary lines',
  subtle: 'Text only there when looked for: placeholders, meta',
  line: 'Hairlines between things',
  'line-strong': 'Borders of what can be pressed or typed into',
  accent: 'Links, focus, the selected and active state',
  'accent-strong': 'Accent text set on the accent wash',
  'accent-wash': 'A light accent ground: selection, active tab, hover',
  'on-accent': 'Text on a solid accent button',
  inverse: 'The primary button, and other solid dark-on-light blocks',
  'on-inverse': 'Text on the inverse block',
  scrim: 'What dims the page behind a sheet or a dialog',
  success: 'Done, paid, saved: its text and icon',
  'success-wash': 'The ground of a success notice or pill',
  warning: 'Needs attention soon: its text and icon',
  'warning-wash': 'The ground of a warning notice or pill',
  danger: 'Failed, refused, destructive: its text and icon',
  'danger-wash': 'The ground of an error notice or pill',
  info: 'In flight, neither good nor bad: its text and icon',
  'info-wash': 'The ground of an in-flight notice or pill',
};

/** A token's role, in the reader's language; undefined for a token the design system does not have. */
export function roleOf(name: string): string | undefined {
  const role = TOKEN_ROLES[name];

  return role === undefined ? undefined : t(role);
}
