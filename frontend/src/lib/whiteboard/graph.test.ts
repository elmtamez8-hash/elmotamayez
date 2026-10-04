import { describe, expect, it } from "vitest";

import { autoRange, blankGraph, compileFunctions, formula, GraphError, niceStep, ranges, tickLabel, type GraphData } from "@/lib/whiteboard/graph";

const graph = (exprs: string[], more: Partial<GraphData> = {}): GraphData => ({
  ...blankGraph(),
  functions: exprs.map((expr) => ({ expr, color: "#1d4ed8" })),
  ...more,
});

describe("compileFunctions", () => {
  it("reads a formula in x, with or without «y =»", async () => {
    const [f, g] = await compileFunctions(graph(["y = x^2 - 3", "f(x) = 2*x + 1"]));
    expect(f(2)).toBe(1);
    expect(g(3)).toBe(7);
    expect(formula("Y= sin(x)")).toBe("sin(x)");
  });

  it("leaves a gap where there is no real value", async () => {
    const [f] = await compileFunctions(graph(["sqrt(x)"]));
    expect(f(-4)).toBeNaN();
    expect(f(9)).toBe(3);
  });

  it("takes the trig functions in degrees when asked", async () => {
    const [deg] = await compileFunctions(graph(["sin(x)"], { angle: "deg" }));
    const [rad] = await compileFunctions(graph(["sin(x)"]));
    expect(deg(90)).toBeCloseTo(1);
    expect(rad(Math.PI / 2)).toBeCloseTo(1);
  }, 15_000);

  it("names the function it cannot read — a typo, another letter, nothing, or a function turned off", async () => {
    for (const [exprs, index] of [
      [["x", "x^^2"], 1],
      [["t + 1"], 0],
      [["x", "  "], 1],
      [['evaluate("1")'], 0],
    ] as const) {
      await expect(compileFunctions(graph([...exprs]))).rejects.toEqual(new GraphError(index));
    }
  });
});

describe("the axes", () => {
  it("works the y range out from the curves, an asymptote's spike trimmed", async () => {
    const data = graph(["1/x"], { x: [-5, 5] });
    const { y } = ranges(data, await compileFunctions(data));
    expect(y[1]).toBeLessThan(100);
    expect(y[0]).toBeGreaterThan(-100);
  });

  it("puts a backwards range right and widens an empty one", () => {
    expect(ranges(graph([]), []).x).toEqual([-5, 5]);
    expect(ranges(graph([], { x: [5, -5], y: [3, 3] }), [])).toEqual({ x: [-5, 5], y: [2, 4] });
    expect(autoRange([2, 2, 2])).toEqual([0.8, 3.2]);
  });

  it("steps the grid by 1, 2 or 5 × 10ⁿ, and writes the numbers cleanly", () => {
    expect(niceStep(10)).toBe(1);
    expect(niceStep(100)).toBe(10);
    expect(niceStep(3)).toBeCloseTo(0.2);
    expect(niceStep(40)).toBe(5);
    expect(tickLabel(0.1 + 0.2)).toBe("0.3");
    expect(tickLabel(-2)).toBe("−2");
  });
});
