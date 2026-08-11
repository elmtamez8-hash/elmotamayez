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

export function Card({
  children,
  padding = "md",
  as: Tag = "div",
}: {
  children: ReactNode;
  padding?: keyof typeof PADDING;
  as?: "div" | "article" | "section";
}) {
  return (
    <Tag
      // rounded-3xl to sit with the pill controls: a card corner tighter than
      // its own buttons reads as two systems in one frame.
      className={`rounded-3xl border border-line bg-surface-raised ${PADDING[padding]}`}
    >
      {children}
    </Tag>
  );
}
