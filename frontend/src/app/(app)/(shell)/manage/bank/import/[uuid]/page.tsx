"use client";

import { use, useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatTile } from "@/components/ui/StatTile";
import {
  AlertIcon,
  CheckIcon,
  InfoIcon,
  ListIcon,
  QuestionBankIcon,
} from "@/components/icons";
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
      <PageHeader
        Icon={QuestionBankIcon}
        title={`تقرير استيراد ${report.filename}`}
        description={
          report.duplicate_policy === "skip"
            ? "السياسة المختارة: تخطّي السؤال الموجود."
            : "السياسة المختارة: إضافة نسخةٍ جديدة."
        }
        actions={
          <Badge
            tone={report.status === "done" ? "success" : report.status === "failed" ? "danger" : "info"}
          >
            {importStatusLabel(report.status)}
          </Badge>
        }
      />

      {running && (
        <Alert tone="info" title="قيد المعالجة">
          يمكنك إغلاق الصفحة — سيصلك إشعارٌ عند اكتماله.
        </Alert>
      )}

      {/* A file that was not a CSV at all produces no rows to report, so the
          reason belongs to the whole import rather than to a line nobody can
          find in their spreadsheet. */}
      {report.failure_reason !== null && <Alert tone="danger" title="لم يكتمل الاستيراد">{report.failure_reason}</Alert>}

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile Icon={ListIcon} label="صفوف الملف" value={String(report.total_rows)} />
        <StatTile Icon={CheckIcon} label="أُضيف" value={String(report.imported_count)} emphasis />
        <StatTile Icon={InfoIcon} label="تُخطّي" value={String(report.skipped_count)} />
        <StatTile Icon={AlertIcon} label="تعذّر" value={String(report.failed_count)} />
      </div>

      <section aria-labelledby="failed-rows" className="space-y-3">
        <SectionHeading id="failed-rows" Icon={AlertIcon} title="الصفوف التي لم تُضَف" />
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
