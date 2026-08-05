"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import type { Enrollment } from "@/lib/types";
import { formatDate, statusLabel, statusTone, TONE_CLASSES } from "@/lib/labels";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

interface EnrollmentWithCourse extends Enrollment {
  course?: { title: string; uuid: string };
}

export default function EnrollmentsPage() {
  const [enrollments, setEnrollments] = useState<EnrollmentWithCourse[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: EnrollmentWithCourse[] }>("/enrollments")
      .then((res) => setEnrollments(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-ink">تعلّمي</h2>

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : enrollments.length === 0 ? (
        <EmptyState
          title="لم تسجّل في أي كورس بعد"
          description="اختر كورساً من السوق وابدأ أول درس اليوم."
          action={
            <Link
              href="/courses"
              className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              تصفّح الكورسات
            </Link>
          }
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {enrollments.map((enr) => (
            <article
              key={enr.uuid}
              className="rounded-xl border border-line bg-surface-raised p-5"
            >
              <div className="mb-3 flex items-start justify-between gap-3">
                <h3 className="font-semibold text-ink">
                  {enr.course?.title ?? (
                    <>
                      كورس رقم <bdi>{enr.course_id}</bdi>
                    </>
                  )}
                </h3>
                <span
                  className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${
                    TONE_CLASSES[statusTone(enr.status)]
                  }`}
                >
                  {statusLabel(enr.status)}
                </span>
              </div>

              <div className="mb-3">
                <div className="mb-1 flex justify-between text-xs text-ink-muted">
                  <span>نسبة الإنجاز</span>
                  <span>
                    <bdi>{enr.progress_pct}%</bdi>
                  </span>
                </div>
                <div
                  className="h-2 overflow-hidden rounded-full bg-line"
                  role="progressbar"
                  aria-valuenow={enr.progress_pct}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label="نسبة الإنجاز"
                >
                  <div
                    className={`h-full ${
                      enr.status === "completed" ? "bg-secondary" : "bg-primary"
                    }`}
                    style={{ width: `${enr.progress_pct}%` }}
                  />
                </div>
              </div>

              <p className="text-xs text-ink-muted">
                سُجِّل في {formatDate(enr.enrolled_at)}
              </p>
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
