"use client";

import type { ComponentType } from "react";

import type { IconProps } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";

/**
 * One axis of a periodic assessment, as a score out of five you can read at a
 * glance.
 *
 * ⚠️ FIVE DOTS RATHER THAN A BARE NUMBER, AND THE NUMBER STAYS. «٣» tells a
 * student nothing until they remember the scale; five marks with three filled
 * says «three out of five» without being read. Removing the digit would be the
 * opposite mistake — a screen reader announcing a row of decorative dots has been
 * told nothing at all, which is why the dots are `aria-hidden` and the figure
 * carries the meaning.
 *
 * ⚠️ THE FILL IS `bg-primary` AND THE REST IS `bg-line`, BOTH REAL TOKENS. There
 * is no `success` token in this product — `bg-success` has shipped three times
 * and painted NOTHING each time, because Tailwind v4 emits no rule for a token
 * `@theme` never defined and the class fails silently. A colour that grades the
 * score (red at one, green at five) would need four of them and would also be a
 * judgement this component has no business making: the teacher's note says what
 * the number means.
 *
 * ⚠️ AND THE DOTS ARE STAGGERED WITH `animate-float-in`, an existing class. The
 * reduced-motion block in `globals.css` zeroes every animation for free; a
 * hand-rolled transition here would have to reimplement that and would be the one
 * place that forgot.
 */
export function AxisScore({
  label,
  value,
  Icon,
  delayMs = 0,
}: {
  label: string;
  /** 1–5, as the teacher recorded it. */
  value: number;
  Icon: ComponentType<IconProps>;
  delayMs?: number;
}) {
  const filled = Math.max(0, Math.min(5, Math.round(value)));

  return (
    <div className="rounded-2xl border border-line bg-surface p-3 transition-colors hover:border-primary">
      <div className="flex items-center gap-2">
        <Icon className="h-4 w-4 shrink-0 text-primary-ink" />
        <span className="truncate text-xs text-ink-muted">{label}</span>
      </div>

      <div className="mt-2 flex items-center gap-2">
        {/*
          The figure is the accessible answer — «الالتزام ٣ من ٥» — and the dots
          beside it are decoration that must not be announced twice.
        */}
        <span className="text-lg font-bold text-ink">
          {/* Arabic-Indic digits, as everywhere else in the product — a Latin «3»
              beside «الالتزام» is the mixed-numeral slip `numerals.ts` exists to
              stop. */}
          <bdi>{arabicNumber(value)}</bdi>
        </span>

        <div aria-hidden className="flex items-center gap-1">
          {[1, 2, 3, 4, 5].map((step) => (
            <span
              key={step}
              className={`block h-1.5 w-1.5 rounded-full ${
                step <= filled ? "bg-primary animate-float-in" : "bg-line"
              }`}
              /*
                Only the filled ones animate, and they arrive in order — an empty
                dot popping in draws the eye to the part of the score that is not
                there. The delay is capped by the caller.
              */
              style={step <= filled ? { animationDelay: `${delayMs + step * 40}ms` } : undefined}
            />
          ))}
        </div>
      </div>

      <span className="sr-only">{`${label}: ${arabicNumber(value)} من ٥`}</span>
    </div>
  );
}
