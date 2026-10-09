import type { ReactNode } from "react";

/**
 * A `border-line` card on `bg-surface-raised` with the faint `shadow-sm` the public
 * site's cards carry since the 2026-10 redesign — one card look across the site and
 * the panel (SC-004).
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
export const CARD_INTERACTIVE =
  "transition duration-300 ease-out hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 motion-safe:hover:-translate-y-1";

export function Card({
  children,
  padding = "md",
  as: Tag = "div",
  interactive = false,
  labelledBy,
}: {
  children: ReactNode;
  padding?: keyof typeof PADDING;
  as?: "div" | "article" | "section";
  /** A card the reader scans as one of a set (a rate, a figure, a plan). */
  interactive?: boolean;
  /** The `SectionHeading` id inside, so a `section` card is a named landmark. */
  labelledBy?: string;
}) {
  return (
    <Tag
      aria-labelledby={labelledBy}
      // rounded-3xl to sit with the pill controls: a card corner tighter than
      // its own buttons reads as two systems in one frame.
      className={`rounded-3xl border border-line bg-surface-raised shadow-sm ${PADDING[padding]} ${
        interactive ? CARD_INTERACTIVE : ""
      }`}
    >
      {children}
    </Tag>
  );
}
