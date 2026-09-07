"use client";

import { useCallback, useEffect, useState } from "react";

import type { ChildAttendanceSummary } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { readChildAttendance } from "./ChildAttendanceCard";
import type { ChildCardProps } from "./ChildCardProps";
import { DashboardCard } from "./DashboardCard";

/**
 * الفئاتُ الأربعُ ورموزُها.
 *
 * ⚠️ **الأربعةُ كلُّها معرَّفةٌ في `@theme`، ولا واحدَ منها اسمٌ يبدو كذلك.** لا
 * `success` ولا `warning` ولا `error` في هذا المستودعِ إطلاقاً — و Tailwind v4 لا
 * يُخرِجُ قاعدةً لرمزٍ لم يرَه، فالصنفُ حاضرٌ وصحيحُ الشكلِ ولا يرسمُ شيئاً: لا
 * خطأ، لا تحذير، ولا فرقٌ في لقطة. شُحِنَ هذا العطلُ **أربعَ مرّاتٍ** هنا، وآخرُها
 * `bg-surface-muted` في قائمةِ المشاركين. و`src/lib/theme-tokens.test.ts` يمسحُ
 * المصدرَ لا النيّة.
 */
const STATES: Array<{ key: keyof ChildAttendanceSummary; label: string; swatch: string }> = [
  { key: "present", label: "حاضر", swatch: "bg-secondary" },
  { key: "late", label: "متأخّر", swatch: "bg-accent" },
  { key: "absent", label: "غائب", swatch: "bg-danger" },
  { key: "excused", label: "بعذر", swatch: "bg-ink-muted" },
];

/**
 * حضورُ الابنِ شريطاً مكدَّساً (٠٢٩ · `US4`).
 *
 * ⚠️ **الشريطُ زينةٌ والمفتاحُ هو البيان.** كلُّ قطعةٍ `aria-hidden`، والأعدادُ
 * الأربعةُ مكتوبةٌ نصّاً في `<dl>` تحتَه: عرضٌ نسبيٌّ لا يقولُ شيئاً لقارئِ
 * الشاشة، ولونٌ وحدَه لا يقولُ شيئاً لمن لا يُفرِّقُ الأخضرَ من الأحمر — وهي
 * القاعدةُ نفسُها التي كتبَها `ProgressBar` في هذا المستودعِ من قبل.
 *
 * ⚠️ **ومن الأرقامِ التي جلبَتها `ChildAttendanceCard`، بطلبٍ واحد** (`FR-018`).
 */
export function AttendanceChartCard({ studentUuid, studentName }: ChildCardProps) {
  const [summary, setSummary] = useState<ChildAttendanceSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    readChildAttendance(studentUuid)
      .then((result) => setSummary(result.data))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [studentUuid]);

  useEffect(load, [load]);

  const total = summary?.total ?? 0;

  return (
    <DashboardCard
      title={`توزيع حضور ${studentName}`}
      href="/family"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        summary !== null && total === 0 ? (
          <p className="text-sm text-ink-muted">لم تُسجَّل حصص لابنك في هذه المدّة بعد.</p>
        ) : null
      }
    >
      {summary === null ? null : (
        <div className="space-y-4">
          <div className="flex h-4 overflow-hidden rounded-full bg-line" aria-hidden="true">
            {STATES.map((state) => (
              <div
                key={state.key}
                className={state.swatch}
                style={{ width: `${((summary[state.key] as number) / total) * 100}%` }}
              />
            ))}
          </div>

          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {STATES.map((state) => (
              <div key={state.key} className="flex items-center gap-2">
                <span className={`h-3 w-3 shrink-0 rounded-full ${state.swatch}`} aria-hidden="true" />
                <dt className="text-xs text-ink-muted">{state.label}</dt>
                <dd className="text-sm font-semibold text-ink">
                  <bdi>{arabicNumber(summary[state.key] as number)}</bdi>
                </dd>
              </div>
            ))}
          </dl>
        </div>
      )}
    </DashboardCard>
  );
}
