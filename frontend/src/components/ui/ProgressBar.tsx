"use client";

import { useEffect, useState } from "react";

/**
 * How far through something you are.
 *
 * ⚠️ ONE COMPONENT BECAUSE THE ACCESSIBILITY IS THE HARD PART, and it was
 * hand-rolled in two places already — `/dashboard` and `/enrollments` — each
 * repeating `role="progressbar"` with its own four ARIA attributes. A third copy
 * is where one of them loses `aria-valuemax` and a screen reader starts reading
 * «٤٠» with nothing to compare it to.
 *
 * No free-form `className`: appearance is a closed set, same rule as `Button`.
 */

const TONES = {
  primary: "bg-primary",
  // A finished course, not one in flight. The distinction is carried by the
  // label beside it too — colour alone would leave it invisible to a reader who
  // cannot separate maroon from green.
  secondary: "bg-secondary",
} as const;

const SIZES = {
  sm: "h-1.5",
  md: "h-2",
} as const;

export function ProgressBar({
  value,
  label,
  tone = "primary",
  size = "md",
}: {
  /** 0–100. Clamped, because a server that ever sends 103 must not overflow. */
  value: number;
  /** What the number is about — the bar's accessible name. */
  label: string;
  tone?: keyof typeof TONES;
  size?: keyof typeof SIZES;
}) {
  const pct = Math.max(0, Math.min(100, Math.round(value)));

  /*
   | ⚠️ THE FILL STARTS AT ZERO AND IS SET AFTER MOUNT, so the browser has two
   | widths to transition between — set straight to `pct` there is nothing to
   | animate from and the bar simply appears. `prefers-reduced-motion` in
   | globals.css zeroes the transition duration, which lands it at the right
   | width instantly rather than at zero: the value is never wrong, only still.
   */
  const [filled, setFilled] = useState(false);

  useEffect(() => setFilled(true), []);

  return (
    <div
      className={`${SIZES[size]} overflow-hidden rounded-full bg-line`}
      role="progressbar"
      aria-valuenow={pct}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label}
    >
      <div
        className={`h-full rounded-full ${TONES[tone]} transition-[width] duration-700 ease-out`}
        style={{ width: `${filled ? pct : 0}%` }}
      />
    </div>
  );
}
