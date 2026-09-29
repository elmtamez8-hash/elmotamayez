import type { ComponentType, ReactNode } from "react";

import type { IconProps } from "@/components/icons";

/**
 * Every list in the product shows this when it has nothing (FR-078). An empty
 * grid with no explanation reads as a broken page, so the copy always names a
 * next action rather than just stating the absence.
 *
 * `Icon` is optional and names what the list would hold (the screen's own icon
 * from `components/icons`). Without it the search glyph stays, which is what
 * every older caller — a filtered list with no match — actually means.
 */
export function EmptyState({
  title,
  description,
  action,
  Icon,
}: {
  title: string;
  description: string;
  action?: ReactNode;
  Icon?: ComponentType<IconProps>;
}) {
  return (
    <div
      className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-line px-6 py-16 text-center"
      role="status"
    >
      {Icon !== undefined ? (
        <span className="mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-primary-soft text-primary-ink">
          <Icon className="h-7 w-7" />
        </span>
      ) : (
      <svg
        className="mb-4 h-12 w-12 text-ink-muted"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.5}
        aria-hidden="true"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"
        />
      </svg>
      )}
      <h2 className="mb-2 text-lg font-semibold text-ink">{title}</h2>
      <p className="mb-6 max-w-md text-sm leading-relaxed text-ink-muted">
        {description}
      </p>
      {action}
    </div>
  );
}
