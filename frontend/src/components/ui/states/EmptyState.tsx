import type { ComponentType, ReactNode } from "react";

import { SearchIcon, type IconProps } from "@/components/icons";

/**
 * Every list in the product shows this when it has nothing (FR-078). An empty
 * grid with no explanation reads as a broken page, so the copy always names a
 * next action rather than just stating the absence.
 *
 * `Icon` is optional and names what the list would hold (the screen's own icon
 * from `components/icons`). Without it the search glyph stays, which is what
 * every older caller — a filtered list with no match — actually means.
 *
 * ⚠️ NOT THE CENTRED ICON-OVER-TITLE-OVER-LINE STACK. The owner read that shape
 * as machine-made (2026-10-09), and it says nothing about THIS list. The text
 * sits start-aligned like any paragraph on the site, and beside it is the list
 * itself, empty: three dashed rows where the items would be, the first one
 * carrying the list's icon. On hover the rows slide and fill, as if about to be
 * filled. It is sized by its CONTAINER (`@container`), not the viewport, because
 * half of its 73 callers sit inside a narrow panel card — there the shelf stacks
 * above the text.
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
  const Glyph = Icon ?? SearchIcon;
  const move = "transition duration-500 ease-out motion-reduce:transition-none";

  return (
    <div className="@container" role="status">
      <div
        className={`group grid items-center gap-8 rounded-3xl border border-line bg-surface-raised p-6 shadow-sm hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 sm:p-8 @lg:grid-cols-[minmax(0,1fr)_minmax(0,15rem)] @lg:gap-10 ${move}`}
      >
        {/* The empty shelf — decoration, so hidden from assistive tech. */}
        <div aria-hidden="true" className="space-y-3 @lg:order-last">
          <div
            className={`flex items-center gap-3 rounded-2xl border-2 border-dashed border-primary/30 bg-primary-soft/40 p-3 group-hover:-translate-x-2 group-hover:border-primary/50 group-hover:bg-primary-soft motion-reduce:group-hover:translate-x-0 ${move}`}
          >
            <span
              className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary text-white group-hover:rotate-6 motion-reduce:group-hover:rotate-0 ${move}`}
            >
              <Glyph className="h-5 w-5" />
            </span>
            <span className="flex-1 space-y-2">
              <span className="block h-2 w-3/4 rounded-full bg-primary/25" />
              <span className="block h-2 w-1/2 rounded-full bg-primary/15" />
            </span>
          </div>
          <div
            className={`flex items-center gap-3 rounded-2xl border-2 border-dashed border-line p-3 delay-75 group-hover:-translate-x-1 group-hover:border-primary/30 motion-reduce:group-hover:translate-x-0 ${move}`}
          >
            <span className="h-10 w-10 shrink-0 rounded-xl border-2 border-dashed border-line" />
            <span className="flex-1 space-y-2">
              <span className="block h-2 w-2/3 rounded-full bg-line" />
              <span className="block h-2 w-1/3 rounded-full bg-line" />
            </span>
          </div>
          <div
            className={`flex items-center gap-3 rounded-2xl border-2 border-dashed border-line p-3 opacity-60 delay-150 group-hover:opacity-100 ${move}`}
          >
            <span className="h-10 w-10 shrink-0 rounded-xl border-2 border-dashed border-line" />
            <span className="flex-1 space-y-2">
              <span className="block h-2 w-1/2 rounded-full bg-line" />
              <span className="block h-2 w-1/4 rounded-full bg-line" />
            </span>
          </div>
        </div>

        <div className="text-start">
          <h2 className="mb-2 text-balance text-2xl font-extrabold leading-snug text-ink">{title}</h2>
          <p className="mb-6 max-w-md leading-relaxed text-ink-muted">{description}</p>
          {action}
        </div>
      </div>
    </div>
  );
}
