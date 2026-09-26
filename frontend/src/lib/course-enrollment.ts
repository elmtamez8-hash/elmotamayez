import { api } from "@/lib/api";
import type { Enrollment } from "@/lib/types";

/**
 * «هل يفتحُ لي هذا الكورسُ منهجَه؟» — يُسألُ قبلَ قراءةِ المنهجِ والمجموعات.
 *
 * ⛔ REPORTED 2026-09-26. The public course page read `/courses/{uuid}/curriculum`
 * (`CourseOwnershipProvider`) and `/courses/{uuid}/cohorts` (`MyCohortProvider`)
 * for EVERY signed-in learner — and both refuse a reader with no enrolment, so a
 * student browsing a course they had not bought fired two 403s on every visit.
 * The refusal was being used as the answer. This asks the question directly, of
 * the reader's own rows: `GET /enrollments?course=` answers 200 with zero or one.
 *
 * ⚠️ `grants_access` IS THE DOOR'S OWN ANSWER (`Enrollment::grantsContentAccess()`),
 * never a status list repeated here — an expired or cancelled row is an
 * enrolment that opens nothing, and both doors would still refuse it.
 *
 * ⚠️ ONE REQUEST FOR BOTH PROVIDERS. They mount in the same commit, so the
 * in-flight promise is shared per course; it is dropped once settled, because a
 * student who buys the course and comes back must be asked again rather than
 * served the answer from before they paid.
 *
 * A failure answers «no» — the page then shows its public face, which is the
 * correct page for everybody it could be wrong about, and never an error: no
 * one pressed anything.
 */
const inFlight = new Map<string, Promise<boolean>>();

export function grantsCourseAccess(courseUuid: string): Promise<boolean> {
  const pending = inFlight.get(courseUuid);

  if (pending !== undefined) return pending;

  const answer = Promise.resolve()
    .then(() =>
      api.get<{ data?: Enrollment[] }>(`/enrollments?course=${encodeURIComponent(courseUuid)}`),
    )
    .then((response) => (response.data ?? []).some((row) => row.grants_access === true))
    .catch(() => false)
    .finally(() => inFlight.delete(courseUuid));

  inFlight.set(courseUuid, answer);

  return answer;
}
