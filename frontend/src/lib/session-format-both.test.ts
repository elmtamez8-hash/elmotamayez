import { describe, expect, it } from "vitest";

import { formatCohortSlot, formatSessionClock, formatSessionTimeBoth } from "./session-format";

/*
| Two clocks on one line, and a group's slot on the reader's clock (owner
| decisions 2026-09-26). Zones are named, never read from the machine: Egypt is
| UTC+3 until 2026-10-29 and UTC+2 after; Qatar is UTC+3 all year.
*/

const CAIRO = "Africa/Cairo";
const DOHA = "Asia/Qatar";

describe("formatSessionTimeBoth", () => {
  it("prints both hours when the two clocks differ at that instant", () => {
    const winter = "2026-11-03T15:00:00Z"; // 17:00 Cairo · 18:00 Doha
    const line = formatSessionTimeBoth(winter, CAIRO, DOHA, "المدرّس");

    expect(line).toContain("بتوقيتك");
    expect(line).toContain(`${formatSessionClock(winter, DOHA)} بتوقيت المدرّس`);
  });

  it("prints ONE time, zone named, when the two names share an hour (summer)", () => {
    const summer = "2026-10-27T14:00:00Z"; // 17:00 in both
    const line = formatSessionTimeBoth(summer, CAIRO, DOHA, "المدرّس");

    expect(line).not.toContain("بتوقيتك");
    expect(line).toContain("توقيت مصر");
  });

  it("prints one time when the other clock is unknown or the same", () => {
    expect(formatSessionTimeBoth("2026-11-03T15:00:00Z", CAIRO, null, "الطالب")).not.toContain("بتوقيتك");
    expect(formatSessionTimeBoth("2026-11-03T15:00:00Z", CAIRO, CAIRO, "الطالب")).not.toContain("بتوقيتك");
  });
});

describe("formatCohortSlot", () => {
  it("draws a Doha group's Saturday 17:00 on each reader's clock, weekday included", () => {
    const at = "2026-11-07T14:00:00Z"; // Saturday 17:00 Doha · 16:00 Cairo

    expect(formatCohortSlot(at, DOHA)).toContain("السبت");
    expect(formatCohortSlot(at, DOHA)).toContain(formatSessionClock(at, DOHA));
    expect(formatCohortSlot(at, CAIRO)).toContain(formatSessionClock(at, CAIRO));
    expect(formatCohortSlot(at, CAIRO)).not.toBe(formatCohortSlot(at, DOHA));
  });

  it("moves the weekday when the hour crosses midnight between the clocks", () => {
    const at = "2026-11-07T21:30:00Z"; // Sunday 00:30 in Doha · Saturday 23:30 in Cairo

    expect(formatCohortSlot(at, DOHA)).toContain("الأحد");
    expect(formatCohortSlot(at, CAIRO)).toContain("السبت");
  });
});
