import { formatMinorMoney } from "@/lib/labels";
import { planShape } from "@/lib/plans";
import type { CoursePrice } from "@/lib/public-api";

/**
 * A plan's price on the course page, ALWAYS with what it buys — «٣٠٠ ر.ق. ·
 * شهر واحد» or «… · ٨ حصص». An amount alone would let a pack of eight read as a
 * cheaper month (review 2026-10-09).
 */
export function PriceTag({ price, size = "sm" }: { price: CoursePrice; size?: "sm" | "lg" }) {
  const per = planShape(price);

  return (
    <span className="flex flex-wrap items-baseline gap-x-2">
      <bdi className={size === "lg" ? "text-3xl font-black text-primary-ink" : "text-base font-extrabold text-primary-ink"}>
        {formatMinorMoney(price.price_minor, price.currency)}
      </bdi>
      {per !== null && <span className="text-sm font-semibold text-ink-muted">/ {per}</span>}
    </span>
  );
}
