import { describe, expect, it } from "vitest";

import { mathSvg } from "@/lib/whiteboard/math";

describe("mathSvg", () => {
  it("draws standard LaTeX and mhchem chemistry as self-contained SVG paths", async () => {
    const fraction = await mathSvg(String.raw`\frac{a}{b} + \sqrt{x^2}`, true);
    expect(fraction).toMatch(/^<svg/);
    expect(fraction).toContain("<path");
    expect(fraction).not.toContain("data-mjx-error");

    const water = await mathSvg(String.raw`\ce{2H2 + O2 -> 2H2O}`, false);
    expect(water).toContain("<path");
    expect(water).not.toContain("data-mjx-error");
  }, 20000);

  it("marks an unknown command as an error, and has no way out of the equation", async () => {
    expect(await mathSvg(String.raw`\notacommand{x}`, false)).toContain("data-mjx-error");
    expect(await mathSvg(String.raw`\href{https://evil}{x}`, false)).toContain("data-mjx-error");
  }, 20000);

  it("draws the same labelled equation twice (preview, then save)", async () => {
    const labelled = String.raw`\begin{align} x &= 1 \label{eq1} \end{align}`;
    expect(await mathSvg(labelled, true)).not.toContain("data-mjx-error");
    expect(await mathSvg(labelled, true)).not.toContain("data-mjx-error");
  }, 20000);
});
