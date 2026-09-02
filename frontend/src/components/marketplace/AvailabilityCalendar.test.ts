import { describe, expect, it } from "vitest";

import { formatDays, formatDuration, partOfDay, slotMinutes } from "./AvailabilityCalendar";

/*
| THE THREE PURE PIECES OF THE WEEKLY TIMETABLE.
|
| The rendering around them is a grid; these are the parts that can be WRONG
| rather than ugly — a slot labelled «ليلاً» that a parent reads as «مساءً», or a
| week total that says «2 ساعة» in a language that has a dual.
*/
describe("partOfDay", () => {
  it("reads the START of the slot, not its end", () => {
    // 17:00–21:00 is an evening lesson to the person deciding whether they can
    // attend after school. Reading the end would relabel it the moment a
    // teacher extends the slot by an hour.
    expect(partOfDay("17:00").label).toBe("مساءً");
  });

  it("names each part of the day at its boundary", () => {
    expect(partOfDay("00:00").label).toBe("صباحاً");
    expect(partOfDay("11:59").label).toBe("صباحاً");
    expect(partOfDay("12:00").label).toBe("ظهراً");
    expect(partOfDay("16:59").label).toBe("ظهراً");
    expect(partOfDay("21:00").label).toBe("ليلاً");
  });
});

describe("slotMinutes", () => {
  it("measures the span", () => {
    expect(slotMinutes({ start_time: "09:00", end_time: "11:30" })).toBe(150);
  });

  it("clamps a reversed span to zero rather than reporting a negative week", () => {
    expect(slotMinutes({ start_time: "18:00", end_time: "16:00" })).toBe(0);
  });
});

describe("formatDuration", () => {
  it("uses the Arabic dual", () => {
    // «٢ ساعة» is wrong in a way «2 hours» never is.
    expect(formatDuration(60)).toBe("ساعة");
    expect(formatDuration(120)).toBe("ساعتان");
    expect(formatDuration(180)).toBe("3 ساعات");
  });

  it("returns to the singular from eleven upwards", () => {
    // The band an English-shaped plural always gets wrong — and the one a
    // weekly total lands in. «12 ساعات» is not Arabic.
    expect(formatDuration(600)).toBe("10 ساعات");
    expect(formatDuration(660)).toBe("11 ساعة");
    expect(formatDuration(720)).toBe("12 ساعة");
  });

  it("says the half hour as a word", () => {
    expect(formatDuration(90)).toBe("ساعة ونصف");
    expect(formatDuration(150)).toBe("ساعتان ونصف");
    expect(formatDuration(30)).toBe("نصف ساعة");
  });

  it("falls back to minutes when there is no whole hour", () => {
    expect(formatDuration(45)).toBe("45 دقيقة");
    expect(formatDuration(75)).toBe("ساعة و15 دقيقة");
  });
});

describe("formatDays", () => {
  it("uses the singular and the dual before the plural", () => {
    // «1 أيام» is the same mistake as «12 ساعات», one band along.
    expect(formatDays(1)).toBe("يوم واحد");
    expect(formatDays(2)).toBe("يومان");
    expect(formatDays(3)).toBe("3 أيام");
    expect(formatDays(7)).toBe("7 أيام");
  });
});
