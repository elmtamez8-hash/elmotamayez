"use client";

import type { ComponentType, ReactNode } from "react";

import type { IconProps } from "@/components/icons";
import { RowsSkeleton } from "./states/LoadingSkeleton";
import { EmptyState } from "./states/EmptyState";
import { ErrorState } from "./states/ErrorState";

/**
 * The panel's table.
 *
 * Column order is start-to-end and needs no reversing: `dir="rtl"` on the
 * document already lays the first cell on the right. Reversing the array by hand
 * — the instinct when a table looks backwards — double-flips it (FR-014).
 *
 * The three list states live here rather than in each page, so a screen cannot
 * ship with a loading state and forget the error one (FR-018, SC-005).
 */

export type Column<T> = {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  align?: "start" | "end";
  /** Wraps the value in <bdi> and aligns it to the end — for money and counts. */
  numeric?: boolean;
};

type TableProps<T> = {
  columns: Column<T>[];
  rows: T[];
  /**
   * The React key. The index is passed as a fallback for rows whose only
   * natural id lives behind a relation that may not have loaded — never reach
   * for it when the row carries a uuid.
   */
  rowKey: (row: T, index: number) => string;
  /** Required. A table with no description is unreadable to a screen reader. */
  caption: string;
  state?: "ready" | "loading" | "empty" | "error";
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  /**
   * The screen's own icon for the TRUE empty state («no shipments yet»). Left
   * out, `EmptyState` keeps its magnifier, which means «no match» — right for a
   * filtered list, wrong for a list that is simply empty (`manage-pages.md` §2).
   */
  emptyIcon?: ComponentType<IconProps>;
  onRetry?: () => void;
};

export function Table<T>({
  columns,
  rows,
  rowKey,
  caption,
  state = "ready",
  emptyTitle = "لا بيانات لعرضها",
  emptyDescription = "لم يُسجَّل شيء هنا بعد.",
  emptyAction,
  emptyIcon,
  onRetry,
}: TableProps<T>) {
  if (state === "loading") return <RowsSkeleton />;
  if (state === "error") return <ErrorState onRetry={onRetry} />;
  if (state === "empty" || rows.length === 0) {
    return (
      <EmptyState
        title={emptyTitle}
        description={emptyDescription}
        action={emptyAction}
        Icon={emptyIcon}
      />
    );
  }

  return (
    /*
      The scroll container is the wrapper, never the page: a wide table must not
      make the document itself pan sideways at 360px (SC-008).

      ⛔ **AND THAT PROMISE WAS FALSE UNTIL 2026-09-17: `overflow-x-auto` ALONE
      DOES NOT KEEP IT, AND `min-w-max` BELOW IS WHAT BREAKS IT.** A scroll
      container's content still contributes to the minimum width it reports
      upward, so `min-width: max-content` on the table travels through this div,
      through `main`, and into the shell's flex item — which then cannot shrink,
      and the DOCUMENT grows a horizontal scrollbar. Measured on
      `/manage/exams` with a table forced wider than the viewport:
      `documentElement.scrollWidth` 1536 against `clientWidth` 1382.

      `contain: inline-size` is what actually keeps it (measured: 1382). It makes
      this box's width independent of its content — the definition of a scroll
      container — so `min-w-max` keeps its own promise («columns are not crushed,
      the table scrolls») without the page paying for it. `min-width: 0` on this
      div, on `main`, or on the flex item does NOT help; all three were measured
      at 1536.

      ⚠️ NO TEST IN THIS REPOSITORY CAN SEE THIS. jsdom has no layout, so every
      width is zero and the whole suite was green over it for thirty screens. It
      was found by looking at a page, then measured in the browser.
    */
    /*
      ⚠️ AND `relative` IS PART OF THAT PROMISE. An `sr-only` label inside a cell
      is `position: absolute`; with no positioned ancestor its containing block is
      the page, so it is laid out at its static spot far off-screen and the
      wrapper's overflow does NOT clip it — measured on `/manage/certificates` at
      375px: `scrollWidth` 766 against 360, from «أعِد الإصدار»'s hidden label.
    */
    <div className="relative [contain:inline-size] overflow-x-auto rounded-2xl border border-line bg-surface-raised">
      <table className="w-full min-w-max text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead className="border-b border-line">
          <tr>
            {columns.map((col) => (
              <th
                key={col.key}
                scope="col"
                className={`px-4 py-3 font-medium text-ink-muted ${
                  col.numeric || col.align === "end" ? "text-end" : "text-start"
                }`}
              >
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {rows.map((row, index) => (
            <tr key={rowKey(row, index)} className="transition hover:bg-primary-soft/40">
              {columns.map((col) => (
                <td
                  key={col.key}
                  className={`px-4 py-3 text-ink ${
                    col.numeric || col.align === "end" ? "text-end" : "text-start"
                  }`}
                >
                  {col.numeric ? <bdi>{col.render(row)}</bdi> : col.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
