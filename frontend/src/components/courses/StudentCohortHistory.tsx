"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { cohortEventLabel, manageCohorts, type CohortHistoryEvent } from "@/lib/cohorts";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";

/**
 * «سجل المجموعات» — one student's moves between the groups of ONE course
 * (FR-034), read from `GET /manage/courses/{course}/students/{student}/cohort-history`.
 *
 * ⚠️ THE ENDPOINT HAD NO CALLER. The group's own log answers «what happened in
 * this group»; a teacher asked why a student is in «الأحد» needs the other
 * axis — every group that student passed through, who moved them, and why.
 *
 * ⚠️ AND THE REASON IS SHOWN. A rejection carries one the student read, and a
 * log that dropped it would leave the teacher unable to see what was said.
 */
export function StudentCohortHistory({
  courseUuid,
  studentUuid,
}: {
  courseUuid: string;
  studentUuid: string;
}) {
  const [rows, setRows] = useState<CohortHistoryEvent[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    manageCohorts
      .studentHistory(courseUuid, studentUuid)
      .then((r) => {
        if (!cancelled) setRows(r.data ?? []);
      })
      .catch((e: unknown) => {
        if (!cancelled) setError(userMessage(e));
      });

    return () => {
      cancelled = true;
    };
  }, [courseUuid, studentUuid]);

  if (error !== null) return <Alert tone="danger" title="تعذّر تحميل سجل المجموعات">{error}</Alert>;

  if (rows === null) {
    return (
      <div className="space-y-2" aria-hidden>
        <div className="h-3 w-40 animate-pulse rounded bg-primary-soft" />
        <div className="h-3 w-28 animate-pulse rounded bg-primary-soft" />
      </div>
    );
  }

  if (rows.length === 0) {
    return <p className="text-xs text-ink-muted">لا حركة لهذا الطالب بين مجموعات الكورس بعد.</p>;
  }

  return (
    <ol className="space-y-2" aria-label="سجل المجموعات">
      {rows.map((row) => (
        <li key={row.uuid} className="border-s-2 border-line ps-3 text-xs">
          <p className="text-ink">
            {cohortEventLabel(row.event)}
            {row.cohort?.name != null && <> — «{row.cohort.name}»</>}
            {row.event === "transferred" && row.from_cohort?.name != null && (
              <> من «{row.from_cohort.name}»</>
            )}
          </p>
          {row.reason !== null && <p className="text-ink-muted">{row.reason}</p>}
          <p className="text-ink-muted">
            {formatDate(row.created_at)}
            {row.actor?.name != null && <> · بيد {row.actor.name}</>}
          </p>
        </li>
      ))}
    </ol>
  );
}
