"use client";

import { useCallback, useEffect, useState } from "react";

import { ProgressBar } from "@/components/ui/ProgressBar";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import type { Enrollment } from "@/lib/types";
import { ProgressIcon } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";
import { sharedRead } from "./shared-read";

/**
 * الكورساتُ الجاريةُ — القراءةُ التي يقرؤُها **رقمُ** `StatCountsCard` و**أشرطةُ**
 * هذه البطاقةِ معاً، طلباً واحداً (`FR-018`).
 */
export function readActiveEnrolments(): Promise<{ data: Enrollment[]; meta?: { total?: number } }> {
  return sharedRead("/enrollments?status=active", () =>
    api.get<{ data: Enrollment[]; meta?: { total?: number } }>("/enrollments?status=active"),
  );
}

/**
 * أين وصلَ الطالبُ في كلِّ كورسٍ جارٍ (٠٢٩ · `US4`).
 *
 * ⚠️ **الأقلُّ إنجازاً أوّلاً، لا الأحدث.** سؤالُ هذا الرسمِ «ما الذي تركتُه
 * خلفي؟»، وترتيبٌ بالتاريخِ يدفنُ الكورسَ المتوقّفَ عندَ ٥٪ تحتَ أربعةٍ اشتُريَت
 * بعدَه — وهو بالضبطِ الكورسُ الذي يُفتَحُ الرسمُ من أجلِه.
 *
 * ⚠️ **وسقفٌ يُوَثَّقُ ولا يُرفَع**: قراءةُ التسجيلاتِ تُقسِّمُ بخمسةَ عشرَ صفّاً
 * ولا تقرأُ `per_page`، فطالبٌ عندَه أكثرُ من ذلك يرى أوّلَ خمسةَ عشرَ من صفحةٍ
 * مرتَّبةٍ بالخادم. والرقمُ فوقَ الرسمِ من `meta.total` فيبقى صادقاً، والشريطُ
 * عيّنةٌ لا جرد. رفعُ السقفِ يبدأُ بمعامِلِ صفحةٍ في الخادم، لا برقمٍ هنا.
 *
 * ⚠️ وكلُّ قيمةٍ مرسومةٍ **مقروءةٌ نصّاً بجوارِها**: شريطٌ بلا رقمٍ لا يقولُ
 * شيئاً لقارئِ الشاشة، و`ProgressBar` يحملُ `aria-label` واحداً لا جدولاً.
 */
export function ProgressChartCard() {
  const [rows, setRows] = useState<Enrollment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    readActiveEnrolments()
      .then((result) => setRows(result.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  // الأقلُّ إنجازاً أوّلاً، والتعادلُ بالاسمِ عربيّاً — لا بترتيبِ الخادمِ الذي
  // لا يَعِدُ بشيءٍ عندَ تساوي النسبة.
  const sorted = [...rows].sort(
    (a, b) =>
      a.progress_pct - b.progress_pct || a.course_title.localeCompare(b.course_title, "ar"),
  );

  return (
    <DashboardCard
      title="تقدّمك في كورساتك"
      Icon={ProgressIcon}
      href="/enrollments"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && sorted.length === 0 ? (
          <p className="text-sm text-ink-muted">لا كورسات جارية.</p>
        ) : null
      }
    >
      <ul className="space-y-4">
        {sorted.map((row) => (
          <li key={row.uuid} className="space-y-1">
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span className="text-ink">{row.course_title}</span>
              <span className="shrink-0 text-ink-muted">
                <bdi>{`${arabicNumber(Math.round(row.progress_pct))}٪`}</bdi>
              </span>
            </div>
            <ProgressBar value={row.progress_pct} label={row.course_title} size="sm" />
          </li>
        ))}
      </ul>
    </DashboardCard>
  );
}
