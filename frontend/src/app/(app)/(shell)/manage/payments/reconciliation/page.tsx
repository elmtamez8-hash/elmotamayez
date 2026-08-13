"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";
import { Alert } from "@/components/ui/Alert";
import { Card } from "@/components/ui/Card";
import { Table, type Column } from "@/components/ui/Table";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";

type Finding = {
  type: string;
  provider?: string;
  reference?: string;
  order_uuid?: string;
  local_status?: string;
  provider_status?: string;
};

type Run = {
  uuid: string;
  ran_at: string;
  window_from: string;
  window_to: string;
  checked_count: number;
  corrected_count: number;
  unresolved_count: number;
  findings: Finding[];
};

/**
 * Arabic for what the sweep could not settle by itself.
 *
 * A `type` on the wire and a sentence here, rather than a sentence on the wire:
 * the API is read by more than this page, and a message written in the sweep is
 * a message that cannot be corrected without a deploy.
 */
const FINDING_LABELS: Record<string, string> = {
  unknown_reference: "المزوّد يذكر عملية لا سجلّ لها عندنا",
  provider_disagrees: "المزوّد يخالفنا في نتيجة عملية محسومة",
  captured_without_credits: "دفعة استُلمت ولم تُضَف أرصدتها",
};

export default function PaymentReconciliationPage() {
  const [run, setRun] = useState<Run | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setError("");

    api
      .get<{ data: Run | null }>("/admin/payments/reconciliation")
      .then((res) => setRun(res.data))
      // Never raw: a 403 here is a person who may not read the platform's
      // collection, and a stack of JSON tells them nothing they can act on.
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const columns: Column<Finding>[] = [
    {
      key: "type",
      header: "الحالة",
      render: (f) => FINDING_LABELS[f.type] ?? f.type,
    },
    {
      key: "subject",
      header: "المرجع",
      render: (f) => (
        // The provider's reference or our order uuid — never an amount and never
        // a payer's name. SC-012 forbids a payment detail in any payload of this
        // phase, and this screen is the one most tempted to dump the body onto.
        <span className="font-mono text-xs" dir="ltr">
          {f.reference ?? f.order_uuid ?? "—"}
        </span>
      ),
    },
    {
      key: "disagreement",
      header: "التفصيل",
      render: (f) =>
        f.local_status !== undefined
          ? `عندنا ${f.local_status} · عند المزوّد ${f.provider_status}`
          : (f.provider ?? "—"),
    },
  ];

  if (loading) {
    return (
      <div className="space-y-6">
        <h2 className="text-2xl font-bold text-ink">تسوية المدفوعات</h2>
        <RowsSkeleton />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">تسوية المدفوعات</h2>
        <p className="mt-1 text-sm text-ink-muted">
          ما التقطته المسحة الدورية من دفعات نجحت بلا إشعار، وما تعذّر حسمه آلياً.
        </p>
      </div>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر عرض التقرير">
          {error}
        </Alert>
      )}

      {error === "" && run === null && (
        /* ⚠️ NOT "no findings". A platform where the sweep has never run and one
           where it ran and found nothing are the same empty screen, and only one
           of them is reassuring. */
        <EmptyState
          title="لم تُشغَّل المسحة بعد"
          description="تعمل المسحة كل ساعة. لا يعني غياب التقرير أن كل شيء سليم — يعني أنه لم يُفحَص بعد."
        />
      )}

      {run !== null && (
        <>
          <Card>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <dt className="text-sm text-ink-muted">آخر تشغيل</dt>
                <dd className="mt-1 font-semibold text-ink">{formatDateTime(run.ran_at)}</dd>
              </div>
              <div>
                <dt className="text-sm text-ink-muted">عمليات فُحصت</dt>
                <dd className="mt-1 text-2xl font-bold text-ink">{run.checked_count}</dd>
              </div>
              <div>
                <dt className="text-sm text-ink-muted">صُحّحت آلياً</dt>
                <dd className="mt-1 text-2xl font-bold text-secondary-ink">{run.corrected_count}</dd>
              </div>
              <div>
                <dt className="text-sm text-ink-muted">تحتاج قراراً</dt>
                <dd
                  className={`mt-1 text-2xl font-bold ${
                    run.unresolved_count > 0 ? "text-danger-ink" : "text-ink"
                  }`}
                >
                  {run.unresolved_count}
                </dd>
              </div>
            </dl>

            <p className="mt-4 border-t border-line pt-4 text-xs text-ink-muted">
              النافذة المفحوصة: {formatDateTime(run.window_from)} ← {formatDateTime(run.window_to)}
            </p>
          </Card>

          {run.unresolved_count > run.findings.length && (
            <Alert tone="warning" title="القائمة عيّنة وليست كل شيء">
              عدد ما يحتاج قراراً {run.unresolved_count}، والمعروض أدناه {run.findings.length}.
            </Alert>
          )}

          <Table
            columns={columns}
            rows={run.findings}
            // The whole row, because a finding has no id: two of the same type
            // with no reference are two real rows, and a key built from the
            // fields present would collapse them into one.
            rowKey={(f) => JSON.stringify(f)}
            caption="ما تعذّر على المسحة حسمه"
            state="ready"
            emptyTitle="لا شيء معلّق"
            emptyDescription="حسمت المسحة كل ما وجدته في هذه النافذة."
          />
        </>
      )}
    </div>
  );
}
