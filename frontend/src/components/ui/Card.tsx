import type { ReactNode } from "react";

/**
 * Border, not shadow. The public site builds its visual hierarchy with `border-line`
 * on `bg-surface-raised`, and a shadowed panel next to a bordered one reads as a
 * second design system (SC-004).
 */

const PADDING = {
  none: "",
  sm: "p-4",
  md: "p-6",
} as const;

/**
 * The one hover every card in the dashboard shares: the border warms to the
 * brand colour and the card lifts a hair. Border and tint, not shadow — the rule
 * above. The lift is `motion-safe:` so a reader who asked for reduced motion gets
 * the colour change alone.
 */
const INTERACTIVE =
  "transition duration-200 hover:border-primary/40 hover:bg-primary-soft/30 motion-safe:hover:-translate-y-0.5";

export function Card({
  children,
  padding = "md",
  as: Tag = "div",
  interactive = false,
}: {
  children: ReactNode;
  padding?: keyof typeof PADDING;
  as?: "div" | "article" | "section";
  /** A card the reader scans as one of a set (a rate, a figure, a plan). */
  interactive?: boolean;
}) {
  return (
    <Tag
      // rounded-3xl to sit with the pill controls: a card corner tighter than
      // its own buttons reads as two systems in one frame.
      className={`rounded-3xl border border-line bg-surface-raised ${PADDING[padding]} ${
        interactive ? INTERACTIVE : ""
      }`}
    >
      {children}
    </Tag>
  );
}
