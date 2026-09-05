"use client";

import { useCallback, useEffect, useState } from "react";
import {
  billing,
  type CreditReconciliationFinding,
  type CreditReconciliationRun,
} from "@/lib/billing";
import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";
import { Alert } from "@/components/ui/Alert";
import { Card } from "@/components/ui/Card";
import { Table, type Column } from "@/components/ui/Table";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";

/**
 * Arabic for what the ledger could not make add up.
 *
 * A `check` on the wire and a sentence here, for the reason its payments twin
 * gives: the API is read by more than this page, and a message written inside a
 * nightly job cannot be corrected without a deploy.
 */
const CHECK_LABELS: Record<string, string> = {
  ledger_sum: "رصيد لا يساوي مجموع قيوده",
  lot_remainder: "رصيد موجب لا يساوي ما تحمله دفعاته",
  session_seats: "حصّة حوسبت بعدد قيود لا يطابق مقاعدها",
};

/**
 * Past this, the report is not a report — it is a sweep that stopped.
 *
 * The job is scheduled nightly at 04:45, so thirty-six hours means at least one
 * night was missed: long enough that a late queue or a slow night never trips
 * it, short enough that a stopped worker is on screen the following evening.
 *
 * ⚠️ THIS IS THE HALF THE READER WOULD OTHERWISE NOT HAVE. «No findings» and
 * «nothing has run since Tuesday» are the same empty table, and the second is
 * the one that matters — a reconciliation nobody notices has stopped is a
 * reconciliation that is not happening.
 */
const STALE_AFTER_HOURS = 36;

function hoursSince(iso: string): number {
  return (Date.now() - new Date(iso).getTime()) / 3_600_000;
}

export default function CreditReconciliationPage() {
  const [run, setRun] = useState<CreditReconciliationRun | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setError("");

    billing
      .creditReconciliation()
      .then((res) => setRun(res.data))
      // Never raw: a 403 here is a person who may not read the platform's
      // ledger, and a stack of JSON tells them nothing they can act on.
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const columns: Column<CreditReconciliationFinding>[] = [
    {
      key: "check",
      header: "الاختلال",
      render: (f) => CHECK_LABELS[f.check] ?? f.check,
    },
    {
      key: "subject",
      header: "المرجع",
      render: (f) => (
        // Internal row ids, never a name and never a price. A credit is a COUNT
        // of sessions; the money behind it is solvable for a teacher's rate, and
        // no screen in this product carries it.
        <span className="font-mono text-xs" dir="ltr">
          {f.credit_balance_id !== undefined
            ? `balance #${f.credit_balance_id}`
            : f.class_session_id !== undefined
              ? `session #${f.class_session_id}`
              : "—"}
        </span>
      ),
    },
    {
      key: "gap",
      header: "الفارق",
      render: (f) => `المتوقّع ${f.expected} · المسجَّل ${f.actual}`,
    },
    {
      key: "teacher",
      /*
       * ⚠️ «المدرّس» AND NOT THE WORD THE COLUMN CARRIES. `workspaces` is the
       * storage layer and spec 025 abolished it as a user-facing idea entirely —
       * one row is one teacher, and `workspace-vocabulary.test.ts` fails the build
       * over the root of that word appearing outside a comment. It caught this
       * header the first time this page ran.
       */
      header: "المدرّس",
      render: (f) => (
        <span className="font-mono text-xs" dir="ltr">
          #{f.workspace_id}
        </span>
      ),
    },
  ];

  if (loading) {
    return (
      <div className="space-y-6">
        <h2 className="text-2xl font-bold text-ink">مطابقة الأرصدة</h2>
        <RowsSkeleton />
      </div>
    );
  }

  const stale = run !== null && hoursSince(run.ran_at) > STALE_AFTER_HOURS;

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">مطابقة الأرصدة</h2>
        <p className="mt-1 text-sm text-ink-muted">
          هل ما يزال دفتر الأرصدة متّسقاً؟ ثلاثة أسئلة تُطرَح كلّ ليلة، واثنان منها يريان ما لا
          يراه الدفتر عن نفسه — حصّة سُلّمت ولم يُحاسَب عليها أحد.
        </p>
      </div>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر عرض التقرير">
          {error}
        </Alert>
      )}

      {error === "" && run === null && (
        /* ⚠️ NOT "nothing found". A platform where the sweep has never run and
           one where it ran and found nothing are the same empty screen, and only
           one of them is reassuring. */
        <EmptyState
          title="لم تُشغَّل المطابقة بعد"
          description="تعمل كلّ ليلة. غياب التقرير لا يعني أنّ الدفتر سليم — يعني أنّه لم يُفحَص بعد."
        />
      )}

      {run !== null && (
        <>
          {stale && (
            <Alert tone="danger" title="المطابقة متوقّفة">
              آخر تشغيل كان {formatDateTime(run.ran_at)}. تعمل المطابقة كلّ ليلة، فمرور أكثر
              من يوم يعني أنّ المهمّة لم تُنفَّذ — والأرقام أدناه قديمة مهما بدت مطمئنة.
            </Alert>
          )}

          <Card>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <dt className="text-sm text-ink-muted">آخر تشغيل</dt>
                <dd
                  className={`mt-1 font-semibold ${stale ? "text-danger-ink" : "text-ink"}`}
                >
                  {formatDateTime(run.ran_at)}
                </dd>
              </div>
              <div>
                <dt className="text-sm text-ink-muted">أرصدة فُحصت</dt>
                <dd className="mt-1 text-2xl font-bold text-ink">{run.balances_checked}</dd>
              </div>
              <div>
                <dt className="text-sm text-ink-muted">حصص محاسَبة فُحصت</dt>
                <dd className="mt-1 text-2xl font-bold text-ink">{run.sessions_checked}</dd>
              </div>
              <div>
                <dt className="text-sm text-ink-muted">اختلالات</dt>
                <dd
                  className={`mt-1 text-2xl font-bold ${
                    run.findings_count > 0 ? "text-danger-ink" : "text-ink"
                  }`}
                >
                  {run.findings_count}
                </dd>
              </div>
            </dl>

            <p className="mt-4 border-t border-line pt-4 text-xs text-ink-muted">
              المطابقة لا تُصلح شيئاً بنفسها: تصحيحٌ صامت يمحو الدليل على ما حدث. التصحيح قيدٌ
              يكتبه إنسان بسبب مكتوب.
            </p>
          </Card>

          {run.findings_count > run.findings.length && (
            <Alert tone="warning" title="القائمة عيّنة وليست كلّ شيء">
              عدد الاختلالات {run.findings_count}، والمعروض أدناه {run.findings.length}.
            </Alert>
          )}

          <Table
            columns={columns}
            rows={run.findings}
            // The whole row, because a finding has no id of its own: two of the
            // same check on two different subjects are two real rows, and a key
            // built from the fields that happen to be present collapses them.
            rowKey={(f) => JSON.stringify(f)}
            caption="ما لم يتّسق في آخر مطابقة"
            state="ready"
            emptyTitle="الدفتر متّسق"
            emptyDescription="لم تجد المطابقة الأخيرة أيّ اختلال."
          />
        </>
      )}
    </div>
  );
}
