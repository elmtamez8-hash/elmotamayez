"use client";

import { useCallback, useEffect, useState } from "react";

import { GradingSchemeForm } from "@/components/community/GradingSchemeForm";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { RecordList, RecordRow } from "@/components/ui/RecordList";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { HistoryIcon, ProgressIcon, ScheduleIcon, SparkIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { counted } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";
import { cardPeriodLabel, gradingSchemes, GRADE_COMPONENTS, type GradingScheme } from "@/lib/reviews";

/** «٣ تركيبات محفوظة» — the scheme is feminine, and its adjective agrees with it. */
const SCHEMES = {
  one: "تركيبة واحدة محفوظة",
  two: "تركيبتان محفوظتان",
  few: "تركيبات محفوظة",
  many: "تركيبةً محفوظةً",
  other: "تركيبة محفوظة",
};

/**
 * The teacher's grade weightings (spec 010 · US5 · FR-049).
 *
 * ⚠️ THERE IS NO «GENERATE CARD» BUTTON HERE, AND ITS ABSENCE IS THE DESIGN. A
 * card spans every teacher the student studies with, so a teacher who could
 * generate one either reads a colleague's grades or produces a single-segment
 * document displayed as the student's whole record. It is built by a scheduled
 * platform job at the start of each month; this screen decides what that job
 * will weigh, and the teacher reads their own segment on the student's page.
 *
 * The worked example of the staff design kit (`docs/design/manage-pages.md`):
 * `PageHeader` → the form in a `Card` → a `SectionHeading` → `RecordList` of
 * `RecordRow`s, with the three list states from `states/`.
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

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={ProgressIcon}
        title="أوزان التقدير"
        description="كيف تتركّب درجة الطالب في كشف التقديرات. يصدر الكشف في مطلع كلّ شهر عن الشهر الذي سبقه، ويستعمل الأوزان السارية على تلك الفترة."
      />

      <Card as="section" labelledBy="new-grading-scheme">
        <div className="mb-4">
          <SectionHeading id="new-grading-scheme" Icon={SparkIcon} title="تركيبة جديدة" />
        </div>
        <GradingSchemeForm onSave={save} busy={busy} error={error} />
      </Card>

      <section aria-labelledby="saved-grading-schemes" className="space-y-4">
        <SectionHeading
          id="saved-grading-schemes"
          Icon={HistoryIcon}
          title="التركيبات المحفوظة"
          description={state === "ready" && rows.length > 0 ? counted(rows.length, SCHEMES) : undefined}
        />

        {state === "loading" && <RowsSkeleton count={3} />}

        {state === "error" && <ErrorState onRetry={load} />}

        {state === "ready" &&
          (rows.length === 0 ? (
            <EmptyState
              Icon={ProgressIcon}
              title="لا توجد تركيبة محفوظة"
              description="بلا تركيبة تُوزَن المكوّنات الأربعة بالتساوي. احفظ أوّل تركيبة من النموذج أعلاه."
            />
          ) : (
            <RecordList labelledBy="saved-grading-schemes">
              {rows.map((scheme) => (
                <RecordRow
                  key={scheme.uuid}
                  level={4}
                  Icon={ScheduleIcon}
                  title={cardPeriodLabel(scheme)}
                  meta={GRADE_COMPONENTS.map((component) => ({
                    key: component.key,
                    label: component.label,
                    value: `${arabicNumber(scheme.weights[component.key] ?? 0)}٪`,
                  }))}
                />
              ))}
            </RecordList>
          ))}
      </section>
    </div>
  );
}
