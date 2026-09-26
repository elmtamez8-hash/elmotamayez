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
          بـ<bdi>{timezoneLabel(zone)}</bdi>
        </p>
      )}
    </div>
  );
}
