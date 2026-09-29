import type { CSSProperties } from "react";

/**
 * The staggered arrival every staff list shares: put `className="stagger-item"`
 * on the element and spread `style={staggerStyle(index)}` onto it.
 *
 * The numbers live in ONE place — `.stagger-item` in `globals.css` (0.36s rise,
 * 40ms step, capped at the ninth item so a long list never arrives late). This
 * helper only hands the index to CSS; it never computes a delay itself, so a
 * page cannot drift to its own timing.
 *
 * Reduced motion is the global block's job and needs nothing here: it zeroes the
 * duration and the delay of every animation.
 */
export const STAGGER_CLASS = "stagger-item";

export function staggerStyle(index: number): CSSProperties {
  return { "--stagger-i": Math.max(0, Math.floor(index)) } as CSSProperties;
}
