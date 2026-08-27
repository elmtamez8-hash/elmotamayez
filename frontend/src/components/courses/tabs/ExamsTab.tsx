"use client";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import type { CourseExam } from "@/lib/course-hub";

/**
 * This course's papers, with the reader's own record on each (US2 · FR-017).
 *
 * ⚠️ `my_attempts` ABSENT MEANS «NOT ASKED», NOT «NEVER SAT». The server sends it
 * only when the list was loaded with the reader's own attempts, so its absence
 * must not be painted as a zero — a student told they scored 0% on a paper they
 * never opened is the harsher of the two readings and the wrong one. Same reason
 * `best_score` is `null` rather than `0`.
 *
 * ⚠️ AND THE PRACTICE RUNS ARE NOT COUNTED HERE. The server narrows to
 * `is_practice = false`: a practice run is the student marking themselves, and
 * reporting a rehearsal as a result puts a number on this page that no exam
 * record agrees with.
 */
export function ExamsTab({ exams }: { exams: CourseExam[] }) {
  if (exams.length === 0) {
    return (
      <EmptyState
        title="لا اختبارات في هذه المادّة بعد"
        description="حين ينشر مدرّسك اختباراً ستجده هنا بنتيجتك فيه."
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      {exams.map((exam, index) => {
        const mine = exam.my_attempts;
        const sat = mine !== undefined && mine.count > 0;
        const left = exam.max_attempts - (mine?.count ?? 0);

        return (
          /*
            The stagger lives on a wrapper, not on the `Card`: shared UI takes no
            free-form `className` — appearance there is a closed set of variants,
            which is what keeps it shared. The cap keeps a long paper list from
            landing its last card seconds after its first, and the reduced-motion
            block in `globals.css` zeroes all of it (FR-012).
          */
          <div
            key={exam.uuid}
            className="banner-rise"
            style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
          >
          <Card as="article" padding="sm">
            <div className="mb-2 flex items-start justify-between gap-3">
              <h3 className="font-semibold text-ink">{exam.title}</h3>
              {/* The word carries the state; the tone is emphasis on top of it.
                  A colour token `@theme` never defined paints NOTHING at all in
                  Tailwind v4, silently — this tree has shipped three states that
                  way — so nothing here depends on the colour alone. */}
              {sat && (
                <Badge tone={mine.passed ? "success" : "danger"}>
                  {mine.passed ? "ناجح" : "لم تجتزه"}
                </Badge>
              )}
            </div>

            <div className="mb-3 flex flex-wrap gap-2">
              <Badge>
                <bdi>{exam.duration_minutes}</bdi>&nbsp;دقيقة
              </Badge>
              <Badge>
                النجاح&nbsp;<bdi>{exam.passing_score}%</bdi>
              </Badge>
            </div>

            <p className="mb-4 text-sm text-ink-muted">
              {mine === undefined
                ? "لم تُقرأ محاولاتك."
                : sat ? (
                    <>
                      أفضل نتيجة <bdi>{mine.best_score ?? 0}%</bdi> من{" "}
                      <bdi>{mine.count}</bdi> محاولة
                    </>
                  ) : (
                    "لم تجرِّبه بعد."
                  )}
            </p>

            <div className="flex flex-wrap gap-2">
              {/*
                ⚠️ THE ATTEMPT LIMIT IS ENFORCED IN THE ACTION, AND THIS ONLY
                STOPS OFFERING. Hiding the button is not the guard — the server
                refuses a sixth sitting whatever the screen draws — but offering
                one that always refuses is the defect this page exists to end.
              */}
              {left > 0 ? (
                <Button href={`/exams/${exam.uuid}/take`} size="sm">
                  {sat ? "أعد المحاولة" : "ابدأ الاختبار"}
                </Button>
              ) : (
                <span className="text-sm text-ink-muted">استنفدت محاولاتك.</span>
              )}

              {mine?.last_uuid != null && (
                <Button href={`/exams/${exam.uuid}/result`} size="sm" variant="secondary">
                  نتيجتك
                </Button>
              )}
            </div>
          </Card>
          </div>
        );
      })}
    </div>
  );
}
