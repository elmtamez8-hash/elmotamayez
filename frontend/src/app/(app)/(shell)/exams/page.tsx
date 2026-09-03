"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Exam } from "@/lib/types";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

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
      <h2 className="text-2xl font-bold text-ink">الاختبارات</h2>

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
            <Card key={exam.uuid} as="article" padding="sm">
              <h3 className="mb-2 font-semibold text-ink">{exam.title}</h3>
              <p className="mb-4 line-clamp-2 text-sm text-ink-muted">{exam.description}</p>

              <div className="mb-4 flex flex-wrap gap-2">
                <Badge>
                  <bdi>{exam.duration_minutes}</bdi>&nbsp;دقيقة
                </Badge>
                <Badge>
                  النجاح&nbsp;<bdi>{exam.passing_score}%</bdi>
                </Badge>
                <Badge>
                  <bdi>{exam.max_attempts}</bdi>&nbsp;محاولات
                </Badge>
                {exam.questions_count !== undefined && (
                  <Badge>
                    <bdi>{exam.questions_count}</bdi>&nbsp;سؤالاً
                  </Badge>
                )}
              </div>

              <Button href={`/exams/${exam.uuid}/take`} fullWidth>
                ابدأ الاختبار
              </Button>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
