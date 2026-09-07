"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { useAuth } from "@/lib/auth-context";
import type { ClassSession } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { sessionDayKey } from "@/lib/session-format";
import { DashboardCard } from "./DashboardCard";
import { readTeacherSessions, teacherSessionsAudience } from "./TeacherSessionsCard";

const DAYS = 7;

/** «السبت» — اسمُ اليومِ وحدَه؛ التاريخُ الكاملُ في `title` تحتَه. */
function weekdayLabel(dayKey: string, timeZone: string): string {
  return new Date(`${dayKey}T12:00:00Z`).toLocaleDateString("ar", { weekday: "long", timeZone });
}

/**
 * ⚠️ **الأيّامُ السبعةُ تُبنى بمنطقةِ الحصّةِ الزمنيّة، لا بمنطقةِ الجهاز.**
 * حصّةُ الواحدةِ صباحاً بتوقيتِ الدوحةِ تقعُ في اليومِ السابقِ على حاسوبٍ ما زالَ
 * على توقيتِ إجازةٍ ماضية، فتُعَدُّ في العمودِ الخطأ — وهي نفسُ العلّةُ التي
 * كُتِبَ `sessionDayKey` من أجلِها، مرفوعةً درجةً إلى **أيُّ عمودٍ** بدلَ **أيُّ
 * عنوان**. ومنطقةُ الصفوفِ هي المرجع؛ فإذا لم يكن ثمَّ صفٌّ فالأعمدةُ أصفارٌ
 * كلُّها ولا يبقى للمنطقةِ أثرٌ إلّا في التسمية.
 */
function buckets(rows: ClassSession[], now: Date): Array<{ key: string; count: number }> {
  const zone = rows[0]?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;

  const days = Array.from({ length: DAYS }, (_, index) => ({
    key: sessionDayKey(new Date(now.getTime() + index * 86_400_000).toISOString(), zone),
    count: 0,
  }));

  for (const session of rows) {
    const day = days.find((entry) => entry.key === sessionDayKey(session.starts_at, session.timezone));

    if (day !== undefined) day.count += 1;
  }

  return days;
}

/**
 * حصصُ السبعةِ أيّامٍ القادمةِ في سبعةِ أعمدة (٠٢٩ · `US4` · `FR-007`).
 *
 * ⚠️ **من صفوفِ `TeacherSessionsCard` نفسِها، بطلبٍ واحدٍ** (`FR-018`): جدولٌ
 * يقولُ «حصّتانِ غداً» ورسمٌ يرسمُ ثلاثاً فوقَه هو شاشةٌ تناقضُ نفسَها بلا خطأٍ
 * في أيِّ مكان. والبطاقةُ تعرضُ خمسةَ صفوفٍ والرسمُ يعُدُّ الصفوفَ كلَّها — عيّنةٌ
 * وإجماليٌّ من ردٍّ واحد، لا رقمانِ من ردَّين.
 *
 * ⚠️ **والفراغُ أعمدةٌ صفريّةٌ + جملةٌ + طريق** (`FR-007` سيناريو ٣): رسمٌ فارغٌ
 * صامتٌ يُقرَأُ على أنّه عطلٌ في الشاشةِ لا على أنّه أسبوعٌ خالٍ. ولذلك **لا
 * تُستعمَلُ خاصّيّةُ `empty`** في `DashboardCard` هنا: هي تحلُّ **محلَّ** المحتوى
 * فتحذفُ الأعمدةَ الصفريّةَ التي هي نصفُ الجواب.
 */
export function WeekSessionsChartCard() {
  const { user } = useAuth();
  const { hostUuid, managesWorkspace } = teacherSessionsAudience(user);
  const shown = hostUuid !== null || managesWorkspace;

  const [rows, setRows] = useState<ClassSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    if (!shown) return;

    setLoading(true);
    setError(null);

    readTeacherSessions(hostUuid)
      .then((result) => setRows(result.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [hostUuid, shown]);

  useEffect(load, [load]);

  if (!shown) return null;

  const days = buckets(rows, new Date());
  // ⚠️ المقامُ واحدٌ على الأقلّ: أسبوعٌ خالٍ يجعلُ `count / max` قسمةً على صفرٍ
  // فيصيرُ الارتفاعُ `NaN%` — وهو عمودٌ لا يُرسَمُ بصمت.
  const max = Math.max(1, ...days.map((day) => day.count));
  const zone = rows[0]?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;

  return (
    <DashboardCard
      title="حصص الأسبوع القادم"
      href="/manage/sessions"
      loading={loading}
      error={error}
      onRetry={load}
    >
      {/* الرسمُ زينةٌ والقائمةُ هي البيان: كلُّ عمودٍ يحملُ اسمَ يومِه وعددَه
          نصّاً، فلا شيءَ هنا يُقرَأُ بالارتفاعِ وحدَه. */}
      <ol className="flex items-end justify-between gap-2" style={{ height: "8rem" }}>
        {days.map((day) => (
          <li key={day.key} className="flex h-full flex-1 flex-col items-center justify-end gap-1">
            <span className="text-xs text-ink-muted">
              <bdi>{arabicNumber(day.count)}</bdi>
            </span>
            <div
              className="w-full rounded-t bg-primary"
              style={{ height: `${(day.count / max) * 100}%`, minHeight: "2px" }}
              aria-hidden="true"
            />
            <span className="text-xs text-ink-muted">{weekdayLabel(day.key, zone)}</span>
          </li>
        ))}
      </ol>

      {!loading && error === null && rows.length === 0 && (
        <p className="mt-4 text-sm text-ink-muted">
          لا حصص في السبعة أيام القادمة.{" "}
          <Link
            href="/manage/sessions"
            className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            أنشئ حصّة
          </Link>
        </p>
      )}
    </DashboardCard>
  );
}
