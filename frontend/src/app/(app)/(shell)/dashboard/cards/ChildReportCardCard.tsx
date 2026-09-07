"use client";

import { useCallback, useEffect, useState } from "react";

import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { periodLabel, reportCards, type ReportCard } from "@/lib/reviews";
import { DashboardCard } from "./DashboardCard";

/**
 * آخرُ كشفِ تقديراتٍ **منشورٍ** لابنٍ مسمّى.
 *
 * ⚠️ رابطُه إلى `/report-cards` وحدَه من بينِ بطاقاتِ الابنِ الأربع، لأنّها
 * الشاشةُ الكاملةُ الوحيدةُ القائمةُ فعلاً لهذه القراءة — وهي تحملُ مُبدِّلَ
 * أبناءٍ من قبلِ هذه المواصفة. والثلاثُ الأخرياتُ إلى `/family`، إذ لا شاشةَ
 * تخصُّ الابنَ لجدولِه ولا لحضورِه ولا لرصيدِه (`R5`).
 *
 * ⚠️ والمنشورُ وحدَه: المسوَّدةُ حكمٌ لم يُصدِرْه المدرّسُ بعد، وعرضُها لوليِّ
 * الأمرِ يُسلِّمُه رقماً قد يتغيّرُ قبلَ أن يُقال.
 */
export function ChildReportCardCard({ studentUuid }: { studentUuid: string }) {
  const [latest, setLatest] = useState<ReportCard | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    reportCards
      .mine(studentUuid)
      .then((result) => {
        const published = (result.data ?? [])
          .filter((card) => card.published_at !== null)
          .sort((a, b) => b.period_end.localeCompare(a.period_end));

        setLatest(published[0] ?? null);
      })
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [studentUuid]);

  useEffect(load, [load]);

  return (
    <DashboardCard
      title="آخر كشف تقديرات"
      href="/report-cards"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && latest === null ? (
          <p className="text-sm text-ink-muted">لم يُنشر كشف تقديرات لابنك بعد.</p>
        ) : null
      }
    >
      {latest === null ? null : (
        <div className="rounded-lg border border-line p-3">
          <p className="text-sm font-medium text-ink">{periodLabel(latest)}</p>
          <p className="mt-1 text-sm text-ink">
            {/* ⚠️ لا شيءَ هنا يُعيدُ حسابَ درجة: الكشفُ لقطةٌ، ومتصفّحٌ يشتقُّ
                المجموعَ من مكوّناتِه يُظهِرُ رقماً آخرَ لحظةَ يغيّرُ المدرّسُ
                أوزانَه، على ورقةٍ قرأَتها الأسرةُ سلفاً. */}
            التقدير العام:{" "}
            <span className="font-semibold">
              {latest.overall_pct === null ? (
                "لم يُحتسب بعد"
              ) : (
                <bdi>{`${arabicNumber(latest.overall_pct)}٪`}</bdi>
              )}
            </span>
          </p>
        </div>
      )}
    </DashboardCard>
  );
}
