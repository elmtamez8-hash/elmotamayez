import { describe, expect, it } from "vitest";

import { nextDaySlot, sameDaySlot } from "./TeacherSignupWizard";

/*
| THE WEEKLY TIMETABLE'S «ADD» BUTTON, WHICH USED TO APPEND A CONSTANT.
|
| Every new row was `{day: 1, 16:00–18:00}` — Monday, whatever day the teacher
| had reached, and two times they had to retype on every row. Availability is
| the same hours repeated across days far more often than it is seven different
| ones, so the previous row is the best guess there is.
|
| ⚠️ THE SAME-DAY BUTTON IS THE ONE THAT CAN SHIP BROKEN AND LOOK FINE.
| `SetAvailability` refuses two overlapping periods on one day, so a second row
| copying the first one's times verbatim is an «add» whose output is invalid on
| arrival — the teacher presses a button and is answered with a refusal it
| created. Its comparison is strict, so back-to-back passes: the new period has
| to START where the previous one ENDED.
*/
describe("nextDaySlot", () => {
  it("keeps the hours and moves to the next day", () => {
    expect(nextDaySlot({ day_of_week: 0, start_time: "09:00", end_time: "11:00" })).toEqual({
      day_of_week: 1,
      start_time: "09:00",
      end_time: "11:00",
    });
  });

  it("wraps Saturday back to Sunday", () => {
    // Never day 7: the select has no such option and the API validates
    // `between:0,6`.
    expect(nextDaySlot({ day_of_week: 6, start_time: "16:00", end_time: "18:00" }).day_of_week).toBe(0);
  });
});

describe("sameDaySlot", () => {
  it("starts where the previous period ended, on the same day", () => {
    expect(sameDaySlot({ day_of_week: 2, start_time: "09:00", end_time: "11:00" })).toEqual({
      day_of_week: 2,
      start_time: "11:00",
      end_time: "13:00",
    });
  });

  it("never overlaps what it follows", () => {
    const first = { day_of_week: 3, start_time: "08:30", end_time: "09:45" };
    const second = sameDaySlot(first);

    expect(second.start_time >= first.end_time).toBe(true);
    expect(second.end_time > second.start_time).toBe(true);
  });

  it("clamps at the end of the day rather than rolling past midnight", () => {
    // 22:00–23:30 + 90 minutes is 01:00 tomorrow — a time `date_format:H:i`
    // accepts and the teacher never meant.
    expect(sameDaySlot({ day_of_week: 4, start_time: "22:00", end_time: "23:30" })).toEqual({
      day_of_week: 4,
      start_time: "23:30",
      end_time: "23:59",
    });
  });
});
