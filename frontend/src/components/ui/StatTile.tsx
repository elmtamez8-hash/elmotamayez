import type { ComponentType, ReactNode } from "react";

import type { IconProps } from "@/components/icons";

/**
 * One headline number: its label, the value, an optional hint — and an icon so a
 * row of four numbers can be told apart at a glance rather than read label by
 * label.
 *
 * ⚠️ `<bdi>` around the value so a Latin-digit amount cannot reorder the Arabic
 * around it. The hover is the shared card hover (`Card interactive`), spelled
 * here once because a tile is not a `Card` child.
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
  value: string;
  hint?: ReactNode;
  Icon?: ComponentType<IconProps>;
  emphasis?: boolean;
}) {
  return (
    <div
      className={`group rounded-3xl border p-4 transition duration-200 motion-safe:hover:-translate-y-0.5 ${
        emphasis
          ? "border-primary/30 bg-primary-soft hover:border-primary/60"
          : "border-line bg-surface-raised hover:border-primary/40 hover:bg-primary-soft/30"
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <p className="text-xs font-medium text-ink-muted">{label}</p>
        {Icon !== undefined && (
          <span
            className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg transition duration-200 motion-safe:group-hover:scale-110 ${
              emphasis ? "bg-primary text-white" : "bg-primary-soft text-primary-ink"
            }`}
          >
            <Icon className="h-4 w-4" />
          </span>
        )}
      </div>
      <bdi className="mt-1 block text-2xl font-bold text-ink">{value}</bdi>
      {hint !== undefined && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </div>
  );
}
