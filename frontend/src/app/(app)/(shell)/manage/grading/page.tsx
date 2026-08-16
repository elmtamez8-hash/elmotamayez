"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { Table, type Column } from "@/components/ui/Table";
import { grading, type GradingQueueRow } from "@/lib/grading";
import { formatDateTime } from "@/lib/labels";

/**
 * What is waiting on a person (FR-027).
 *
 * ⚠️ THE AUTO-SCORE IS LABELLED, NEVER SHOWN BARE. It is the machine-marked half
 * of an unfinished paper; in a column called "الدرجة" it reads as a result, and a
 * teacher glancing down the list would conclude half the class failed a paper
 * nobody has marked yet.
 *
 * ⚠️ AND THE ORDER IS THE SERVER'S. Oldest first is the one property that keeps
 * a queue from starving whoever has waited longest; re-sorting in the browser
 * would silently undo it on every page after the first.
 */
export default function GradingQueuePage() {
  const [rows, setRows] = useState<GradingQueueRow[]>([]);
  const [total, setTotal] = useState(0);
  const [state, setState] = useState<"ready" | "loading" | "empty" | "error">("loading");

  const load = useCallback(() => {
    setState("loading");

    grading
      .queue()
      .then((response) => {
        const data = response.data ?? [];
        setRows(data);
        setTotal(response.meta?.total ?? 0);
        setState(data.length === 0 ? "empty" : "ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  const columns: Column<GradingQueueRow>[] = [
    {
      key: "student",
      header: "الطالب",
      render: (row) =>
        row.is_anonymous ? (
          // The word, not an empty cell: a blank reads as data that failed to
          // arrive, and the grader needs to know the omission is deliberate.
          <span className="text-ink-muted">مُخفى</span>
        ) : (
          (row.student?.name ?? "—")
        ),
    },
    { key: "exam", header: "الاختبار", render: (row) => row.exam_title ?? "—" },
    { key: "submitted", header: "سُلّمت", render: (row) => formatDateTime(row.submitted_at) },
    {
      key: "pending",
      header: "أسئلة تنتظر",
      numeric: true,
      render: (row) => <Badge tone="warning">{row.pending_count}</Badge>,
    },
    {
      key: "auto",
      header: "المصحَّح آلياً",
      numeric: true,
      render: (row) => `${Math.round(row.auto_score)}٪`,
    },
    {
      key: "open",
      header: "الإجراء",
      render: (row) => (
        <Link href={`/manage/grading/${row.uuid}`} className="text-primary hover:underline">
          صحّح
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">لوحة التصحيح</h1>
        <p className="text-sm text-ink-muted">
          أوراقٌ فيها أسئلة مقالية سُلّمت وتنتظر قراءتك. الأقدم أولاً، ولا تصل النتيجة
          الطالبَ قبل أن تنتهي منها.
        </p>
      </header>

      <Card>
        {state === "ready" && (
          <p className="mb-3 text-sm text-ink-muted">
            <bdi>{total}</bdi> ورقة بانتظار التصحيح.
          </p>
        )}
        <Table
          caption="الأوراق المنتظرة للتصحيح"
          columns={columns}
          rows={rows}
          rowKey={(row) => row.uuid}
          state={state}
          emptyTitle="لا شيء ينتظر التصحيح"
          emptyDescription="كلّ ورقةٍ فيها سؤال مقاليّ قُرئت. تظهر هنا الأوراق الجديدة فور تسليمها."
          onRetry={load}
        />
      </Card>
    </div>
  );
}
