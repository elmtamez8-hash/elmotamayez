import { describe, expect, it } from "vitest";

import { formatSessionClock, formatSessionTimeWithZone, sessionDayKey } from "./session-format";
import { localDateTimeToIso } from "./labels";
import { PLATFORM_TIME_ZONE, resolveViewerTimeZone, zonedWallTimeToInstant } from "./timezone";

/*
| Whose clock a time is drawn on (owner decision 2026-09-25): the viewer's own.
|
| ⚠️ ZONES ARE NAMED, NEVER READ FROM THE MACHINE, so each case states concrete
| hours and passes on any machine. Egypt leaves daylight saving on 2026-10-29
| (UTC+3 → UTC+2); Qatar is UTC+3 all year.
*/

const CAIRO = "Africa/Cairo";
const DOHA = "Asia/Qatar";

describe("resolveViewerTimeZone", () => {
  it("prefers the account's stored zone over the browser's", () => {
    expect(resolveViewerTimeZone(CAIRO, DOHA)).toBe(CAIRO);
  });

  it("falls back to the browser's when the account has none", () => {
    expect(resolveViewerTimeZone(null, CAIRO)).toBe(CAIRO);
    expect(resolveViewerTimeZone(undefined, DOHA)).toBe(DOHA);
  });

  it("ignores a name this runtime does not know, rather than throwing in every formatter", () => {
    expect(resolveViewerTimeZone("Mars/Olympus", CAIRO)).toBe(CAIRO);
  });

  it("uses the platform zone only when nothing else is known (the server render)", () => {
    expect(resolveViewerTimeZone(null, null)).toBe(PLATFORM_TIME_ZONE);
  });
});

describe("one session, two viewers", () => {
  // 15:00Z on 2026-11-03 — after Egypt left daylight saving.
  const winter = "2026-11-03T15:00:00Z";
  // 14:00Z on 2026-10-27 — before it did.
  const summer = "2026-10-27T14:00:00Z";

  it("is 17:00 in Cairo and 18:00 in Doha in winter", () => {
    expect(new Date(winter).toLocaleTimeString("en-GB", { timeZone: CAIRO, hour: "2-digit", minute: "2-digit" })).toBe("17:00");
    expect(new Date(winter).toLocaleTimeString("en-GB", { timeZone: DOHA, hour: "2-digit", minute: "2-digit" })).toBe("18:00");

    // The helper draws the same lesson on each viewer's own clock: the Cairo
    // viewer reads the hour they read in summer, the Doha viewer one later.
    expect(formatSessionClock(winter, CAIRO)).toBe(formatSessionClock(summer, CAIRO));
    expect(formatSessionClock(winter, DOHA)).toBe(formatSessionClock("2026-11-03T16:00:00Z", CAIRO));
    expect(formatSessionClock(winter, CAIRO)).not.toBe(formatSessionClock(winter, DOHA));
  });

  it("is 17:00 for both in summer, when the two clocks agree", () => {
    expect(formatSessionClock(summer, CAIRO)).toBe(formatSessionClock(summer, DOHA));
  });

  it("names the clock where two countries read one time", () => {
    expect(formatSessionTimeWithZone(winter, CAIRO)).toContain("توقيت مصر");
    expect(formatSessionTimeWithZone(winter, DOHA)).toContain("توقيت قطر");
  });

  it("puts a late lesson on the right DAY for each viewer", () => {
    // 21:30Z is 23:30 in Cairo (still the 3rd) and 00:30 in Doha (already the 4th).
    expect(sessionDayKey("2026-11-03T21:30:00Z", CAIRO)).toBe("2026-11-03");
    expect(sessionDayKey("2026-11-03T21:30:00Z", DOHA)).toBe("2026-11-04");
  });
});

describe("zonedWallTimeToInstant", () => {
  it("uses the offset THAT DATE has, not today's", () => {
    expect(zonedWallTimeToInstant("2026-10-27", "17:00", CAIRO).toISOString()).toBe("2026-10-27T14:00:00.000Z");
    expect(zonedWallTimeToInstant("2026-11-03", "17:00", CAIRO).toISOString()).toBe("2026-11-03T15:00:00.000Z");
    expect(zonedWallTimeToInstant("2026-11-03", "17:00", DOHA).toISOString()).toBe("2026-11-03T14:00:00.000Z");
  });

  it("reads a typed datetime on the viewer's clock, the same one it is shown back on", () => {
    expect(localDateTimeToIso("2026-11-03T17:00", CAIRO)).toBe("2026-11-03T15:00:00.000Z");
    expect(localDateTimeToIso("2026-11-03T17:00", DOHA)).toBe("2026-11-03T14:00:00.000Z");
  });
});
