"use client";

import { useCallback, useEffect, useState } from "react";

import { GradingSchemeForm } from "@/components/community/GradingSchemeForm";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { gradingSchemes, GRADE_COMPONENTS, type GradingScheme } from "@/lib/reviews";

/**
 * The teacher's grade weightings (spec 010 · US5 · FR-049).
 *
 * ⚠️ THERE IS NO «GENERATE CARD» BUTTON HERE, AND ITS ABSENCE IS THE DESIGN. A
 * card spans every teacher the student studies with, so a teacher who could
 * generate one either reads a colleague's grades or produces a single-segment
 * document displayed as the student's whole record. It is built by a scheduled
 * platform job at the start of each month; this screen decides what that job
 * will weigh, and the teacher reads their own segment on the student's page.
 */
export default function GradingSchemesPage() {
  const [rows, setRows] = useState<GradingScheme[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");

    gradingSchemes
      .list()
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  async function save(input: {
    period_start: string;
    period_end: string;
    weights: Record<string, number>;
  }) {
    setBusy(true);
    setError(null);

    try {
      await gradingSchemes.save(input);
      load();
    } catch (err) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  }

  if (state === "error") return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">أوزان التقدير</h1>
        <p className="mt-1 text-sm text-ink-muted">
          كيف تتركّب درجة الطالب في كشف التقديرات. يصدر الكشف في مطلع كلّ شهر عن الشهر الذي
          سبقه، ويستعمل الأوزان السارية على تلك الفترة.
        </p>
      </header>

      <Card>
        <h2 className="mb-4 font-semibold text-ink">تركيبة جديدة</h2>
        <GradingSchemeForm onSave={save} busy={busy} error={error} />
      </Card>

      {state === "loading" ? (
        <RowsSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState
          title="لا توجد تركيبة محفوظة"
          description="بلا تركيبة تُوزَن المكوّنات الأربعة بالتساوي."
        />
      ) : (
        <ul className="space-y-3">
          {rows.map((scheme) => (
            <li key={scheme.uuid}>
              <Card as="article">
                <p className="font-semibold text-ink">
                  {scheme.period_start} – {scheme.period_end}
                </p>
                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                  {GRADE_COMPONENTS.map((component) => (
                    <div key={component.key} className="flex gap-1">
                      <dt className="text-ink-muted">{component.label}</dt>
                      <dd className="font-semibold text-ink">
                        {arabicNumber(scheme.weights[component.key] ?? 0)}٪
                      </dd>
                    </div>
                  ))}
                </dl>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
