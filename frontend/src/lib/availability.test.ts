import { describe, expect, it } from "vitest";

import { crossesUtcMidnight, toLocalSlot, toUtcSlot } from "./availability";
import type { AvailabilityItem } from "./public-api";

/*
| The weekly window, both ways.
|
| ⚠️ THE ROUND TRIP IS THE ASSERTION, NOT EITHER HALF. A test checking «16:00
| becomes 13:00» has to name an offset, so it passes only on a machine in that
| zone and is deleted by whoever first runs the suite somewhere else. A round trip
| is true in every zone and false for every half-conversion: shift the hour and
| leave `day_of_week` alone and it fails on exactly the slots near midnight, which
| is the defect that shape produces.
|
| ⚠️ AND A UTC MACHINE CANNOT SEE THE BUG AT ALL — there the round trip holds over
| a pair of functions that both do nothing. So the offset decides what may be
| claimed, and the cases that need a real offset say so.
*/

/** A Wednesday in September, so the anchor is fixed for every case below. */
const FROM = new Date("2026-09-02T12:00:00Z");

const offsetMinutes = FROM.getTimezoneOffset();

function slot(day: number, start: string, end: string): AvailabilityItem {
  return { day_of_week: day, start_time: start, end_time: end };
}

describe("availability conversion", () => {
  it("returns what it was given, all the way around the week", () => {
    for (let day = 0; day < 7; day += 1) {
      for (const [start, end] of [
        ["00:00", "01:00"],
        ["08:30", "12:00"],
        ["16:00", "18:00"],
        ["22:45", "23:59"],
      ]) {
        const original = slot(day, start, end);

        expect(toUtcSlot(toLocalSlot(original, FROM), FROM)).toEqual(original);
        expect(toLocalSlot(toUtcSlot(original, FROM), FROM)).toEqual(original);
      }
    }
  });

  it("keeps the length of the window", () => {
    const minutes = (s: AvailabilityItem) =>
      Number(s.end_time.slice(0, 2)) * 60 + Number(s.end_time.slice(3))
      - (Number(s.start_time.slice(0, 2)) * 60 + Number(s.start_time.slice(3)));

    for (let day = 0; day < 7; day += 1) {
      // 150 minutes is 150 minutes in every zone. A conversion that dropped the
      // minutes, or applied the offset to one end only, changes it.
      expect(minutes(toUtcSlot(slot(day, "09:15", "11:45"), FROM))).toBe(150);
      expect(minutes(toLocalSlot(slot(day, "09:15", "11:45"), FROM))).toBe(150);
    }
  });

  it("moves the weekday when the hour crosses midnight", () => {
    if (offsetMinutes === 0) {
      // A UTC machine cannot show this, and asserting it here would be asserting
      // the clock rather than the code. The round trip above still covers it.
      expect(toUtcSlot(slot(1, "01:00", "02:00"), FROM)).toEqual(slot(1, "01:00", "02:00"));

      return;
    }

    // Ahead of UTC (Qatar, +3): 01:00 Monday local is the previous day in UTC.
    // Behind it (New York, −5): 22:00 Sunday local is the next day in UTC.
    const near = offsetMinutes < 0 ? slot(1, "01:00", "01:30") : slot(0, "22:30", "23:00");

    expect(toUtcSlot(near, FROM).day_of_week).not.toBe(near.day_of_week);
  });
});

describe("crossesUtcMidnight", () => {
  it("says no to an ordinary afternoon window", () => {
    expect(crossesUtcMidnight(slot(2, "16:00", "18:00"), FROM)).toBe(false);
  });

  it("catches the window the database cannot hold", () => {
    if (offsetMinutes === 0) {
      // With no offset nothing can straddle, and a window is never rejected.
      expect(crossesUtcMidnight(slot(1, "01:00", "03:00"), FROM)).toBe(false);

      return;
    }

    /*
     | ⚠️ ONE ROW CARRIES A WEEKDAY AND TWO CLOCK TIMES, so a window whose UTC end
     | lands past midnight cannot be written at all — `SetAvailability` refuses
     | `end_time <= start_time`. Without this predicate the teacher is shown a
     | server message about times they never typed.
     |
     | Built from the offset rather than from a literal hour: «the window that
     | ends just after UTC midnight» is a different local time in every zone.
     */
    const ahead = offsetMinutes < 0;
    const offsetHours = Math.abs(offsetMinutes) / 60;

    const straddling = ahead
      // +3 ⇒ local 00:00–03:00 Monday is 21:00 Sunday → 00:00 Monday.
      ? slot(1, "00:00", `${String(offsetHours).padStart(2, "0")}:00`)
      // −5 ⇒ local 19:00–23:00 Sunday is 00:00 Monday → 04:00 Monday.
      : slot(0, `${String(24 - offsetHours).padStart(2, "0")}:00`, "23:00");

    expect(crossesUtcMidnight(straddling, FROM)).toBe(true);
  });
});
