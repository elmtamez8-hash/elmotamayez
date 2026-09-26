import { describe, expect, it } from "vitest";

import { blankTimeIndex, endsBeforeStartIndex, startsWithin, toViewerSlot } from "./availability";
import type { AvailabilityItem } from "./public-api";

/*
| The weekly window: wall-clock hours on the teacher's clock, with the clock
| named (2026-09-25).
|
| ⚠️ EVERY ASSERTION NAMES ITS ZONES, AND NONE READS THE MACHINE'S. The old
| suite asserted a round trip because the conversion used the browser's offset
| and a literal hour could pass only on one machine. Now both ends of every
| conversion are named zones, so the cases can state concrete hours — which is
| the only way to see the DST defect this replaced: Egypt leaves daylight saving
| on 2026-10-29 (UTC+3 → UTC+2) and Qatar never has it (UTC+3 all year).
*/

const CAIRO = "Africa/Cairo";
const DOHA = "Asia/Qatar";

function cairo(day: number, start: string, end: string): AvailabilityItem {
  return { day_of_week: day, start_time: start, end_time: end, timezone: CAIRO };
}

describe("a Cairo teacher's Tuesday 17:00, offered as real dates", () => {
  const TUESDAY_17 = [cairo(2, "17:00", "18:00")];

  it("is 17:00 Cairo — 14:00Z — on the last Tuesday of summer time", () => {
    const [first] = startsWithin(TUESDAY_17, 60, new Date("2026-10-26T00:00:00Z"), 1);

    expect(first.toISOString()).toBe("2026-10-27T14:00:00.000Z");
  });

  it("is STILL 17:00 Cairo — now 15:00Z — on the first Tuesday after 2026-10-29", () => {
    /*
     | ⚠️ THE WHOLE DEFECT IN ONE LINE. A window stored as «Tuesday 14:00 UTC»
     | answers 14:00Z here too — 16:00 on the teacher's own clock. Per-date
     | conversion in the window's zone answers 15:00Z.
     */
    const [first] = startsWithin(TUESDAY_17, 60, new Date("2026-11-02T00:00:00Z"), 1);

    expect(first.toISOString()).toBe("2026-11-03T15:00:00.000Z");
  });

  it("offers only starts whose WHOLE duration fits", () => {
    const starts = startsWithin([cairo(2, "17:00", "18:30")], 45, new Date("2026-11-02T00:00:00Z"), 1);

    expect(starts.map((at) => at.toISOString())).toEqual([
      "2026-11-03T15:00:00.000Z",
      "2026-11-03T15:45:00.000Z",
    ]);
  });

  it("reads a slot with no zone as the UTC payload it came from", () => {
    // A public page cached from before the change carries UTC hours and no zone.
    const [first] = startsWithin([{ day_of_week: 2, start_time: "14:00", end_time: "15:00" }], 60, new Date("2026-11-02T00:00:00Z"), 1);

    expect(first.toISOString()).toBe("2026-11-03T14:00:00.000Z");
  });
});

describe("toViewerSlot — one window, two readers", () => {
  const window = cairo(2, "17:00", "18:00");

  it("is 17:00 to Cairo and 17:00 to Doha while Egypt is on summer time", () => {
    const october = new Date("2026-10-20T12:00:00Z");

    expect(toViewerSlot(window, CAIRO, october)).toEqual({ day_of_week: 2, start_time: "17:00", end_time: "18:00" });
    expect(toViewerSlot(window, DOHA, october)).toEqual({ day_of_week: 2, start_time: "17:00", end_time: "18:00" });
  });

  it("is still 17:00 to Cairo and becomes 18:00 to Doha in winter", () => {
    const november = new Date("2026-11-10T12:00:00Z");

    expect(toViewerSlot(window, CAIRO, november)).toEqual({ day_of_week: 2, start_time: "17:00", end_time: "18:00" });
    expect(toViewerSlot(window, DOHA, november)).toEqual({ day_of_week: 2, start_time: "18:00", end_time: "19:00" });
  });

  it("moves the weekday when the hour crosses midnight between the two clocks", () => {
    // 00:30 Wednesday in Doha is 23:30 Tuesday in Cairo in winter.
    const late = { day_of_week: 3, start_time: "00:30", end_time: "01:00", timezone: DOHA };

    expect(toViewerSlot(late, CAIRO, new Date("2026-11-10T12:00:00Z"))).toEqual({
      day_of_week: 2,
      start_time: "23:30",
      end_time: "00:00",
    });
  });
});

describe("a window the editor cannot send", () => {
  it("finds a window that ends at or before it starts — one past midnight", () => {
    expect(endsBeforeStartIndex([cairo(1, "16:00", "18:00"), cairo(2, "22:00", "00:30")])).toBe(1);
    expect(endsBeforeStartIndex([cairo(1, "16:00", "18:00")])).toBe(-1);
  });
});

describe("a cleared time", () => {
  it("is found before it can be sent as midnight", () => {
    const week: AvailabilityItem[] = [
      { day_of_week: 0, start_time: "09:00", end_time: "11:00" },
      { day_of_week: 1, start_time: "", end_time: "11:00" },
    ];

    expect(blankTimeIndex(week)).toBe(1);
    expect(blankTimeIndex([week[0]])).toBe(-1);
    expect(blankTimeIndex([{ day_of_week: 2, start_time: "09:00", end_time: " " }])).toBe(0);
  });
});
