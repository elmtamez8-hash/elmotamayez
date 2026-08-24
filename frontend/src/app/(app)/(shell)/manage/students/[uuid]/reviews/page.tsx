"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams, useSearchParams } from "next/navigation";

import { AxisScale } from "@/components/ui/AxisScale";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { arabicDecimal, arabicNumber } from "@/lib/numerals";
import { PERIODIC_AXES, periodLabel, reviews, type PeriodicReview } from "@/lib/reviews";

/** The month we are in, which is the period a teacher means by default. */
function thisMonth(): { start: string; end: string } {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);

  const iso = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;

  return { start: iso(start), end: iso(end) };
}

/**
 * The teacher writes a student's periodic assessment (spec 010 · US4 · FR-028).
 *
 * ⚠️ THE NAME IN THE HEADING COMES FROM THE LINK, NOT FROM THE API. The payload
 * carries no student name on purpose — a name in it is one careless list away
 * from FR-035, a student reading a judgement of their classmate. The register
 * that links here already knows whose row was clicked.
 *
 * ⚠️ AND PUBLISHING IS SEPARATE FROM SAVING. A saved row is a draft the student
 * cannot see; publishing is what tells them and their guardian, once. Folding the
 * two together would send the notification on every keystroke's worth of «save».
 */
export default function StudentReviewsPage() {
  const params = useParams<{ uuid: string }>();
  const search = useSearchParams();

  const studentUuid = params.uuid;
  const studentName = search.get("name") ?? "الطالب";

  const period = thisMonth();

  const [rows, setRows] = useState<PeriodicReview[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const [axes, setAxes] = useState<Record<string, number>>({
    commitment: 3,
    participation: 3,
    homework: 3,
    improvement: 3,
  });
  const [note, setNote] = useState("");

  const load = useCallback(() => {
    setState("loading");

    reviews
      .forStudent(studentUuid)
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, [studentUuid]);

  useEffect(load, [load]);

  const save = () => {
    setBusy(true);
    setProblem(null);

    reviews
      .save({
        student_uuid: studentUuid,
        period_start: period.start,
        period_end: period.end,
        commitment: axes.commitment,
        participation: axes.participation,
        homework: axes.homework,
        improvement: axes.improvement,
        note: note.trim() === "" ? null : note.trim(),
      })
      .then(load)
      // Never a raw error: 422 lands under its field, everything else becomes a
      // sentence.
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setBusy(false));
  };

  const publish = (uuid: string) => {
    setBusy(true);
    setProblem(null);

    reviews
      .publish(uuid)
      .then(load)
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setBusy(false));
  };

  if (state === "error") return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">التقييم الدوري — {studentName}</h1>
        <p className="mt-1 text-sm text-ink-muted">
          يراه الطالب ووليّ أمره بعد النشر، ولا يراه أيّ طالبٍ آخر.
        </p>
      </header>

      {problem !== null && (
        <Alert tone="danger" title="تعذّر الحفظ">
          {problem}
        </Alert>
      )}

      <Card as="section">
        <h2 className="mb-4 text-lg font-bold text-ink">
          تقييم الفترة {periodLabel({ period_start: period.start, period_end: period.end })}
        </h2>

        <div className="space-y-4">
          {PERIODIC_AXES.map((axis) => (
            <AxisScale
              key={axis.key}
              name={axis.key}
              label={axis.label}
              value={axes[axis.key]}
              onChange={(value) => setAxes((current) => ({ ...current, [axis.key]: value }))}
              disabled={busy}
            />
          ))}

          <TextareaField
            id="review-note"
            label="ملاحظة للطالب (اختيارية)"
            value={note}
            onChange={setNote}
            rows={3}
          />

          <Button onClick={save} loading={busy}>
            حفظ كمسوّدة
          </Button>
        </div>
      </Card>

      <section aria-labelledby="past-reviews">
        <h2 id="past-reviews" className="mb-3 text-lg font-bold text-ink">
          التقييمات السابقة
        </h2>

        {state === "loading" ? (
          <RowsSkeleton />
        ) : rows.length === 0 ? (
          <EmptyState title="لا توجد تقييمات بعد" description="اكتب أوّل تقييمٍ لهذه الفترة." />
        ) : (
          <ul className="space-y-3">
            {rows.map((review) => (
              <li key={review.uuid}>
                <Card padding="sm" as="article">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <p className="font-semibold text-ink">{periodLabel(review)}</p>
                      <p className="text-sm text-ink-muted">المتوسّط {arabicDecimal(review.average)} من ٥</p>
                    </div>

                    <div className="flex items-center gap-3">
                      <Badge tone={review.is_published ? "success" : "neutral"}>
                        {review.is_published ? "منشور" : "مسوّدة"}
                      </Badge>

                      {!review.is_published && (
                        <Button
                          variant="secondary"
                          size="sm"
                          loading={busy}
                          onClick={() => publish(review.uuid)}
                        >
                          نشر
                        </Button>
                      )}
                    </div>
                  </div>

                  <dl className="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    {PERIODIC_AXES.map((axis) => (
                      <div key={axis.key}>
                        <dt className="text-ink-muted">{axis.label}</dt>
                        <dd className="font-semibold text-ink">{arabicNumber(review[axis.key])}</dd>
                      </div>
                    ))}
                  </dl>

                  {review.note !== null && (
                    <p className="mt-3 text-sm text-ink-muted">{review.note}</p>
                  )}
                </Card>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
