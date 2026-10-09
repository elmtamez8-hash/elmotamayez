"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { useAuth } from "@/lib/auth-context";
import type { ClassSession } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { sessionDayKey } from "@/lib/session-format";
import { ScheduleIcon } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";
import { readTeacherSessions, teacherSessionsAudience } from "./TeacherSessionsCard";
import { isRefusal } from "./shared-read";
import { counted, NOUNS } from "@/lib/labels";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";

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
function buckets(rows: ClassSession[], now: Date, zone: string): Array<{ key: string; sessions: ClassSession[] }> {
  const days = Array.from({ length: DAYS }, (_, index) => ({
    key: sessionDayKey(new Date(now.getTime() + index * 86_400_000).toISOString(), zone),
    sessions: [] as ClassSession[],
  }));

  for (const session of rows) {
    const day = days.find((entry) => entry.key === sessionDayKey(session.starts_at, zone));

    // ⚠️ الصفوفُ نفسُها تُحفَظُ لا عددُها وحدَه: العمودُ يقولُ «٣ حصص السبت»
    // ويتركُ المدرّسَ يفتحُ التقويمَ ليعرفَ **أيّ** ثلاث. والصفوفُ محمَّلةٌ هنا
    // أصلاً، فعرضُها لا يكلّفُ طلباً.
    if (day !== undefined) day.sessions.push(session);
  }

  for (const day of days) {
    // الخادمُ يرتّبُ القائمةَ كاملةً، لا كلَّ يومٍ على حدة — والفرزُ هنا هو ما
    // يجعلُ ساعاتِ اليومِ تُقرَأُ من أوّلِها.
    day.sessions.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
  }

  return days;
}

/**
 * «26/09» — the day and month with no year: next week never spans two years.
 * ⚠️ `latn` IS PINNED, as in `formatDate()`: a bare `"ar"` answers Arabic-Indic
 * or Latin digits by the visitor's ICU version, and dates in this product are
 * Latin by decision (see `numerals.ts`).
 */
function dateLabel(dayKey: string, timeZone: string): string {
  return new Date(`${dayKey}T12:00:00Z`).toLocaleDateString("ar", {
    day: "2-digit",
    month: "2-digit",
    numberingSystem: "latn",
    timeZone,
  });
}

