"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { api } from "@/lib/api";
import type { Exam } from "@/lib/types";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Table, type Column } from "@/components/ui/Table";
import { useAuth } from "@/lib/auth-context";
import { P, can } from "@/lib/permissions";

/**
 * The papers a teacher WROTE. The student's list is `/exams`.
 *
 * ⚠️ SAME ENDPOINT, DIFFERENT ANSWER — which is the whole reason this file
 * exists. `ExamController::index()` reads `exams.view` and hands the author an
 * unfiltered list WITH ITS DRAFTS, and everybody else their own enrolled slice.
 * One rendering over two answers is how a draft ended up under «ابدأ الاختبار»
 * and a student was offered «اختبار جديد».
 *
 * ⚠️ A TABLE, NOT THE STUDENT'S CARDS. The student picks a paper to sit, so a
 * card carrying duration and pass mark is the right shape; the teacher scans
 * for «which of these is still a draft» across twenty rows, which a grid of
 * cards makes into a hunt.
 *
 * ⚠️ AND IT CARRIES THE ONLY DOORS TO `/exams/new` AND `/exams/{uuid}/manage`.
 * Those two pages were reached from the student screen before the split; a
 * surface nothing links to is a surface nobody has. They keep their addresses
 * deliberately — moving them under `/manage` is a route migration nobody asked
 * for, and two files resolving to one path 500s the WHOLE application.
 */
export default function ManageExamsPage() {
  const { user } = useAuth();
  const [exams, setExams] = useState<Exam[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Exam[] }>("/exams")
      .then((res) => setExams(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const canCreate = can(user, P.examsCreate);

  const columns: Column<Exam>[] = [
    {
      key: "title",
      header: "الاختبار",
      render: (row) => (
        <Link
          href={`/exams/${row.uuid}/manage`}
          className="text-ink underline-offset-4 hover:underline"
        >
          {row.title}
        </Link>
      ),
    },
    {
      key: "state",
      header: "الحالة",
      // The column a teacher actually came for: a draft is invisible to every
      // student, and the commonest question about a paper is whether it is.
      render: (row) =>
        row.is_published ? (
          <Badge tone="success">منشور</Badge>
        ) : (
          <Badge tone="warning">مسودّة</Badge>
        ),
    },
    {
      key: "questions",
      header: "الأسئلة",
      numeric: true,
      render: (row) => row.questions_count ?? 0,
    },
    {
      key: "duration",
      header: "المدّة",
      numeric: true,
      render: (row) => <bdi>{row.duration_minutes} دقيقة</bdi>,
    },
    {
      key: "passing",
      header: "درجة النجاح",
      numeric: true,
      render: (row) => <bdi>{row.passing_score}%</bdi>,
    },
    {
      key: "open",
      header: "الإجراء",
      render: (row) => (
        <Link
          href={`/exams/${row.uuid}/manage`}
          className="text-primary-ink underline-offset-4 hover:underline"
        >
          إدارة
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">إدارة الاختبارات</h1>
          <p className="text-sm text-ink-muted">
            المسودّات هنا لا يراها أحدٌ غيرك. ينشرها زرّ «إدارة» داخل كلّ اختبار.
          </p>
        </div>
        {canCreate && <Button href="/exams/new">اختبار جديد</Button>}
      </header>

      <Table
        columns={columns}
        rows={exams}
        rowKey={(row) => row.uuid}
        caption="اختبارات مساحتك بحالتها وعدد أسئلتها"
        state={loading ? "loading" : failed ? "error" : exams.length === 0 ? "empty" : "ready"}
        emptyTitle="لا اختبارات بعد"
        emptyDescription="أنشئ اختباراً لتقيس فهم طلابك لما شرحته."
        emptyAction={canCreate ? <Button href="/exams/new">اختبار جديد</Button> : undefined}
        onRetry={load}
      />
    </div>
  );
}
