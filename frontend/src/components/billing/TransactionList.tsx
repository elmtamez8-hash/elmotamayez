"use client";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Table, type Column } from "@/components/ui/Table";
import { formatCredits, type CreditTransaction } from "@/lib/billing";
import { formatDate } from "@/lib/labels";

/**
 * The ledger, newest first.
 *
 * Every line shows which way it moved and why. `reason` is the field that turns
 * a correction from alarming into readable — a −2 with no explanation is a
 * support ticket, and the backend makes the reason mandatory for exactly that.
 *
 * No money column, because the API sends none (FR-021د).
 */

const COLUMNS: Column<CreditTransaction>[] = [
  {
    key: "created_at",
    header: "التاريخ",
    render: (row) => formatDate(row.created_at),
  },
  {
    key: "type",
    header: "النوع",
    render: (row) => (
      <Badge tone={row.credits < 0 ? "warning" : "success"}>{row.type_label}</Badge>
    ),
  },
  {
    key: "credits",
    header: "الحصص",
    numeric: true,
    // The sign is the fact, so it is spelled out rather than left to a glyph
    // that the bidirectional algorithm may place on either side.
    render: (row) =>
      row.credits < 0
        ? `− ${formatCredits(-row.credits)}`
        : `+ ${formatCredits(row.credits)}`,
  },
  {
    key: "reason",
    header: "السبب",
    render: (row) => row.reason ?? "—",
  },
];

export function TransactionList({
  transactions,
  state,
  page,
  lastPage,
  onPageChange,
  onRetry,
}: {
  transactions: CreditTransaction[];
  state: "ready" | "loading" | "empty" | "error";
  page: number;
  lastPage: number;
  onPageChange: (page: number) => void;
  onRetry?: () => void;
}) {
  return (
    <div className="space-y-4">
      <Table
        columns={COLUMNS}
        rows={transactions}
        rowKey={(row) => row.uuid}
        caption="سجلّ حركة الحصص"
        state={state}
        emptyTitle="لا حركة بعد"
        emptyDescription="ستظهر هنا كل إضافة أو استهلاك لحصصك."
        onRetry={onRetry}
      />

      {lastPage > 1 && (
        <div className="flex items-center justify-between gap-3">
          <Button
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => onPageChange(page - 1)}
          >
            الأحدث
          </Button>

          <span className="text-sm text-ink-muted">
            <bdi>
              {page.toLocaleString("ar-EG")} من {lastPage.toLocaleString("ar-EG")}
            </bdi>
          </span>

          <Button
            variant="secondary"
            size="sm"
            disabled={page >= lastPage}
            onClick={() => onPageChange(page + 1)}
          >
            الأقدم
          </Button>
        </div>
      )}
    </div>
  );
}