function clock(session: ClassSession, timeZone: string): string {
  return new Date(session.starts_at).toLocaleTimeString("ar", {
    hour: "2-digit",
    minute: "2-digit",
    numberingSystem: "latn",
    timeZone,
  });
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
  const zone = useViewerTimeZone();
  const { hostUuid, readsWorkspace, canManage } = teacherSessionsAudience(user);
  const shown = hostUuid !== null || readsWorkspace;

  const [rows, setRows] = useState<ClassSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refused, setRefused] = useState(false);
  /*
   * ⚠️ الضغطُ وحدَه يختارُ اليوم، والمرورُ لونٌ فقط. كانَ المرورُ يفتحُ لوحةً تحتَ
   * الرسمِ فتطولُ البطاقة، واللوحةُ أعمدةُ CSS فتتوزّعُ من جديدٍ وتخرجُ البطاقةُ من
   * تحتِ المؤشِّرِ فتُغلَقُ اللوحةُ وتعود — رعشةٌ بلَّغَ عنها المالكُ (٢٠٢٦-١٠-٠٩).
   * وهاتفٌ لا مؤشِّرَ له لم يكنْ يملكُ إلّا الضغطَ أصلاً. `null` = اليوم.
   */
  const [pinned, setPinned] = useState<string | null>(null);

  const load = useCallback(() => {
    if (!shown) return;

    setLoading(true);
    setError(null);

    readTeacherSessions(hostUuid)
      .then((result) => setRows(result.data ?? []))
      .catch((err) => (isRefusal(err) ? setRefused(true) : setError(userMessage(err))))
      .finally(() => setLoading(false));
  }, [hostUuid, shown]);

  useEffect(load, [load]);

  if (!shown || refused) return null;

  const days = buckets(rows, new Date(), zone);
  // ⚠️ المقامُ واحدٌ على الأقلّ: أسبوعٌ خالٍ يجعلُ `count / max` قسمةً على صفرٍ
  // فيصيرُ الارتفاعُ `NaN%` — وهو عمودٌ لا يُرسَمُ بصمت.
  const max = Math.max(1, ...days.map((day) => day.sessions.length));
  // ⚠️ اليومُ هو الافتراض، والضغطةُ الثانيةُ على اليومِ المختارِ تعودُ إليه.
  const selectedKey = pinned ?? days[0]?.key ?? null;
  const selectedDay = days.find((day) => day.key === selectedKey) ?? null;

  return (
    <DashboardCard
      title="حصص الأسبوع القادم"
      Icon={ScheduleIcon}
      href={canManage ? "/manage/sessions" : undefined}
      loading={loading}
      error={error}
      onRetry={load}
    >
      {/* الرسمُ زينةٌ والقائمةُ هي البيان: كلُّ يومٍ يحملُ اسمَه وتاريخَه وعددَه
          نصّاً، فلا شيءَ هنا يُقرَأُ بالارتفاعِ وحدَه. */}
      <ol className="grid grid-cols-7 gap-1 sm:gap-1.5">
        {days.map((day, index) => {
          const selected = day.key === selectedKey;
          const busy = day.sessions.length > 0;

          return (
            <li key={day.key} className="min-w-0">
              {/*
                ⚠️ زرٌّ حقيقيّ: قائمةُ اليومِ تحتَه هي الطريقُ الوحيدُ إلى معرفةِ
                **أيّ** حصصٍ فيه، ولوحةٌ لا تُفتَحُ إلّا بمؤشِّرٍ لا وجودَ لها على
                هاتفٍ ولا على لوحةِ مفاتيح.
              */}
              <button
                type="button"
                onClick={() => setPinned((current) => (current === day.key ? null : day.key))}
                aria-pressed={pinned === day.key}
                aria-label={`${weekdayLabel(day.key, zone)} ${dateLabel(day.key, zone)} — ${counted(day.sessions.length, NOUNS.sessions)}`}
                className={`group/day flex w-full flex-col items-center gap-1.5 rounded-2xl border px-0.5 py-2 transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                  selected
                    ? "border-primary bg-primary text-white shadow-md shadow-primary/20"
                    : "border-line text-ink hover:border-primary/40 hover:bg-primary-soft/50"
                }`}
              >
                <span className="w-full whitespace-nowrap text-center text-[10px] font-bold tracking-tight sm:text-[11px]">
                  {index === 0 ? "اليوم" : weekdayLabel(day.key, zone)}
                </span>
                {/* ⚠️ التاريخُ تحتَ الاسمِ لأنّ «السبت» وحدَه يقعُ مرّتَينِ في
                    أسبوعٍ يبدأُ اليوم. */}
                <span className={`text-[10px] ${selected ? "text-white/75" : "text-ink-muted"}`}>
                  <bdi>{dateLabel(day.key, zone)}</bdi>
                </span>
                <span
                  aria-hidden="true"
                  className={`flex h-12 w-2.5 items-end overflow-hidden rounded-full ${selected ? "bg-white/20" : "bg-line/70"}`}
                >
                  <span
                    className={`block w-full rounded-full transition-[height] duration-500 ease-out motion-reduce:transition-none ${selected ? "bg-white" : "bg-primary"}`}
                    style={{ height: `${(day.sessions.length / max) * 100}%` }}
                  />
                </span>
                <span
                  className={`grid h-6 min-w-6 place-items-center rounded-full px-1 text-xs font-extrabold ${
                    selected
                      ? "bg-white text-primary-ink"
                      : busy
                        ? "bg-primary-soft text-primary-ink"
                        : "text-ink-muted"
                  }`}
                >
                  {/* يومٌ بلا حصّةٍ شَرطةٌ لا «٠»: الصفرُ العربيُّ نقطةٌ تُقرأُ كأنّ العددَ لم يُحمَّل. */}
                  <bdi>{day.sessions.length === 0 ? "–" : arabicNumber(day.sessions.length)}</bdi>
                </span>
              </button>
            </li>
          );
        })}
      </ol>

      {/*
        ⚠️ ارتفاعٌ ثابتٌ يتمرّرُ من داخله: اختيارُ يومٍ يُغيِّرُ ما في القائمةِ لا
        طولَ البطاقة، فلا تتحرّكُ الأعمدةُ تحتَ الإصبع. وأسبوعٌ بلا حصّةٍ لا قائمةَ
        له؛ الجملةُ تحتَه تقولُ ذلك.
      */}
      {rows.length > 0 && selectedDay !== null && (
        <div className="mt-4 h-52 overflow-y-auto overscroll-contain rounded-2xl bg-surface p-3">
          <p className="mb-2 flex items-center justify-between gap-2 text-xs font-bold text-ink">
            <span>
              {weekdayLabel(selectedDay.key, zone)} <bdi>{dateLabel(selectedDay.key, zone)}</bdi>
            </span>
            <span className="text-ink-muted">{counted(selectedDay.sessions.length, NOUNS.sessions)}</span>
          </p>

          {selectedDay.sessions.length === 0 ? (
            <p className="text-xs text-ink-muted">لا حصص في هذا اليوم.</p>
          ) : (
            <ul className="space-y-1.5">
              {selectedDay.sessions.map((session) => (
                <li key={session.uuid}>
                  {/* ⚠️ صفحةُ الإدارةِ لمن يُديرُ، وصفحةُ الحصّةِ لمن يقرأُ فقط —
                      و`ClassSessionPolicy::view()` يُجيزُها بـ`sessions.view`. */}
                  <Link
                    href={canManage ? `/manage/sessions/${session.uuid}` : `/sessions/${session.uuid}`}
                    className="group/row flex items-center gap-3 rounded-xl border border-line bg-surface-raised p-2 text-xs transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    <bdi className="shrink-0 rounded-lg bg-primary-soft px-2 py-1 font-extrabold text-primary-ink transition group-hover/row:bg-primary group-hover/row:text-white">
                      {clock(session, zone)}
                    </bdi>
                    <span className="min-w-0">
                      <span className="block truncate font-bold text-ink">{session.title}</span>
                      {/* ⚠️ اسمُ المجموعةِ هو ما يُفرِّقُ ثلاثَ حصصٍ بالعنوانِ نفسِه
                          في يومٍ واحد. وغيابُه جوابٌ صريح — «حصّة بلا مجموعة». */}
                      {session.cohort_name != null && (
                        <span className="block truncate text-[0.625rem] text-ink-muted">
                          {session.cohort_name}
                        </span>
                      )}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {!loading && error === null && rows.length === 0 && (
        <p className="mt-4 text-sm text-ink-muted">
          لا حصص في السبعة أيام القادمة.
          {canManage && " "}
          {canManage && (
          <Link
            href="/manage/sessions"
            className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            أنشئ حصّة
          </Link>
          )}
        </p>
      )}
    </DashboardCard>
  );
}
