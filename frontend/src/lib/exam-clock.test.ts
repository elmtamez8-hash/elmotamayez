import { describe, expect, it } from "vitest";

import { formatClock, payloadOf, restoreFrom, secondsLeft } from "./exam-clock";

describe("exam clock", () => {
  it("sends every question, a blank as a blank", () => {
    const rows = payloadOf(
      [{ id: 1, type: "mcq" }, { id: 2, type: "mcq" }, { id: 3, type: "essay" }],
      { 1: [10] },
      { 3: "  " },
    );

    expect(rows).toEqual([
      { question_id: 1, selected_option_ids: [10] },
      { question_id: 2, selected_option_ids: [] },
      { question_id: 3, selected_option_ids: [], answer_text: null },
    ]);
  });

  it("puts a reload back exactly where it was", () => {
    const saved = payloadOf([{ id: 1, type: "mcq" }, { id: 3, type: "essay" }], { 1: [10] }, { 3: "جواب" });

    expect(restoreFrom(saved)).toEqual({ answers: { 1: [10], 3: [] }, texts: { 3: "جواب" } });
    expect(restoreFrom(null)).toEqual({ answers: {}, texts: {} });
  });

  it("counts down to the server's deadline and stops at zero", () => {
    const now = Date.parse("2026-10-29T10:00:00Z");

    expect(secondsLeft("2026-10-29T10:01:30Z", now)).toBe(90);
    expect(secondsLeft("2026-10-29T09:59:00Z", now)).toBe(0);
    expect(secondsLeft(null, now)).toBeNull();
  });

  it("shows minutes and seconds, and hours when there are any", () => {
    expect(formatClock(65)).toBe("01:05");
    expect(formatClock(3725)).toBe("1:02:05");
  });
});
