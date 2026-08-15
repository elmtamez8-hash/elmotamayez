"use client";

import { use, useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Table, type Column } from "@/components/ui/Table";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { bank, importStatusLabel, type ImportReport, type ImportReportRow } from "@/lib/bank";

/**
 * One upload, row by row.
 *
 * ⚠️ THIS PAGE IS WHERE THE IMPORT NOTIFICATION POINTS. The upload answers 202
 * and the teacher closes the tab; the notification is their only route back, and
 * a notification whose link 404s is a report nobody ever reads.
 *
 * It polls while the import is still running, and stops the moment it is not —
 * an interval that keeps firing against a finished import is a request every few
 * seconds, for ever, on a page left open in a background tab.
 */
export default function ImportReportPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);

  const [report, setReport] = useState<ImportReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(
    () =>
      bank
        .importReport(uuid)
        .then((response) => {
          setReport(response.data);

          return response.data;
        })
        .catch(() => {
          setFailed(true);

          return null;
        })
        .finally(() => setLoading(false)),
    [uuid],
  );

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout>;

    const tick = () =>
      load().then((data) => {
        if (data !== null && (data.status === "queued" || data.status === "running")) {
          timer = setTimeout(tick, 3000);
        }
      });

    tick();

    return () => clearTimeout(timer);
  }, [load]);

  if (loading) return <RowsSkeleton />;
  if (failed || report === null) return <ErrorState onRetry={load} />;

  const running = report.status === "queued" || report.status === "running";

  const columns: Column<ImportReportRow>[] = [
    { key: "line", header: "السطر", numeric: true, render: (row) => row.line },
    { key: "reason", header: "السبب", render: (row) => row.reason },
    { key: "content", header: "نصّ الصفّ", render: (row) => row.content ?? "—" },
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">تقرير استيراد {report.filename}</h1>
          <p className="text-sm text-ink-muted">
            {report.duplicate_policy === "skip"
              ? "السياسة المختارة: تخطّي السؤال الموجود."
              : "السياسة المختارة: إضافة نسخةٍ جديدة."}
          </p>
        </div>
        <Badge
          tone={report.status === "done" ? "success" : report.status === "failed" ? "danger" : "info"}
        >
          {importStatusLabel(report.status)}
        </Badge>
      </header>

      {running && (
        <Alert tone="info" title="قيد المعالجة">
          يمكنك إغلاق الصفحة — سيصلك إشعارٌ عند اكتماله.
        </Alert>
      )}

      {/* A file that was not a CSV at all produces no rows to report, so the
          reason belongs to the whole import rather than to a line nobody can
          find in their spreadsheet. */}
      {report.failure_reason !== null && <Alert tone="danger" title="لم يكتمل الاستيراد">{report.failure_reason}</Alert>}

      <Card>
        <dl className="grid gap-4 sm:grid-cols-4">
          <div>
            <dt className="text-sm text-ink-muted">صفوف الملف</dt>
            <dd className="text-lg font-semibold text-ink">{report.total_rows}</dd>
          </div>
          <div>
            <dt className="text-sm text-ink-muted">أُضيف</dt>
            <dd className="text-lg font-semibold text-ink">{report.imported_count}</dd>
          </div>
          <div>
            <dt className="text-sm text-ink-muted">تُخطّي</dt>
            <dd className="text-lg font-semibold text-ink">{report.skipped_count}</dd>
          </div>
          <div>
            <dt className="text-sm text-ink-muted">تعذّر</dt>
            <dd className="text-lg font-semibold text-ink">{report.failed_count}</dd>
          </div>
        </dl>
      </Card>

      <section className="space-y-3">
        <h2 className="text-base font-semibold text-ink">الصفوف التي لم تُضَف</h2>
        <Table
          columns={columns}
          rows={report.rows}
          rowKey={(row) => String(row.line)}
          caption="الصفوف التي تُخطّيت أو تعذّر استيرادها، برقم السطر والسبب"
          state={report.rows.length === 0 ? "empty" : "ready"}
          emptyTitle={running ? "لم يكتمل بعد" : "كلّ الصفوف أُضيفت"}
          emptyDescription={
            running ? "سيظهر هنا كلّ صفٍّ لم يُضَف." : "لم يُتخطَّ صفٌّ ولم يتعذّر أيّ صفّ."
          }
        />
      </section>

      <Button href="/manage/bank" variant="secondary">
        عودة إلى البنك
      </Button>
    </div>
  );
}
