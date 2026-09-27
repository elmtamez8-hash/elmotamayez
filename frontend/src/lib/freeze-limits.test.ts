import { describe, expect, it } from "vitest";

import { freezeLimitsHint } from "./class-sessions";

describe("freezeLimitsHint", () => {
  it("states the two limits the server enforces, in Arabic numerals", () => {
    expect(freezeLimitsHint({ max_days: 30, max_per_month: 2 })).toBe(
      "لا تزيد الفترة الواحدة على ٣٠ يوماً، ولا تبدأ في الشهر الواحد أكثر من فترتين.",
    );
  });

  it("reads correctly when an operator changes either number", () => {
    expect(freezeLimitsHint({ max_days: 2, max_per_month: 1 })).toBe(
      "لا تزيد الفترة الواحدة على يومين، ولا تبدأ في الشهر الواحد أكثر من فترة واحدة.",
    );
    expect(freezeLimitsHint({ max_days: 10, max_per_month: 3 })).toBe(
      "لا تزيد الفترة الواحدة على ١٠ أيام، ولا تبدأ في الشهر الواحد أكثر من ٣ فترات.",
    );
  });
});
