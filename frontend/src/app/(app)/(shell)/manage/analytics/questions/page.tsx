"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { ConceptIcon, ItemAnalysisIcon, QuestionIcon, ScheduleIcon } from "@/components/icons";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { FilterBar } from "@/components/ui/FilterBar";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatStrip } from "@/components/ui/StatStrip";
import { StatTile } from "@/components/ui/StatTile";
import { Table, type Column } from "@/components/ui/Table";
import {
  analytics,
  ratioLabel,
  type ConceptStat,
  type QuestionStat,
} from "@/lib/analytics";
import { counted, formatDate } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";
import { matchesSearch } from "@/lib/search-text";

/** «٣ نتائج» — the rows of the concept table that the search leaves on screen. */
const RESULTS = { one: "نتيجة واحدة", two: "نتيجتان", few: "نتائج", many: "نتيجة", other: "نتيجة" };

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
 *
 * ⚠️ THE SEARCH NARROWS THE IDEAS, NEVER THE QUESTIONS. The question list is the
 * server's FIRST PAGE (worst first) and the endpoint takes no query, so a box
 * over it would answer «no match» about a question sitting on page two. The idea
 * list arrives whole, so filtering it in the browser tells the truth.
 */
export default function ItemAnalysisPage() {
  const [questions, setQuestions] = useState<QuestionStat[]>([]);
  const [questionTotal, setQuestionTotal] = useState(0);
  const [concepts, setConcepts] = useState<ConceptStat[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [query, setQuery] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([analytics.questions(), analytics.concepts()])
      .then(([questionsResponse, conceptsResponse]) => {
        const rows = questionsResponse.data ?? [];
        setQuestions(rows);
        setQuestionTotal(questionsResponse.meta?.total ?? rows.length);
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
          <Link
            href={`/manage/bank/${row.question.uuid}`}
            className="rounded-sm text-ink underline-offset-4 transition-colors hover:text-primary-ink hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
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
      render: (row) => arabicNumber(row.attempts_count),
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
      render: (row) => arabicNumber(row.attempts_count),
    },
    {
      key: "wrong",
      header: "نسبة الخطأ",
      render: (row) => ratioLabel(row),
    },
  ];

  const state = loading ? "loading" : failed ? "error" : "ready";
  const shownConcepts = concepts.filter((row) =>
    matchesSearch(query, row.concept?.name, row.is_overall ? null : row.lesson?.title),
  );

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={ItemAnalysisIcon}
        title="تحليل الأسئلة"
        description="أين يخطئ طلابك: أيّ الأسئلة أخطأ فيها الأغلبية، وأيّ الأفكار تحتاج إعادة شرح."
      />

      {state === "ready" && (
        <StatStrip label="ملخّص التحليل" columns={2}>
          <StatTile
            label="أسئلة لها تحليل"
            value={questionTotal}
            Icon={QuestionIcon}
            hint={
              questionTotal > questions.length
                ? `يُعرض أدناه أعلاها خطأً: ${arabicNumber(questions.length)}.`
                : undefined
            }
          />
          <StatTile
            label="آخر حساب"
            value={computedAt === null ? "لم يُحسب بعد" : formatDate(computedAt)}
            Icon={ScheduleIcon}
            hint={
              computedAt === null
                ? "يُحدَّث كلّ ليلة."
                : "يُحدَّث كلّ ليلة، فلا يشمل محاولات اليوم."
            }
          />
        </StatStrip>
      )}

      <section aria-labelledby="question-stats" className="space-y-4">
        <SectionHeading
          id="question-stats"
          Icon={QuestionIcon}
          title="الأسئلة، الأعلى خطأً أوّلاً"
          description="السؤال الذي حلّه عددٌ قليل من الطلاب لا تُعرض له نسبة: «بيانات غير كافية» ليست صفراً، والفرق بينهما هو الفرق بين سؤالٍ سليم وسؤالٍ تحذفه بلا سبب."
        />
        <Table
          columns={questionColumns}
          rows={questions}
          rowKey={(row, index) => row.question?.uuid ?? String(index)}
          caption="أسئلة البنك مرتّبةً بنسبة الخطأ، الأعلى أوّلاً"
          state={state === "ready" && questions.length === 0 ? "empty" : state}
          emptyIcon={ItemAnalysisIcon}
          emptyTitle="لا محاولات بعد"
          emptyDescription="يظهر التحليل بعد أن يجلس طلابك لاختبار من أسئلة البنك."
          onRetry={load}
        />
      </section>

      <section aria-labelledby="concept-stats" className="space-y-4">
        <SectionHeading
          id="concept-stats"
          Icon={ConceptIcon}
          title="الأفكار"
          description="نسبة الخطأ لكلّ فكرة، إجمالاً وداخل كلّ درس."
        />

        {state === "ready" && concepts.length > 0 && (
          <FilterBar
            search={{
              id: "concept-search",
              label: "ابحث في الأفكار",
              value: query,
              onChange: setQuery,
              placeholder: "اسم الفكرة أو الدرس",
            }}
            summary={counted(shownConcepts.length, RESULTS)}
          />
        )}

        <Table
          columns={conceptColumns}
          rows={shownConcepts}
          rowKey={(row, index) => `${row.concept?.uuid ?? "?"}-${row.is_overall ? "all" : (row.lesson?.uuid ?? index)}`}
          caption="نسبة الخطأ لكلّ فكرة، إجمالاً وداخل كلّ درس"
          state={state === "ready" && shownConcepts.length === 0 ? "empty" : state}
          {...(concepts.length === 0
            ? {
                emptyIcon: ConceptIcon,
                emptyTitle: "لا أفكار بعد",
                emptyDescription: "وسم الأسئلة بالفكرة هو ما يجعل هذا الجدول ممكناً.",
              }
            : {
                emptyTitle: "لا فكرة تطابق البحث",
                emptyDescription: "جرّب كلمةً أخرى من اسم الفكرة أو الدرس.",
                emptyAction: (
                  <Button variant="secondary" size="sm" onClick={() => setQuery("")}>
                    مسح البحث
                  </Button>
                ),
              })}
          onRetry={load}
        />
      </section>
    </div>
  );
}
