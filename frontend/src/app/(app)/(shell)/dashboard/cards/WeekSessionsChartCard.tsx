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
function buckets(rows: ClassSession[], now: Date): Array<{ key: string; sessions: ClassSession[] }> {
  const zone = rows[0]?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;

  const days = Array.from({ length: DAYS }, (_, index) => ({
    key: sessionDayKey(new Date(now.getTime() + index * 86_400_000).toISOString(), zone),
    sessions: [] as ClassSession[],
  }));

  for (const session of rows) {
    const day = days.find((entry) => entry.key === sessionDayKey(session.starts_at, session.timezone));

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

/** «١٠/٠٩» — اليومُ والشهرُ بلا سنة: الأسبوعُ القادمُ لا يعبرُ سنتَين. */
function dateLabel(dayKey: string, timeZone: string): string {
  return new Date(`${dayKey}T12:00:00Z`).toLocaleDateString("ar", {
    day: "2-digit",
    month: "2-digit",
    timeZone,
  });
}

function clock(session: ClassSession): string {
  return new Date(session.starts_at).toLocaleTimeString("ar", {
    hour: "2-digit",
    minute: "2-digit",
    timeZone: session.timezone,
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
  const { hostUuid, managesWorkspace } = teacherSessionsAudience(user);
  const shown = hostUuid !== null || managesWorkspace;

  const [rows, setRows] = useState<ClassSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  /*
   * ⚠️ حالتانِ لا واحدة، ولمسُ الهاتفِ هو السبب. المرورُ (`hovered`) عابرٌ يزولُ
   * بمغادرةِ المؤشِّر؛ والضغطُ (`pinned`) يثبت. وهاتفٌ لا مؤشِّرَ له يُطلِقُ
   * `mouseenter` **مرّةً** مع اللمسةِ ثمّ لا يُطلِقُ `mouseleave` أبداً — فحالةٌ
   * واحدةٌ تعني لوحةً تُفتَحُ باللمسِ ولا تُغلَقُ إلّا بلمسِ يومٍ آخر، بلا أيِّ
   * طريقٍ إلى الإغلاق.
   */
  const [hovered, setHovered] = useState<string | null>(null);
  const [pinned, setPinned] = useState<string | null>(null);

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
  const max = Math.max(1, ...days.map((day) => day.sessions.length));
  const zone = rows[0]?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;
  const shownKey = pinned ?? hovered;
  const shownDay = days.find((day) => day.key === shownKey) ?? null;

  return (
    <DashboardCard
      title="حصص الأسبوع القادم"
      Icon={ScheduleIcon}
      href="/manage/sessions"
      loading={loading}
      error={error}
      onRetry={load}
    >
      {/* الرسمُ زينةٌ والقائمةُ هي البيان: كلُّ عمودٍ يحملُ اسمَ يومِه وعددَه
          نصّاً، فلا شيءَ هنا يُقرَأُ بالارتفاعِ وحدَه. */}
      <ol className="flex items-end justify-between gap-2" style={{ height: "9.5rem" }}>
        {days.map((day) => (
          <li key={day.key} className="flex h-full flex-1 flex-col justify-end">
            {/*
              ⚠️ زرٌّ حقيقيّ، لا `div` عليه `onMouseEnter`. اللوحةُ تحتَه هي
              الطريقُ الوحيدُ إلى معرفةِ **أيّ** حصصٍ في اليوم، ولوحةٌ لا تُفتَحُ
              إلّا بمؤشِّرٍ لا وجودَ لها على هاتفٍ ولا على لوحةِ مفاتيح.
            */}
            <button
              type="button"
              onMouseEnter={() => setHovered(day.key)}
              onMouseLeave={() => setHovered(null)}
              onFocus={() => setHovered(day.key)}
              onBlur={() => setHovered(null)}
              onClick={() => setPinned((current) => (current === day.key ? null : day.key))}
              aria-pressed={pinned === day.key}
              aria-label={`${weekdayLabel(day.key, zone)} ${dateLabel(day.key, zone)} — ${arabicNumber(day.sessions.length)} حصة`}
              className={`flex h-full w-full flex-col items-center justify-end gap-1 rounded-lg p-1 transition hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                shownKey === day.key ? "bg-primary-soft" : ""
              }`}
            >
              <span className="text-xs text-ink-muted">
                <bdi>{arabicNumber(day.sessions.length)}</bdi>
              </span>
              <div
                className="w-full rounded-t bg-primary"
                style={{ height: `${(day.sessions.length / max) * 100}%`, minHeight: "2px" }}
                aria-hidden="true"
              />
              <span className="text-xs text-ink-muted">{weekdayLabel(day.key, zone)}</span>
              {/* ⚠️ التاريخُ تحتَ الاسمِ لأنّ «السبت» وحدَه يقعُ مرّتَينِ في أسبوعٍ
                  يبدأُ اليوم: سبتُ الغدِ وسبتُ الأسبوعِ القادمِ عمودانِ بالاسمِ
                  نفسِه، ولا شيءَ في الرسمِ يقولُ أيُّهما أيّ. */}
              <span className="text-[0.625rem] text-ink-muted">
                <bdi>{dateLabel(day.key, zone)}</bdi>
              </span>
            </button>
          </li>
        ))}
      </ol>

      {shownDay !== null && (
        <div className="mt-3 rounded-xl border border-line bg-primary-soft p-3">
          <p className="mb-2 text-xs font-bold text-ink">
            {weekdayLabel(shownDay.key, zone)} <bdi>{dateLabel(shownDay.key, zone)}</bdi>
          </p>

          {shownDay.sessions.length === 0 ? (
            <p className="text-xs text-ink-muted">لا حصص في هذا اليوم.</p>
          ) : (
            <ul className="flex flex-col gap-1.5">
              {shownDay.sessions.map((session) => (
                <li key={session.uuid} className="flex items-baseline justify-between gap-3 text-xs">
                  <Link
                    href={`/manage/sessions/${session.uuid}`}
                    className="truncate rounded text-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    {session.title}
                  </Link>
                  <bdi className="shrink-0 font-bold text-primary-ink">{clock(session)}</bdi>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

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
