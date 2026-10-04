/**
 * WCAG relative luminance contrast between two `#rgb`/`#rrggbb` colours, or
 * undefined for anything else — a value this cannot read is shown as unknown
 * rather than guessed at.
 */
export function contrast(foreground: string, background: string): number | undefined {
  const luminance = (hex: string): number | undefined => {
    const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(hex);
    const long = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
    const parts = long !== null ? long.slice(1) : short?.slice(1).map((c) => c + c);

    if (parts === undefined) {
      return undefined;
    }

    const [r = 0, g = 0, b = 0] = parts.map((part) => {
      const channel = parseInt(part, 16) / 255;

      return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };

  const a = luminance(foreground);
  const b = luminance(background);

  if (a === undefined || b === undefined) {
    return undefined;
  }

  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
