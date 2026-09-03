"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { Table, type Column } from "@/components/ui/Table";
import {
  analytics,
  ratioLabel,
  type ConceptStat,
  type QuestionStat,
} from "@/lib/analytics";
import { formatDateTime } from "@/lib/labels";

/**
 * Where students go wrong — by question, and by idea.
 *
 * ⚠️ NOTHING HERE IS COMPUTED AT READ TIME. Every number arrives from the
 * nightly rollup, which is why the page states WHEN it was computed instead of
 * implying it is live. A teacher who thinks a rate includes this morning's exam
 * draws the wrong conclusion from a correct number.
 *
 * ⚠️ AND A MISSING RATE IS RENDERED AS A SENTENCE, NEVER AS ZERO. `ratioLabel`
 * is the only place the value becomes text for exactly that reason: a `?? 0`
 * anywhere on this page tells the teacher that nobody struggles with a question
 * two people have sat.
 */
export default function ItemAnalysisPage() {
  const [questions, setQuestions] = useState<QuestionStat[]>([]);
  const [concepts, setConcepts] = useState<ConceptStat[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([analytics.questions(), analytics.concepts()])
      .then(([questionsResponse, conceptsResponse]) => {
        setQuestions(questionsResponse.data ?? []);
        setConcepts(conceptsResponse.data ?? []);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const computedAt = questions[0]?.computed_at ?? concepts[0]?.computed_at ?? null;

  const questionColumns: Column<QuestionStat>[] = [
    {
      key: "question",
      header: "السؤال",
      render: (row) =>
        row.question === null ? (
          "—"
        ) : (
          <Link href={`/manage/bank/${row.question.uuid}`} className="text-ink underline-offset-4 hover:underline">
            {row.question.content.length > 90
              ? `${row.question.content.slice(0, 90)}…`
              : row.question.content}
          </Link>
        ),
    },
    {
      key: "concept",
      header: "الفكرة",
      render: (row) => row.question?.concept?.name ?? "—",
    },
    {
      key: "attempts",
      header: "عدد المحاولات",
      numeric: true,
      render: (row) => row.attempts_count,
    },
    {
      key: "wrong",
      header: "نسبة الخطأ",
      render: (row) =>
        row.has_enough_data ? (
          <Badge tone={(row.wrong_pct ?? 0) >= 60 ? "danger" : (row.wrong_pct ?? 0) >= 30 ? "warning" : "success"}>
            {ratioLabel(row)}
          </Badge>
        ) : (
          // Not a badge: a grey chip beside coloured ones reads as a fourth
          // grade of "how wrong", and this row is not on that scale at all.
          <span className="text-sm text-ink-muted">{ratioLabel(row)}</span>
        ),
    },
  ];

  const conceptColumns: Column<ConceptStat>[] = [
    {
      key: "concept",
      header: "الفكرة",
      render: (row) => row.concept?.name ?? "—",
    },
    {
      key: "lesson",
      header: "الدرس",
      // The overall row is about no single lesson, and saying so beats an
      // em-dash that reads as missing data.
      render: (row) => (row.is_overall ? "الفكرة إجمالاً" : (row.lesson?.title ?? "—")),
    },
    {
      key: "attempts",
      header: "عدد المحاولات",
      numeric: true,
      render: (row) => row.attempts_count,
    },
    {
      key: "wrong",
      header: "نسبة الخطأ",
      render: (row) => ratioLabel(row),
    },
  ];

  const state = loading ? "loading" : failed ? "error" : "ready";

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">تحليل الأسئلة</h1>
        <p className="text-sm text-ink-muted">
          أين يخطئ طلابك: أيّ الأسئلة أخطأ فيها الأغلبية، وأيّ الأفكار تحتاج إعادة شرح.
        </p>
      </header>

      <Card>
        <p className="text-sm text-ink-muted">
          {computedAt === null
            ? "لم يُحسب التحليل بعد. يُحدَّث كلّ ليلة."
            : `آخر حساب: ${formatDateTime(computedAt)} — يُحدَّث كلّ ليلة، فلا يشمل محاولات اليوم.`}
        </p>
        <p className="mt-2 text-sm text-ink-muted">
          السؤال الذي حلّه عددٌ قليل من الطلاب لا تُعرض له نسبة: «بيانات غير كافية» ليست
          صفراً، والفرق بينهما هو الفرق بين سؤالٍ سليم وسؤالٍ تحذفه بلا سبب.
        </p>
      </Card>

      <Table
        columns={questionColumns}
        rows={questions}
        rowKey={(row, index) => row.question?.uuid ?? String(index)}
        caption="أسئلة البنك مرتّبةً بنسبة الخطأ، الأعلى أوّلاً"
        state={state === "ready" && questions.length === 0 ? "empty" : state}
        emptyTitle="لا محاولات بعد"
        emptyDescription="يظهر التحليل بعد أن يجلس طلابك لاختبار من أسئلة البنك."
        onRetry={load}
      />

      <Table
        columns={conceptColumns}
        rows={concepts}
        rowKey={(row, index) => `${row.concept?.uuid ?? "?"}-${row.is_overall ? "all" : (row.lesson?.uuid ?? index)}`}
        caption="نسبة الخطأ لكلّ فكرة، إجمالاً وداخل كلّ درس"
        state={state === "ready" && concepts.length === 0 ? "empty" : state}
        emptyTitle="لا أفكار بعد"
        emptyDescription="وسم الأسئلة بالفكرة هو ما يجعل هذا الجدول ممكناً."
        onRetry={load}
      />
    </div>
  );
}
