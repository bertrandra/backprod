import { describe, expect, it } from 'vitest';
import { formatBytes } from './Bytes';

describe('formatBytes', () => {
  it('reads small sizes in bytes', () => {
    expect(formatBytes(512, 'en')).toBe('512 bytes');
  });

  it('steps by 1024, so the 4 MiB limit reads as 4 MB', () => {
    expect(formatBytes(4 * 1024 * 1024, 'en')).toBe('4 MB');
    expect(formatBytes(1536, 'en')).toBe('1.5 kB');
  });

  // French puts a narrow no-break space before the unit, which `Intl` knows.
  it("names the unit in the reader's language", () => {
    expect(formatBytes(3.5 * 1024 * 1024, 'fr')).toBe('3,5\u202fMo');
  });
});
