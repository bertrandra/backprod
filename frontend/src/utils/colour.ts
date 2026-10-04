/**
 * Colour as the palette editor handles it (2026-10-04): a `#rrggbb` code, its
 * red/green/blue channels, its hue/saturation/lightness, and the shades a
 * hue offers. Presentation only — what a colour picker shows — never a value
 * the platform decides anything from.
 *
 * Anything that is not six hex digits (`rgb(14 21 32 / 0.4)`, the scrim's) is
 * `null` here: the editor leaves such a value to its text field rather than
 * guess at what a picker should do with transparency.
 */
export interface Rgb {
  readonly r: number;
  readonly g: number;
  readonly b: number;
}

export interface Hsl {
  readonly h: number;
  readonly s: number;
  readonly l: number;
}

const HEX = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i;

export function isHex(value: string): boolean {
  return HEX.test(value);
}

export function toRgb(hex: string): Rgb | null {
  const match = HEX.exec(hex);

  if (match === null) {
    return null;
  }

  return { r: parseInt(match[1] ?? '0', 16), g: parseInt(match[2] ?? '0', 16), b: parseInt(match[3] ?? '0', 16) };
}

const channel = (value: number): string => Math.round(Math.min(255, Math.max(0, value))).toString(16).padStart(2, '0');

export function fromRgb({ r, g, b }: Rgb): string {
  return `#${channel(r)}${channel(g)}${channel(b)}`;
}

export function toHsl(hex: string): Hsl | null {
  const rgb = toRgb(hex);

  if (rgb === null) {
    return null;
  }

  const r = rgb.r / 255;
  const g = rgb.g / 255;
  const b = rgb.b / 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2;
  const d = max - min;

  if (d === 0) {
    return { h: 0, s: 0, l: Math.round(l * 100) };
  }

  const s = d / (1 - Math.abs(2 * l - 1));
  let h: number;

  if (max === r) {
    h = ((g - b) / d) % 6;
  } else if (max === g) {
    h = (b - r) / d + 2;
  } else {
    h = (r - g) / d + 4;
  }

  return { h: Math.round((h * 60 + 360) % 360), s: Math.round(s * 100), l: Math.round(l * 100) };
}

export function fromHsl({ h, s, l }: Hsl): string {
  const sat = Math.min(100, Math.max(0, s)) / 100;
  const light = Math.min(100, Math.max(0, l)) / 100;
  const c = (1 - Math.abs(2 * light - 1)) * sat;
  const hue = (((h % 360) + 360) % 360) / 60;
  const x = c * (1 - Math.abs((hue % 2) - 1));
  const m = light - c / 2;
  const [r, g, b] =
    hue < 1 ? [c, x, 0] : hue < 2 ? [x, c, 0] : hue < 3 ? [0, c, x] : hue < 4 ? [0, x, c] : hue < 5 ? [x, 0, c] : [c, 0, x];

  return fromRgb({ r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 });
}

/**
 * Nine steps of one hue, lightest to darkest, keeping its saturation: the
 * shades somebody reaches for when a colour is right but too light or dark.
 */
export function shades(hex: string): readonly string[] {
  const hsl = toHsl(hex);

  if (hsl === null) {
    return [];
  }

  return [95, 88, 78, 66, 54, 42, 32, 22, 12].map((l) => fromHsl({ ...hsl, l }));
}
