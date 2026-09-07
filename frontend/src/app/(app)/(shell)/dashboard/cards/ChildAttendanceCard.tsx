"use client";

import { useCallback, useEffect, useState } from "react";

import { classSessions, type ChildAttendanceSummary } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { MembersIcon } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";
import type { ChildCardProps } from "./ChildCardProps";
import { sharedRead } from "./shared-read";

/** الفئاتُ الأربعُ بالترتيبِ الذي تُقرَأُ به، وأسماؤُها كما يقولُها وليُّ الأمر. */
const STATES: Array<{ key: keyof ChildAttendanceSummary; label: string }> = [
  { key: "present", label: "حاضر" },
  { key: "late", label: "متأخّر" },
  { key: "absent", label: "غائب" },
  { key: "excused", label: "بعذر" },
];

/**
 * ملخّصُ حضورِ الابن — طلبٌ واحدٌ تقرؤُه هذه البطاقةُ ورسمُها معاً (`FR-018`).
 *
 * ⚠️ رقمانِ من ردَّينِ مختلفَينِ عن الطفلِ نفسِه على شاشةٍ واحدةٍ يتناقضانِ بلا
 * خطأٍ في أيِّ مكان، والمفتاحُ يحملُ الابنَ لأنّ المُبدِّلَ يُغيِّرُه.
 */
export function readChildAttendance(studentUuid: string) {
  return sharedRead(`attendance:${studentUuid}`, () => classSessions.childAttendance(studentUuid));
}

/**
 * حضورُ الابنِ في آخرِ ثلاثينَ يوماً (٠٢٩ · `FR-019`).
 *
 * الأعدادُ الأربعةُ والنسبةُ **نصّاً**: الرسمُ يأتي في `US4` فوقَ هذه الأرقامِ
 * نفسِها، لا فوقَ قراءةٍ ثانيةٍ له (`FR-018`).
 */
export function ChildAttendanceCard({ studentUuid, studentName }: ChildCardProps) {
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

  return (
    <DashboardCard
      title={`حضور ${studentName}`}
      Icon={MembersIcon}
      href="/family"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        summary !== null && summary.total === 0 ? (
          /* ⚠️ «لم تُسجَّلْ حصصٌ بعد» لا «صفرُ حضور»: الثانيةُ جملةٌ عن طالبٍ
             يتغيّب، والخادمُ يُرسِلُ `rate_pct: null` لهذا السببِ بعينِه. */
          <p className="text-sm text-ink-muted">لم تُسجَّل حصص لابنك في هذه المدّة بعد.</p>
        ) : null
      }
    >
      {summary === null ? null : (
        <div className="space-y-4">
          <p className="text-sm text-ink">
            نسبة الحضور في آخر <bdi>{arabicNumber(summary.window_days)}</bdi> يوماً:{" "}
            <span className="font-semibold">
              {summary.rate_pct === null ? "—" : <bdi>{`${arabicNumber(summary.rate_pct)}٪`}</bdi>}
            </span>
          </p>

          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {STATES.map((state) => (
              <div key={state.key} className="rounded-lg border border-line p-3">
                <dt className="text-xs text-ink-muted">{state.label}</dt>
                <dd className="text-lg font-semibold text-ink">
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
