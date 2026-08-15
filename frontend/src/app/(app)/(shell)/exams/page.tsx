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
import { useAuth } from "@/lib/auth-context";
import { P, can } from "@/lib/permissions";

export default function ExamsPage() {
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
  const canManage = can(user, P.examsUpdate);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold text-ink">الاختبارات</h2>
        {/* ⚠️ This page is one screen for two readers. A student opens it to SIT
            an exam and a teacher to write one, and both buttons used to show to
            both — so a student was offered "new exam" and "manage" on a paper
            they were about to take. The server refused; the offer was the bug. */}
        {canCreate && <Button href="/exams/new">اختبار جديد</Button>}
      </div>

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : exams.length === 0 ? (
        <EmptyState
          title="لا اختبارات متاحة الآن"
          description={
            canCreate
              ? "أنشئ اختباراً لتقيس فهم طلابك لما شرحته."
              : "لم ينشر مدرّسك اختباراً بعد. ستجده هنا حين ينشره."
          }
          action={canCreate ? <Button href="/exams/new">اختبار جديد</Button> : undefined}
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {exams.map((exam) => (
            <Card key={exam.uuid} as="article" padding="sm">
              <h3 className="mb-2 font-semibold text-ink">{exam.title}</h3>
              <p className="mb-4 line-clamp-2 text-sm text-ink-muted">
                {exam.description}
              </p>

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

              {exam.is_published ? (
                <div className="flex gap-2">
                  <Button href={`/exams/${exam.uuid}/take`} fullWidth>
                    ابدأ الاختبار
                  </Button>
                  {canManage && (
                    <Button href={`/exams/${exam.uuid}/manage`} variant="secondary">
                      إدارة
                    </Button>
                  )}
                </div>
              ) : (
                // An unpublished exam is the teacher's draft. A reader who cannot
                // manage it has no business being offered its editor — and the
                // list only shows drafts to somebody who can see them anyway.
                canManage && (
                  <Button href={`/exams/${exam.uuid}/manage`} variant="secondary" fullWidth>
                    إدارة الاختبار
                  </Button>
                )
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
