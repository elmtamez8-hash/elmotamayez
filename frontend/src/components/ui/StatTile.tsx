import type { ComponentType, ReactNode } from "react";

import type { IconProps } from "@/components/icons";

import { AnimatedNumber } from "./AnimatedNumber";

/**
 * One headline number: its label, the value, an optional hint — and an icon so a
 * row of four numbers can be told apart at a glance rather than read label by
 * label.
 *
 * ⚠️ `<bdi>` around the value so a Latin-digit amount cannot reorder the Arabic
 * around it. The hover is the shared card hover (`Card interactive`), spelled
 * here once because a tile is not a `Card` child.
 *
 * A `number` value counts up once through `AnimatedNumber` (Arabic digits,
 * reduced motion respected); a `string` is shown as given — use it for money,
 * percentages and anything already formatted.
 *
 * `emphasis` gives the headline figure of a row (the net owed, the balance) the
 * brand fill — one per row, or none of them stands out.
 */
export function StatTile({
  label,
  value,
  hint,
  Icon,
  emphasis = false,
}: {
  label: string;
  value: string | number;
  hint?: ReactNode;
  Icon?: ComponentType<IconProps>;
  emphasis?: boolean;
}) {
  return (
    <div
      className={`group rounded-3xl border p-5 shadow-sm transition duration-300 ease-out hover:shadow-lg hover:shadow-primary/10 motion-safe:hover:-translate-y-1 ${
        emphasis
          ? "bg-squares relative isolate overflow-hidden border-primary bg-primary text-white"
          : "border-line bg-surface-raised hover:border-primary/40"
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <p className={`text-sm font-bold ${emphasis ? "text-white/80" : "text-ink-muted"}`}>{label}</p>
        {Icon !== undefined && (
          <span
            className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl transition duration-300 motion-safe:group-hover:rotate-6 ${
              emphasis
                ? "bg-accent text-accent-foreground"
                : "bg-primary-soft text-primary-ink group-hover:bg-primary group-hover:text-white"
            }`}
          >
            <Icon className="h-5 w-5" />
          </span>
        )}
      </div>
      <bdi className={`mt-2 block text-3xl font-extrabold ${emphasis ? "text-white" : "text-ink"}`}>
        {typeof value === "number" ? <AnimatedNumber value={value} /> : value}
      </bdi>
      {hint !== undefined && <p className={`mt-1 text-xs ${emphasis ? "text-white/75" : "text-ink-muted"}`}>{hint}</p>}
    </div>
  );
}
