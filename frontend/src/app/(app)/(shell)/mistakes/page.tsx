"use client";

import { useCallback, useEffect, useState } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { mistakes, type Mistake } from "@/lib/mistakes";

/**
 * Everything this student got wrong with this teacher, and the right answer.
 *
 * ⚠️ THE CORRECTION IS SHOWN, and that is the difference from the exam screen.
 * This is read after the paper is marked; a list of failures with nothing beside
 * them is a scoreboard, not a way to study.
 *
 * ⚠️ AND "FIXED" IS HIDDEN BY DEFAULT, NOT DELETED. A question they finally got
 * right is worth re-reading, so it stays one toggle away instead of vanishing.
 */
export default function MistakesPage() {
  const [rows, setRows] = useState<Mistake[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [includeResolved, setIncludeResolved] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    mistakes
      .list({ include_resolved: includeResolved })
      .then((response) => setRows(response.data ?? []))
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, [includeResolved]);

  useEffect(load, [load]);

  const standing = rows.filter((row) => !row.is_resolved).length;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">دفتر أخطائي</h1>
          <p className="text-sm text-ink-muted">
            كلّ ما أخطأت فيه مع هذا المدرّس، ومعه الصواب وشرحه.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant={includeResolved ? "primary" : "ghost"}
            onClick={() => setIncludeResolved((value) => !value)}
          >
            {includeResolved ? "القائم فقط" : "أظهر ما أصلحته"}
          </Button>
          {/* A link, not a handler: the paper is built by the page it opens, on
              mount. Building here would mean an attempt written for somebody who
              closed the tab before the navigation landed. */}
          {standing > 0 && (
            <Button href="/mistakes/practice">اختبرني في أخطائي</Button>
          )}
        </div>
      </header>

      {loading ? (
        <RowsSkeleton count={4} />
      ) : error !== null ? (
        <ErrorState description={error} onRetry={load} />
      ) : rows.length === 0 ? (
        <EmptyState
          title={includeResolved ? "لا أخطاء بعد" : "لا أخطاء قائمة"}
          description={
            includeResolved
              ? "لم تخطئ في شيء بعد — يظهر هنا كلّ سؤال أخطأت فيه، ومعه الصواب."
              : "أصلحت كلّ ما أخطأت فيه. اضغط «أظهر ما أصلحته» لمراجعتها."
          }
        />
      ) : (
        <div className="space-y-4">
          {rows.map((row) => (
            <Card key={row.uuid}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <p className="text-base font-medium text-ink">{row.question?.content ?? "—"}</p>
                <div className="flex gap-2">
                  {row.is_resolved ? (
                    <Badge tone="success">أصلحته</Badge>
                  ) : (
                    <Badge tone="warning">قائم</Badge>
                  )}
                  {row.times_wrong > 1 && (
                    <Badge tone="neutral">أخطأت فيه {row.times_wrong} مرّات</Badge>
                  )}
                </div>
              </div>

              <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                  <dt className="text-xs text-ink-muted">إجابتك</dt>
                  <dd className="text-sm text-ink">
                    {/* An unanswered question is the strongest evidence of a gap
                        on the page, so it says so rather than showing a blank. */}
                    {Array.isArray(row.your_answer)
                      ? row.your_answer.length === 0
                        ? "تركته بلا إجابة"
                        : row.your_answer.join(" · ")
                      : row.your_answer}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-muted">الصواب</dt>
                  <dd className="text-sm text-ink">
                    {row.correct_answer.length === 0 ? "—" : row.correct_answer.join(" · ")}
                  </dd>
                </div>
              </dl>

              {row.question?.explanation != null && row.question.explanation !== "" && (
                <p className="mt-3 rounded-lg bg-primary-soft/40 p-3 text-sm text-ink">
                  {row.question.explanation}
                </p>
              )}

              <p className="mt-3 text-xs text-ink-muted">
                {row.question?.concept?.name ?? "بلا فكرة"} · {formatDate(row.answered_at)}
              </p>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
