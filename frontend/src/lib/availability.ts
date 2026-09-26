import type { AvailabilityItem } from "./public-api";
import { addDaysToKey, clockIn, dateKeyIn, weekdayIn, zonedWallTimeToInstant } from "./timezone";

/**
 * The weekly availability window: WALL-CLOCK time on the teacher's own clock,
 * with that clock named (`timezone`, an IANA name) — since 2026-09-25.
 *
 * ⛔ IT WAS STORED IN UTC, AND A WEEKLY UTC WINDOW CANNOT EXPRESS DAYLIGHT
 * SAVING. The old writer converted with the browser's offset of the CURRENT
 * week, which is exact for Qatar (no DST) and wrong for Egypt twice a year: a
 * Cairo teacher's «Tuesday 17:00» was stored as 14:00Z, and from 2026-10-29
 * (Egypt back to UTC+2) every reader — the public calendar, the request form,
 * the job that puts real lessons on the calendar — put it at 16:00 on their own
 * clock. The row now carries the zone and every reader converts PER DATE.
 *
 * So the editor sends exactly what the teacher typed plus their zone — NO
 * conversion on the way in — and the readers below convert a window into
 * instants on a given date, in the window's own zone.
 *
 * ⚠️ A SLOT WITH NO `timezone` IS AN OLD UTC PAYLOAD, not a Qatar one. Public
 * pages are cached, so for a while a response from before the change can reach
 * a browser after it; reading its hours as UTC is what that response meant.
 */

type Slot = Pick<AvailabilityItem, "day_of_week" | "start_time" | "end_time"> & { timezone?: string | null };

export function slotZone(slot: Slot): string {
  return slot.timezone || "UTC";
}

/** The weekday (0 = Sunday) of a `YYYY-MM-DD` key — a calendar fact, no zone. */
function weekdayOfKey(dateKey: string): number {
  const [year, month, day] = dateKey.split("-").map(Number);

  return new Date(Date.UTC(year, month - 1, day)).getUTCDay();
}

/**
 * This window's occurrence on a DATE of its own zone's calendar, as instants.
 * `null` when the date is another weekday.
 */
export function occurrenceOn(slot: Slot, dateKey: string): { start: Date; end: Date } | null {
  if (weekdayOfKey(dateKey) !== slot.day_of_week) return null;

  const zone = slotZone(slot);

  return {
    start: zonedWallTimeToInstant(dateKey, slot.start_time.slice(0, 5), zone),
    end: zonedWallTimeToInstant(dateKey, slot.end_time.slice(0, 5), zone),
  };
}

/**
 * A window as a VIEWER in `viewerZone` reads it this week: «Tuesday 17:00 Cairo»
 * is «Tuesday 18:00» in Doha in winter and «Tuesday 17:00» in summer.
 *
 * ⚠️ ANCHORED ON THE WEEK CONTAINING `from`, and that is the honest answer for a
 * weekly grid: the two zones' difference is a property of the date. The request
 * form, which offers real dates, uses `startsWithin()` and is exact per date.
 *
 * The weekday moves with the clock: 01:00 Monday in Doha is 23:00 Sunday in
 * Cairo in winter — so the day is read back off the converted instant.
 */
export function toViewerSlot(slot: Slot, viewerZone: string, from: Date = new Date()): AvailabilityItem {
  const zone = slotZone(slot);
  const todayKey = dateKeyIn(from, zone);
  const sunday = addDaysToKey(todayKey, -weekdayOfKey(todayKey));
  const occurrence = occurrenceOn(slot, addDaysToKey(sunday, slot.day_of_week));

  if (occurrence === null) {
    return { day_of_week: slot.day_of_week, start_time: slot.start_time.slice(0, 5), end_time: slot.end_time.slice(0, 5) };
  }

  return {
    day_of_week: weekdayIn(occurrence.start, viewerZone),
    start_time: clockIn(occurrence.start, viewerZone),
    end_time: clockIn(occurrence.end, viewerZone),
  };
}

/**
 * Every start a student could ask for: each declared window, cut into slots that
 * FIT — the whole duration, never merely its first minute (FR-016ب).
 *
 * ⚠️ BUILT PER DATE IN THE WINDOW'S OWN ZONE. `RequestPrivateSession` checks the
 * ask the same way (`AvailabilitySlot::containsSpan()`), so an hour offered here
 * is an hour the server accepts — on both sides of a DST change.
 */
export function startsWithin(windows: Slot[], minutes: number, from: Date, weeks = 2): Date[] {
  const out: Date[] = [];

  for (const window of windows) {
    const zone = slotZone(window);
    const firstKey = dateKeyIn(from, zone);

    for (let day = 0; day <= weeks * 7; day += 1) {
      const occurrence = occurrenceOn(window, addDaysToKey(firstKey, day));

      if (occurrence === null) continue;

      for (
        let slot = occurrence.start.getTime();
        slot + minutes * 60_000 <= occurrence.end.getTime();
        slot += minutes * 60_000
      ) {
        if (slot > from.getTime()) out.push(new Date(slot));
      }
    }
  }

  return out.sort((a, b) => a.getTime() - b.getTime());
}

/**
 * The first row whose start or end was cleared, or −1.
 *
 * ⚠️ A CLEARED `<input type="time">` IS `""`, AND IT WOULD BE STORED AS 00:00.
 * So a teacher who emptied a field to retype it and saved in between stored a
 * window starting — or ending — at midnight, which the server accepts as a
 * perfectly valid time. Every caller asks this BEFORE sending, and says so in
 * words instead of saving an hour nobody chose.
 */
export function blankTimeIndex(slots: Slot[]): number {
  return slots.findIndex((slot) => slot.start_time.trim() === "" || slot.end_time.trim() === "");
}

/** The sentence both editors show for a cleared time. */
export const BLANK_TIME_MESSAGE = "أكمل وقتَي البداية والنهاية في كلّ فترة قبل الحفظ.";

/**
 * The first row that ends at or before it starts, or −1.
 *
 * A row is one day on the teacher's clock, so a window past midnight cannot be
 * one row — asked here so the form can say «split it» instead of relaying the
 * server's refusal.
 */
export function endsBeforeStartIndex(slots: Slot[]): number {
  return slots.findIndex((slot) => slot.end_time.slice(0, 5) <= slot.start_time.slice(0, 5));
}

/** The sentence both editors show for a window past midnight. */
export const PAST_MIDNIGHT_MESSAGE =
  "وقت النهاية يجب أن يكون بعد وقت البداية. لفترةٍ تمتدّ بعد منتصف الليل قسّمها إلى فترتين: واحدة تنتهي 23:59 وأخرى تبدأ 00:00.";
