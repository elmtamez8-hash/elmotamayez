"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { counted, NOUNS } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";
import type { Exam } from "@/lib/types";
import { Button } from "@/components/ui/Button";
import { CARD_INTERACTIVE } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { ExamIcon } from "@/components/icons";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { htmlToText } from "@/lib/html-text";

/**
 * The papers a student may SIT. The teacher's list is `/manage/exams`.
 *
 * ⚠️ ONE READER, AND THE PERMISSION BRANCHES ARE GONE ON PURPOSE. This screen
 * used to serve both audiences off one `GET /exams`, hiding the author's two
 * buttons behind `can()` — which left the heading, the empty state and the whole
 * reading of the page addressed to whichever reader was written first. A
 * sentence cannot be gated: «لم ينشر مدرّسك اختباراً بعد» is wrong for a teacher
 * however carefully the «اختبار جديد» button beside it is hidden.
 *
 * ⚠️ AND IT STILL SHOWS ONLY PUBLISHED PAPERS BECAUSE THE SERVER SAYS SO, not
 * because this file filters. `ExamController::index()` narrows to the student's
 * own enrolled slice for a reader without `exams.view`; a status check written
 * here as well would be a second spelling of the same question, and the one that
 * is not the door.
 */
export default function ExamsPage() {
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

  return (
    <div className="space-y-6">
      <PageHeader Icon={ExamIcon} title="الاختبارات" />

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : exams.length === 0 ? (
        <EmptyState
          title="لا اختبارات متاحة الآن"
          description="لم ينشر مدرّسك اختباراً بعد. ستجده هنا حين ينشره."
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {exams.map((exam) => (
            // The bold band (owner request 2026-10-11), the group card's language.
            <article
              key={exam.uuid}
              className={`flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm ${CARD_INTERACTIVE}`}
            >
              <header className="bg-squares relative isolate overflow-hidden bg-primary px-5 py-5 text-white">
                <ExamIcon
                  aria-hidden="true"
                  className="pointer-events-none absolute -bottom-6 -end-4 -z-10 h-24 w-24 opacity-15"
                />
                <h3 className="line-clamp-2 text-lg font-extrabold leading-snug">{exam.title}</h3>
              </header>

              <div className="flex flex-1 flex-col p-5">
                {exam.description_html && (
                  <p className="mb-4 line-clamp-2 text-sm text-ink-muted">{htmlToText(exam.description_html)}</p>
                )}

                {/*
                  Each count goes through `counted()`: «2 سؤالاً» and «3 محاولات»
                  beside «30 دقيقة» shipped on this card, each wrong in its own
                  band (2026-09-24).
                */}
                <ul className="mb-5 grid grid-cols-2 gap-2 text-sm font-bold text-primary-ink">
                  <li className="rounded-2xl bg-primary-soft px-3 py-2">{counted(exam.duration_minutes, NOUNS.minutes)}</li>
                  <li className="rounded-2xl bg-primary-soft px-3 py-2">
                    النجاح&nbsp;<bdi>{arabicNumber(exam.passing_score)}%</bdi>
                  </li>
                  <li className="rounded-2xl bg-primary-soft px-3 py-2">{counted(exam.max_attempts, NOUNS.attempts)}</li>
                  {exam.questions_count !== undefined && (
                    <li className="rounded-2xl bg-primary-soft px-3 py-2">
                      {counted(exam.questions_count, NOUNS.questions)}
                    </li>
                  )}
                </ul>

                <div className="mt-auto">
                  <Button href={`/exams/${exam.uuid}/take`} fullWidth>
                    ابدأ الاختبار
                  </Button>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
