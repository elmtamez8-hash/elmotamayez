import { describe, expect, it } from "vitest";

import { calculate, formatDecimal } from "@/lib/whiteboard/calculator";

describe("calculator", () => {
  it("answers exactly first, with the decimal beside it (S⇔D)", async () => {
    expect(await calculate(String.raw`\frac{1}{3}+\frac{1}{6}`, "deg")).toEqual({ ok: true, exact: String.raw`\frac{1}{2}`, decimal: "0.5" });
    expect(await calculate(String.raw`\sqrt{8}`, "deg")).toEqual({ ok: true, exact: String.raw`2\sqrt{2}`, decimal: "2.828427125" });
    expect(await calculate(String.raw`5!`, "deg")).toEqual({ ok: true, exact: null, decimal: "120" });
  }, 30000);

  it("reads angles in the unit chosen", async () => {
    expect(await calculate(String.raw`\sin(30)`, "deg")).toMatchObject({ ok: true, decimal: "0.5" });
    expect(await calculate(String.raw`\sin(30)`, "rad")).toMatchObject({ ok: true, exact: null, decimal: "-0.9880316241" });
  }, 30000);

  it("says which kind of error, like Syntax ERROR and Math ERROR", async () => {
    expect(await calculate("2+", "deg")).toEqual({ ok: false, error: "syntax" });
    expect(await calculate("", "deg")).toEqual({ ok: false, error: "syntax" });
    expect(await calculate(String.raw`1\div0`, "deg")).toEqual({ ok: false, error: "math" });
    expect(await calculate(String.raw`\sqrt{-4}`, "deg")).toEqual({ ok: false, error: "math" });
  }, 30000);

  it("writes e as MathJax reads it, offers no «exact» plain decimal, and gives up on a sum too long", async () => {
    expect(await calculate("e^{2}", "deg")).toMatchObject({ ok: true, exact: "e^{2}", decimal: "7.389056099" });
    expect(await calculate("3.5!", "deg")).toMatchObject({ ok: true, exact: null });
    const started = Date.now();
    expect(await calculate("100000!", "deg")).toEqual({ ok: false, error: "math" });
    expect(Date.now() - started).toBeLessThan(5000);
  }, 30000);

  it("shows ten digits, and scientific form at the extremes", () => {
    expect(formatDecimal(1 / 3)).toBe("0.3333333333");
    expect(formatDecimal(1.5e12)).toBe(String.raw`1.5\times10^{12}`);
    expect(formatDecimal(2e-10)).toBe(String.raw`2\times10^{-10}`);
    expect(formatDecimal(-42)).toBe("-42");
  });
});
