"use client";

import { formatCohortSlot } from "@/lib/session-format";
import { timezoneLabel } from "@/lib/labels";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";

/**
 * When a group meets, on the VISITOR's own clock (owner decision 2026-09-26).
 *
 * ⚠️ `slots` ARE INSTANTS, `labels` ARE THE PLATFORM-ZONE TEXT BESIDE THEM. «السبت
 * ٥م» in Doha is 16:00 in Cairo in winter and 17:00 in summer — only a date says
 * which — so the server sends the next meeting in each slot and this draws its
 * weekday and hour on `useViewerTimeZone()`. On the server render that hook is
 * the platform zone, so the crawled text still reads the platform's hours, with
 * the zone named beneath.
 *
 * A caller with no slots (an older payload, a teacher's screen) passes labels
 * alone and gets them as they are.
 */
export function useCohortSchedule(labels: string[], slots: string[] | undefined): { items: string[]; zone: string | null } {
  const zone = useViewerTimeZone();

  if (slots === undefined || slots.length === 0) return { items: labels, zone: null };

  return { items: slots.map((at) => formatCohortSlot(at, zone)), zone };
}

const WEEK = ["الأحد", "الإثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];
// The calendar's own one-letter names, Sunday first as the Qatari week runs.
const WEEK_INITIALS = ["ح", "ن", "ث", "ر", "خ", "ج", "س"];

/**
 * A group's meetings as a week (owner request 2026-10-11): seven days with the
 * meeting days lit, then each meeting's hour — so «which days, what time» is read
 * in one look instead of from a row of chips.
 *
 * Built from the same `items` as the chips, so the server render (platform-zone
 * labels) and the browser (the visitor's clock) light the same days. An item
 * whose weekday cannot be read is still listed, just not lit.
 */
export function CohortWeek({ labels, slots }: { labels: string[]; slots?: string[] }) {
  const { items, zone } = useCohortSchedule(labels, slots);

  if (items.length === 0) {
    return <p className="text-xs text-ink-muted">لم تُجدول حصص بعد</p>;
  }

  const meetings = items.map((item) => {
    const day = WEEK.findIndex((name) => item.startsWith(name));

    return { day, time: day === -1 ? item : item.slice(WEEK[day].length).trim() };
  });
  const lit = new Set(meetings.map((m) => m.day));

  return (
    <div className="flex flex-col gap-3 rounded-2xl bg-surface p-3.5">
      <ol aria-hidden="true" className="grid grid-cols-7 gap-1">
        {WEEK_INITIALS.map((initial, day) => (
          <li
            key={day}
            className={`grid aspect-square place-items-center rounded-full text-xs font-extrabold ${
              lit.has(day) ? "bg-primary text-white shadow-sm shadow-primary/30" : "text-ink-muted/70"
            }`}
          >
            {initial}
          </li>
        ))}
      </ol>
      <ul className="flex flex-col gap-1.5">
        {meetings.map((m, index) => (
          <li key={index} className="flex items-baseline justify-between gap-3 text-sm">
            <span className="font-bold text-ink">{m.day === -1 ? "موعد" : WEEK[m.day]}</span>
            <bdi className="font-extrabold tabular-nums text-primary-ink">{m.time}</bdi>
          </li>
        ))}
      </ul>
      {zone !== null && (
        <p className="text-[0.7rem] text-ink-muted">
          بتوقيت <bdi>{timezoneLabel(zone)}</bdi>
        </p>
      )}
    </div>
  );
}

export function CohortScheduleSlots({
  labels,
  slots,
  empty = "لم تُجدول حصص بعد",
}: {
  labels: string[];
  slots?: string[];
  empty?: string;
}) {
  const { items, zone } = useCohortSchedule(labels, slots);

  if (items.length === 0) {
    // Never an empty gap: a section with nothing in it reads as a broken page
    // rather than as an answer.
    return <p className="text-xs text-ink-muted">{empty}</p>;
  }

  return (
    <div className="flex flex-col gap-1">
      <ul className="flex flex-wrap gap-2">
        {items.map((slot) => (
          <li
            key={slot}
            className="rounded-lg bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary-ink"
          >
            {slot}
          </li>
        ))}
      </ul>
      {zone !== null && (
        <p className="text-xs text-ink-muted">
          بتوقيت <bdi>{timezoneLabel(zone)}</bdi>
        </p>
      )}
    </div>
  );
}
