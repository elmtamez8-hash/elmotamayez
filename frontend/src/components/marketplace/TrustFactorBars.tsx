"use client";

import { useEffect, useRef, useState } from "react";
import { AnimatedNumber } from "@/components/ui/AnimatedNumber";

export type TrustFactor = {
  label: string;
  /** Percentage points of the score. Negative for the complaints deduction. */
  weight: number;
  /** Shown instead of the bare number when the figure needs a qualifier. */
  note?: string;
};

/**
 * The trust score broken into the parts it is made of.
 *
 * The page's own copy promises this — «نوضّح مكوّناته بدل الاكتفاء بالرقم» — and
 * a column of right-aligned percentages does not deliver it: five numbers of
 * similar size read as a list, not as a division of one hundred. The bars make
 * the proportion the thing you see first and the number the confirmation.
 *
 * ⚠️ EVERY BAR STARTS AT THE INLINE START — the right, in Arabic — including the
 * deduction. Growing that one from the far edge was meant to say "this takes
 * away", and it said something else entirely: five bars in one stack are read as
 * one scale, and the odd one out looks like a rendering fault before it looks
 * like a meaning. The subtraction is carried by the label, which opens with
 * «خصم», by the red fill, and by the sign on the figure — three statements that
 * cost the reader nothing.
 */
export function TrustFactorBars({ factors }: { factors: TrustFactor[] }) {
  const [filled, setFilled] = useState(false);
  const ref = useRef<HTMLDListElement>(null);

  useEffect(() => {
    const node = ref.current;

    if (node === null) return;

    if (!("IntersectionObserver" in window)) {
      setFilled(true);

      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setFilled(true);
          observer.disconnect();
        }
      },
      { threshold: 0.3 },
    );

    observer.observe(node);

    return () => observer.disconnect();
  }, []);

  return (
    <dl ref={ref} className="space-y-5">
      {factors.map((factor, index) => {
        const negative = factor.weight < 0;
        const width = Math.abs(factor.weight);

        return (
          <div key={factor.label}>
            <div className="mb-2 flex items-baseline justify-between gap-4">
              <dt className="text-ink">{factor.label}</dt>
              <dd
                className={`text-sm font-bold ${negative ? "text-danger-ink" : "text-primary-ink"}`}
              >
                {factor.note ? (
                  <>
                    {factor.note}{" "}
                    {/* ⚠️ `dir="ltr"` is what puts the minus on the LEFT of the
                        digits, and nothing else can. `−` is a bidi NEUTRAL, and
                        Arabic-Indic digits count as right-to-left for the rule
                        that resolves neutrals — so in the surrounding Arabic the
                        sign is pushed to the far side of the figure and reads as
                        a stray dash. The attribute carries `unicode-bidi:
                        isolate` from the UA stylesheet, which makes the sign and
                        its number ONE run laid out left to right, exactly as a
                        negative number is written inside Arabic text. No CSS
                        property can do this; direction is resolved before paint.

                        The sign comes from `negative`, never from the note
                        string: a `−` typed into the data would be back outside
                        the isolate and back on the wrong side. */}
                    <span dir="ltr">
                      {negative ? "−" : ""}
                      <AnimatedNumber value={width} />
                    </span>{" "}
                    نقطة
                  </>
                ) : (
                  <AnimatedNumber value={width} suffix="٪" />
                )}
              </dd>
            </div>

            {/* aria-hidden: the dt/dd pair above already states the figure, and a
                second announcement of the same number is noise, not a graphic. */}
            <div
              className="h-2 overflow-hidden rounded-full bg-primary-soft"
              aria-hidden="true"
            >
              <div
                className={`h-full rounded-full ${negative ? "bg-danger" : "bg-primary"}`}
                style={{
                  width: filled ? `${width}%` : "0%",
                  // Staggered by row so the five read as one thing dividing
                  // rather than five bars racing. Capped: the last row starts a
                  // third of a second after the first, not two seconds after.
                  transitionDelay: `${index * 80}ms`,
                  transitionDuration: "700ms",
                  transitionProperty: "width",
                  transitionTimingFunction: "cubic-bezier(0.16, 1, 0.3, 1)",
                }}
              />
            </div>
          </div>
        );
      })}
    </dl>
  );
}
