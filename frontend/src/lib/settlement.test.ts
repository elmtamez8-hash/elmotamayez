import { describe, expect, it } from "vitest";

import { toMinorUnits } from "./settlement";

/*
| THE ONE CONVERSION ON THE MONEY PATH, AND THE OBVIOUS SPELLING IS THE WRONG ONE.
|
| `Math.round(parseFloat(value) * 100)` is what everybody writes, and it is a
| float multiplication on the number that becomes a teacher's rate — the very
| reason `ledger_entries` and `settlement_rates` store integers in minor units
| rather than `decimal:2`, whose Laravel cast returns a STRING and puts every sum
| through a float anyway.
|
| ⚠️ AND «NOT A NUMBER» HAS TO BE ITS OWN ANSWER. `parseFloat("")` is NaN,
| `parseFloat("12abc")` is 12, and `Number("")` is 0 — so a silently-coerced
| empty field asks the platform to approve a rate of zero.
*/
describe("toMinorUnits", () => {
  it("converts by the text, not by multiplying", () => {
    expect(toMinorUnits("150")).toBe(15000);
    expect(toMinorUnits("150.5")).toBe(15050);
    expect(toMinorUnits("150.55")).toBe(15055);
    expect(toMinorUnits("0.05")).toBe(5);
    expect(toMinorUnits(" 8.11 ")).toBe(811);
  });

  it("refuses anything that is not an amount", () => {
    // Each of these is a value `parseFloat` answers something plausible for.
    expect(toMinorUnits("")).toBeNull();
    expect(toMinorUnits("12abc")).toBeNull();
    expect(toMinorUnits("-5")).toBeNull();
    expect(toMinorUnits("1.234")).toBeNull();
    expect(toMinorUnits(".5")).toBeNull();
  });
});
