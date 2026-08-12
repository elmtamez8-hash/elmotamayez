"use client";

import { useEffect, useLayoutEffect, useRef, useState } from "react";

/**
 * A number that counts up to its value the first time it is seen.
 *
 * ⚠️ THE FINAL VALUE IS THE DEFAULT, NOT THE END OF AN ANIMATION. The server
 * renders it, so a crawler, a reader with JavaScript off, and a failed hydration
 * all get the real figure — this component only ever replaces a correct number
 * with the same correct number. The countdown to zero happens in
 * `useLayoutEffect`, before the browser paints, so there is no flash of 442
 * followed by 0.
 *
 * It runs ONCE. A figure that re-counts every time it scrolls past is a number
 * performing rather than reporting, and this product's first principle is that
 * nothing appears on screen the server cannot derive.
 */
export function AnimatedNumber({
  value,
  suffix,
  durationMs = 900,
}: {
  value: number;
  suffix?: string;
  /** Fixed, not scaled by magnitude: 5 and 1,325 arriving together reads as one
      section settling, while per-value durations read as a race. */
  durationMs?: number;
}) {
  const [shown, setShown] = useState(value);
  const ref = useRef<HTMLSpanElement>(null);
  const done = useRef(false);

  // Layout effect, not effect: this runs after the DOM is built and BEFORE the
  // browser paints, so dropping to zero is never visible.
  useLayoutEffect(() => {
    if (typeof window === "undefined") return;

    // FR-086. The global reduced-motion rule zeroes CSS durations; it cannot
    // reach a requestAnimationFrame loop, so the query is asked here too.
    const still = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (still || !("IntersectionObserver" in window)) {
      done.current = true;

      return;
    }

    setShown(0);
  }, []);

  useEffect(() => {
    const node = ref.current;

    if (node === null || done.current) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (!entries.some((entry) => entry.isIntersecting) || done.current) return;

        done.current = true;
        observer.disconnect();

        const start = performance.now();

        const step = (now: number) => {
          const progress = Math.min((now - start) / durationMs, 1);
          // The same exponential ease-out the surface uses for arrivals, written
          // out because a CSS curve cannot drive a JS value.
          const eased = 1 - Math.pow(1 - progress, 4);

          setShown(Math.round(value * eased));

          if (progress < 1) requestAnimationFrame(step);
        };

        requestAnimationFrame(step);
      },
      // Fires a little before the section reaches the middle of the screen, so
      // the count is already settling by the time it is comfortable to read.
      { threshold: 0.4 },
    );

    observer.observe(node);

    return () => observer.disconnect();
  }, [value, durationMs]);

  return (
    // tabular-nums so the box cannot jitter while the digits change: the value
    // moves, the layout does not.
    <span ref={ref} className="tabular-nums">
      {shown.toLocaleString("ar-QA")}
      {suffix}
    </span>
  );
}
