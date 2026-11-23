import { useEffect, useRef, useState } from 'react';

/**
 * A figure that counts up once it is in view (2026-11-23).
 *
 * The figure is the operator's text — `1 200`, `−40 %`, `3 min` — and
 * stays it: what moves is the number inside it, from zero to itself, and
 * the words around it are left alone. Written the way it was written, so
 * `1 200` counts through `1 199` and not `1199`, and `2,5` keeps its comma.
 *
 * Text that has no number in it is shown as it is. So is everything for a
 * reader who asked the system for less motion, and for a browser with no
 * way to tell when the figure is seen.
 */
export function CountUp({ value }: { value: string }) {
  const parsed = parse(value);
  const here = useRef<HTMLSpanElement>(null);
  const [shown, setShown] = useState<string>(parsed === null ? value : parsed.at(0));

  useEffect(() => {
    const element = here.current;

    if (
      parsed === null ||
      element === null ||
      typeof IntersectionObserver === 'undefined' ||
      window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
    ) {
      setShown(value);

      return;
    }

    let frame = 0;
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) {
        return;
      }

      observer.disconnect();
      const started = performance.now();
      const step = (now: number) => {
        const progress = Math.min(1, (now - started) / 1200);
        // Fast, then settling: the last digits arrive slowly enough to read.
        setShown(parsed.at(1 - (1 - progress) ** 3));

        if (progress < 1) {
          frame = requestAnimationFrame(step);
        }
      };

      frame = requestAnimationFrame(step);
    });

    observer.observe(element);

    return () => {
      observer.disconnect();
      cancelAnimationFrame(frame);
    };
    // The text is the whole input; `parsed` is derived from it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  return (
    <span ref={here} data-count={value}>
      <span aria-hidden="true">{shown}</span>
      <span className="sr-only">{value}</span>
    </span>
  );
}

interface Parsed {
  /** The figure as it reads at a share of the way from zero to itself. */
  at: (share: number) => string;
}

function parse(value: string): Parsed | null {
  const found = /^(\D*?)(\d{1,3}(?:[ \u00a0\u202f]\d{3})+|\d+)(?:([.,])(\d+))?(.*)$/u.exec(value);

  if (found === null) {
    return null;
  }

  const [, before = '', whole = '', mark = '', decimals = '', after = ''] = found;
  const group = /[ \u00a0\u202f]/u.exec(whole)?.[0] ?? null;
  const target = Number(`${whole.replace(/\D/g, '')}.${decimals === '' ? '0' : decimals}`);

  if (!Number.isFinite(target)) {
    return null;
  }

  return {
    at: (share) => {
      const now = target * share;
      const [integer = '0', fraction = ''] = now.toFixed(decimals.length).split('.');
      const grouped = group === null ? integer : integer.replace(/\B(?=(\d{3})+(?!\d))/g, group);

      return `${before}${grouped}${decimals === '' ? '' : `${mark}${fraction}`}${after}`;
    },
  };
}
