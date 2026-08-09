"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/ui/Badge";
import { Table, type Column } from "@/components/ui/Table";
import { billing, formatCredits, type StudentBalanceRow } from "@/lib/billing";

/**
 * Who has sessions left, and who has stopped.
 *
 * ⚠️ NO MONEY ON THIS SCREEN, and none arrives to put here. The API sends
 * credits alone: a session's price is the teacher's approved rate plus platform
 * constants, so a teacher reading two priced rows could solve for the platform's
 * margin — the mirror of the rule that keeps a teacher's rate off every public
 * page.
 *
 * ⚠️ ONE ROW PER (STUDENT × COURSE), never a total per student. +10 in maths and
 * −6 in physics adds to +4 and "fine", while withholding is decided per course
 * precisely so the paid-up course stays open. A total here would hide the one
 * course that has stopped.
 */
const columns: Column<StudentBalanceRow>[] = [
  { key: "student", header: "الطالب", render: (row) => row.student_name },
  { key: "course", header: "الكورس", render: (row) => row.course_title },
  {
    key: "remaining",
    header: "المتبقّي",
    numeric: true,
    render: (row) => formatCredits(row.remaining_credits),
  },
  {
    key: "consumed",
    header: "المستهلَك",
    numeric: true,
    render: (row) => formatCredits(row.consumed_credits),
  },
  {
    key: "state",
    header: "الحالة",
    // The label carries the meaning and the tone is emphasis only, so the row
    // still reads correctly to someone who cannot tell the two colours apart.
    render: (row) =>
      row.is_withheld ? (
        <Badge tone="danger">موقوف</Badge>
      ) : (
        <Badge tone="success">متاح</Badge>
      ),
  },
];

export default function StudentBalancesPage() {
  const [rows, setRows] = useState<StudentBalanceRow[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  const load = useCallback(() => {
    setState("loading");

    billing
      .students()
      .then((res) => {
        setRows(res.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">أرصدة الطلاب</h1>
        <p className="mt-1 text-sm text-ink-muted">
          الحصص المتبقّية لكل طالب في كل كورس، ومن توقّف حجزه منهم.
        </p>
      </header>

      <Table
        caption="أرصدة الطلاب في كل كورس"
        columns={columns}
        rows={rows}
        rowKey={(row) => `${row.student_uuid}:${row.course_uuid}`}
        state={state}
        emptyTitle="لا طلاب مسجّلين بعد"
        emptyDescription="يظهر هنا كل طالب لديه تسجيل نشط عندك، ولو لم يشترِ رصيداً بعد."
        onRetry={load}
      />

      <p className="text-xs text-ink-muted">
        الأرقام بالحصص لا بالمال. يتوقّف الحجز في الكورس الذي نفد رصيده وحده،
        ويعود فور اعتماد الدفع بلا أي إجراء منك.
      </p>
    </div>
  );
}
