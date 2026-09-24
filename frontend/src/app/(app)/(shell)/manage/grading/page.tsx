"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { GradingIcon } from "@/components/icons";
import { Table, type Column } from "@/components/ui/Table";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { grading, type GradingQueueRow } from "@/lib/grading";
import { formatDateTime } from "@/lib/labels";
import { can, P } from "@/lib/permissions";

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
  const { user } = useAuth();
  const [rows, setRows] = useState<GradingQueueRow[]>([]);
  const [total, setTotal] = useState(0);
  const [state, setState] = useState<"ready" | "loading" | "empty" | "error">("loading");
  // Read from the queue's meta, never off `rows[0]`: an empty queue has no row.
  const [anonymous, setAnonymous] = useState<boolean | null>(null);

  const load = useCallback(() => {
    setState("loading");

    grading
      .queue()
      .then((response) => {
        const data = response.data ?? [];
        setRows(data);
        setTotal(response.meta?.total ?? 0);
        setAnonymous(response.meta?.anonymous ?? null);
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
        <Link href={`/manage/grading/${row.uuid}`} className="text-primary-ink hover:underline">
          صحّح
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={GradingIcon}
        title="لوحة التصحيح"
        description="أوراقٌ فيها أسئلة مقالية سُلّمت وتنتظر قراءتك. الأقدم أولاً، ولا تصل النتيجة الطالبَ قبل أن تنتهي منها."
      />

      {anonymous !== null && (
        <AnonymityControl
          anonymous={anonymous}
          canChange={can(user, P.settingsUpdate)}
          onChanged={(now) => {
            setAnonymous(now);
            load();
          }}
        />
      )}

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

/**
 * Names on or off for the whole workspace (FR-033).
 *
 * ⚠️ THE OWNER'S SWITCH, NOT EVERY GRADER'S. The route asks `settings.update`,
 * which a plain teacher does not hold — so they read the state and are told who
 * can change it, rather than meeting a 403 on a button.
 *
 * ⚠️ AND TURNING IT OFF ASKS FIRST. Anonymity is a promise made to the students;
 * withdrawing it is audited server-side, and one stray press should not be.
 */
function AnonymityControl({
  anonymous,
  canChange,
  onChanged,
}: {
  anonymous: boolean;
  canChange: boolean;
  onChanged: (anonymous: boolean) => void;
}) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const send = async (next: boolean) => {
    setBusy(true);
    setError("");

    try {
      const response = await grading.setAnonymous(next);
      onChanged(response.data.anonymous);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
      setAsking(false);
    }
  };

  return (
    <Card as="section">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-ink">التصحيح بلا أسماء</h2>
          <p className="mt-1 text-sm text-ink-muted">
            {anonymous
              ? "مفعَّل: تُخفى أسماء الطلاب عن كلّ من يصحّح أوراق طلابك."
              : "غير مفعَّل: يظهر اسم الطالب على ورقته أثناء التصحيح."}
            {!canChange && " يغيّره المدرّس صاحب الحساب."}
          </p>
        </div>
        {canChange && (
          <Button
            variant={anonymous ? "ghost" : "secondary"}
            size="sm"
            loading={busy}
            loadingLabel="جارٍ الحفظ…"
            onClick={() => (anonymous ? setAsking(true) : void send(true))}
          >
            {anonymous ? "أظهِر الأسماء" : "أخفِ الأسماء"}
          </Button>
        )}
      </div>

      {error !== "" && (
        <div className="mt-3">
          <Alert tone="danger" title={error} />
        </div>
      )}

      <Modal
        open={asking}
        title="إظهار أسماء الطلاب"
        message="ستظهر الأسماء على كلّ الأوراق لكلّ من يصحّح أوراق طلابك، ويُسجَّل هذا التغيير باسمك."
        confirmLabel="أظهِر الأسماء"
        busy={busy}
        onConfirm={() => void send(false)}
        onCancel={() => setAsking(false)}
      />
    </Card>
  );
}
