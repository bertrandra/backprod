/**
 * A size on screen.
 *
 * The API speaks whole bytes (`document_bytes`), measured by the server the way
 * its storage limit measures them, and this only chooses a unit to read them in
 * — the same job `Money.tsx` does for minor units, and nothing more. The size
 * itself is never computed here: a screen that measured `JSON.stringify` of a
 * document would print a second number for one document, and it would not be
 * the one the next save is judged by.
 *
 * **Steps of 1024, named by `Intl`.** The limit is 4 MiB, so a document at the
 * limit reads "4 MB" rather than "4.19 MB" — a figure nobody could compare with
 * the refusal. `Intl` supplies the unit's name in each language ("Mo" in
 * French, "MB" in English), so no catalogue has to.
 */

const UNITS = ['byte', 'kilobyte', 'megabyte', 'gigabyte'] as const;

export function formatBytes(bytes: number, locale?: string): string {
  let value = bytes;
  let unit = 0;

  while (value >= 1024 && unit < UNITS.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return new Intl.NumberFormat(locale, {
    style: 'unit',
    unit: UNITS[unit],
    // Spelled out below a kilobyte: the short form is "512 byte" in English,
    // which reads as a typo, and "512 bytes" / "512 octets" do not.
    unitDisplay: unit === 0 ? 'long' : 'short',
    // Whole bytes, and one decimal above that: "812 KB", "3.4 MB".
    maximumFractionDigits: unit === 0 || value >= 100 ? 0 : 1,
  }).format(value);
}
