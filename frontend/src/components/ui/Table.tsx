"use client";

import type { ReactNode } from "react";
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
  rowKey: (row: T) => string;
  /** Required. A table with no description is unreadable to a screen reader. */
  caption: string;
  state?: "ready" | "loading" | "empty" | "error";
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
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
      />
    );
  }

  return (
    // The scroll container is the wrapper, never the page: a wide table must not
    // make the document itself pan sideways at 360px (SC-008).
    <div className="overflow-x-auto rounded-2xl border border-line bg-surface-raised">
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
          {rows.map((row) => (
            <tr key={rowKey(row)} className="transition hover:bg-primary-soft/40">
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
