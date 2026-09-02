import type { AvailabilityItem } from "./public-api";

/**
 * The weekly availability window, in the two directions it has to travel.
 *
 * ⚠️ `availability_slots` STORES UTC, AND FIVE READERS ALREADY SAID SO:
 * `AvailabilitySlot::coversUtc()`, `GenerateSessionsFromAvailability` (which
 * parses the stored string with an explicit `'UTC'`), `PublicTeacherDetailResource`,
 * `toLocalSlot` below, and `RequestPrivateSession`. What was missing was the
 * WRITER: the signup wizard sent the raw value of an `<input type="time">` with
 * no conversion at all, so a teacher in Qatar typing 16:00 stored `16:00` and
 * every one of those readers understood 19:00. The hour they declared and the
 * hour the product offered were three apart, their own editor and their own
 * public page showed different times for the same row, and
 * `GenerateSessionsFromAvailability` put real lessons on the calendar at the
 * wrong one.
 *
 * ⚠️ THE TWO DIRECTIONS LIVE IN ONE FILE ON PURPOSE. A conversion written once
 * at the reader and once at the writer is two spellings of one rule, and the day
 * they disagree is the state this replaced. `availability.test.ts` asserts the
 * ROUND TRIP rather than either half — a test naming a concrete offset would
 * pass only on a machine in that zone and be deleted by whoever ran it elsewhere.
 *
 * ⚠️ AND THE WEEKDAY MOVES WITH THE CLOCK. One in the morning on Monday in Qatar
 * is ten on Sunday night in UTC: a conversion that shifts the hour and leaves
 * `day_of_week` alone is wrong only for the slots near midnight and looks correct
 * everywhere else, which is worse than the defect it replaces. Both functions
 * read the day back off the converted Date.
 */

/**
 * The Sunday of the CURRENT week, in local or UTC terms.
 *
 * ⚠️ THE ANCHOR IS TODAY, NOT A FIXED DATE, AND THIS MACHINE IS WHY. A weekly
 * UTC window cannot express a zone that observes DST — the wall-clock hour moves
 * twice a year and the stored row does not — so whichever anchor is chosen is
 * right for one half of the year. Measured 2026-09-02: this machine reported
 * offset −120 for a January date and −180 for a September one, so a hard-coded
 * January anchor would have converted every slot an hour out for the whole
 * summer. Anchoring on the current week makes the conversion right for the
 * teacher editing it now and drifts only at the next changeover, which is the
 * best a model with no zone column can do. Qatar has no DST, so for the declared
 * platform timezone this is exact.
 */
function anchorSunday(day: number, utc: boolean, from: Date): Date {
  const base = new Date(from);
  const today = utc ? base.getUTCDay() : base.getDay();

  if (utc) {
    base.setUTCDate(base.getUTCDate() - today + day);
  } else {
    base.setDate(base.getDate() - today + day);
  }

  return base;
}

function hhmm(value: string): [number, number] {
  const [hours, minutes] = value.split(":").map(Number);

  return [hours || 0, minutes || 0];
}

function pad(value: number): string {
  return String(value).padStart(2, "0");
}

/** Stored UTC → the reader's own clock. */
export function toLocalSlot(slot: AvailabilityItem, from: Date = new Date()): AvailabilityItem {
  const [startHour, startMinute] = hhmm(slot.start_time);
  const [endHour, endMinute] = hhmm(slot.end_time);

  const start = anchorSunday(slot.day_of_week, true, from);
  start.setUTCHours(startHour, startMinute, 0, 0);

  const end = anchorSunday(slot.day_of_week, true, from);
  end.setUTCHours(endHour, endMinute, 0, 0);

  return {
    day_of_week: start.getDay(),
    start_time: `${pad(start.getHours())}:${pad(start.getMinutes())}`,
    end_time: `${pad(end.getHours())}:${pad(end.getMinutes())}`,
  };
}

/** What the teacher typed → the UTC the column holds. */
export function toUtcSlot(slot: AvailabilityItem, from: Date = new Date()): AvailabilityItem {
  const [startHour, startMinute] = hhmm(slot.start_time);
  const [endHour, endMinute] = hhmm(slot.end_time);

  // Local setters, UTC accessors — the exact mirror of the function above.
  const start = anchorSunday(slot.day_of_week, false, from);
  start.setHours(startHour, startMinute, 0, 0);

  const end = anchorSunday(slot.day_of_week, false, from);
  end.setHours(endHour, endMinute, 0, 0);

  return {
    day_of_week: start.getUTCDay(),
    start_time: `${pad(start.getUTCHours())}:${pad(start.getUTCMinutes())}`,
    end_time: `${pad(end.getUTCHours())}:${pad(end.getUTCMinutes())}`,
  };
}

/**
 * Whether this window, once converted, would straddle midnight in UTC.
 *
 * ⚠️ SUCH A WINDOW CANNOT BE STORED AT ALL, AND THE SERVER SAYS SO IN WORDS THE
 * TEACHER DID NOT EARN. One row carries a weekday and two clock times, so a
 * window running 01:00–02:00 on Monday in Qatar becomes 22:00 Sunday to 23:00
 * Sunday — fine — while 01:00–03:00 becomes 22:00 to 00:00, and
 * `SetAvailability::assertNoOverlaps` refuses it with «وقت النهاية يجب أن يكون
 * بعد وقت البداية» about times the teacher never typed.
 *
 * Asked here so the form can say the true thing — split the window — instead of
 * relaying a sentence about a value it invented.
 */
export function crossesUtcMidnight(slot: AvailabilityItem, from: Date = new Date()): boolean {
  const utc = toUtcSlot(slot, from);

  return utc.end_time <= utc.start_time;
}
