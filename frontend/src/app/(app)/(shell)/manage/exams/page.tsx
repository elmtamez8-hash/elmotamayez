"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { api } from "@/lib/api";
import type { Exam } from "@/lib/types";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { ExamIcon } from "@/components/icons";
import { Table, type Column } from "@/components/ui/Table";
import { useAuth } from "@/lib/auth-context";
import { P, can } from "@/lib/permissions";
import { counted, NOUNS } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";

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
  /*
    `?course=<uuid>` — «اختبارات الكورس» on a course's page lands here with it.
    `undefined` until the address is read, so the first request already carries
    the filter instead of fetching the whole list and then the narrow one.

    ⚠️ READ FROM `location` IN AN EFFECT, NOT WITH `useSearchParams` — that hook
    fails the production build outside a `<Suspense>` (see the course content
    page, which carries `?lesson=` the same way).

    The server filters (`ExamController::index` matches the uuid through the
    relation), so an unknown uuid is an empty list, never the unfiltered one.
  */
  const [course, setCourse] = useState<string | null | undefined>(undefined);

  useEffect(() => {
    const value = new URLSearchParams(window.location.search).get("course");

    setCourse(value !== null && value !== "" ? value : null);
  }, []);

  const load = useCallback(() => {
    if (course === undefined) return;

    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Exam[] }>(course === null ? "/exams" : `/exams?course=${encodeURIComponent(course)}`)
      .then((res) => setExams(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [course]);

  useEffect(load, [load]);

  const showAll = () => {
    window.history.replaceState(null, "", "/manage/exams");
    setCourse(null);
  };

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
      render: (row) => counted(row.duration_minutes, NOUNS.minutes),
    },
    {
      key: "passing",
      header: "درجة النجاح",
      numeric: true,
      render: (row) => <bdi>{arabicNumber(row.passing_score)}٪</bdi>,
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
      <PageHeader
        Icon={ExamIcon}
        title="إدارة الاختبارات"
        description="المسودّات هنا لا يراها أحدٌ غيرك. ينشرها زرّ «إدارة» داخل كلّ اختبار."
        actions={canCreate ? <Button href="/exams/new">اختبار جديد</Button> : undefined}
      />

      {typeof course === "string" && (
        <div className="flex flex-wrap items-center gap-3">
          <Badge tone="info">اختبارات كورس واحد فقط</Badge>
          <Button size="sm" variant="ghost" onClick={showAll}>
            اعرض كل الاختبارات
          </Button>
        </div>
      )}

      <Table
        columns={columns}
        rows={exams}
        rowKey={(row) => row.uuid}
        caption="اختباراتك بحالتها وعدد أسئلتها"
        state={loading ? "loading" : failed ? "error" : exams.length === 0 ? "empty" : "ready"}
        emptyTitle={typeof course === "string" ? "لا اختبارات في هذا الكورس بعد" : "لا اختبارات بعد"}
        emptyDescription="أنشئ اختباراً لتقيس فهم طلابك لما شرحته."
        emptyAction={canCreate ? <Button href="/exams/new">اختبار جديد</Button> : undefined}
        onRetry={load}
      />
    </div>
  );
}
