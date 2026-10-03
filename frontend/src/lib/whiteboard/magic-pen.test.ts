import { describe, expect, it } from "vitest";

import { recognise, type Point } from "@/lib/whiteboard/magic-pen";

/** A hand's wobble: deterministic, a few units either way. */
const wobble = (points: Point[], amount = 3): Point[] => points.map(([x, y], i) => [x + Math.sin(i * 1.7) * amount, y + Math.cos(i * 2.3) * amount]);

/** Points along a path through `corners`, `step` apart. */
function along(corners: Point[], step = 6): Point[] {
  const out: Point[] = [];
  for (let i = 1; i < corners.length; i++) {
    const [ax, ay] = corners[i - 1];
    const [bx, by] = corners[i];
    const n = Math.max(1, Math.round(Math.hypot(bx - ax, by - ay) / step));
    for (let k = 0; k < n; k++) out.push([ax + ((bx - ax) * k) / n, ay + ((by - ay) * k) / n]);
  }
  out.push(corners[corners.length - 1]);
  return out;
}

function ellipse(cx: number, cy: number, rx: number, ry: number, from = 0.3): Point[] {
  return Array.from({ length: 80 }, (_, i) => {
    const t = from + (i / 79) * Math.PI * 2;
    return [cx + rx * Math.cos(t), cy + ry * Math.sin(t)] as Point;
  });
}

describe("the magic pen", () => {
  it("straightens a shaky line", () => {
    expect(recognise(wobble(along([[0, 0], [300, 40]]), 2))?.type).toBe("line");
  });

  it("reads a shaft with a head folded back as an arrow from tail to tip", () => {
    const shape = recognise(wobble(along([[0, 0], [300, 0], [270, -25], [300, 0], [270, 25]]), 1.5));
    expect(shape).toMatchObject({ type: "arrow" });
    if (shape?.type === "arrow") expect(shape.to[0]).toBeGreaterThan(280);
  });

  it("rounds a wobbly loop into an ellipse", () => {
    expect(recognise(wobble(ellipse(200, 150, 120, 70)))?.type).toBe("ellipse");
    expect(recognise(wobble(ellipse(100, 100, 60, 60, 2)))?.type).toBe("ellipse");
  });

  it("squares four corners into a rectangle, wherever the pen started", () => {
    expect(recognise(wobble(along([[0, 0], [240, 0], [240, 150], [0, 150], [0, 0]])))?.type).toBe("rectangle");
    expect(recognise(wobble(along([[120, 0], [240, 0], [240, 150], [0, 150], [0, 0], [120, 0]])))?.type).toBe("rectangle");
  });

  it("tells a diamond and a triangle apart", () => {
    expect(recognise(wobble(along([[100, 0], [200, 80], [100, 160], [0, 80], [100, 0]])))?.type).toBe("diamond");
    expect(recognise(wobble(along([[0, 150], [100, 0], [200, 150], [0, 150]])))?.type).toBe("triangle");
  });

  it("leaves an L — the corner of a pair of axes — as drawn, not an arrow", () => {
    expect(recognise(wobble(along([[0, 0], [0, 200], [100, 200]]), 1.5))).toBeNull();
  });

  it("measures «too small» in board units at the caller's zoom", () => {
    const small = wobble(ellipse(30, 30, 12, 12), 0.5);
    expect(recognise(small)).not.toBeNull();
    expect(recognise(small, 24 / 0.5)).toBeNull(); // zoomed out to half: a letter's loop
  });

  it("leaves writing alone: a scribble, a tick and a dot stay as drawn", () => {
    expect(recognise(wobble(along([[0, 0], [40, 60], [80, 0], [120, 60], [160, 0], [200, 60]]), 1))).toBeNull();
    expect(recognise(along([[0, 0], [8, 10]]))).toBeNull();
    expect(recognise([[0, 0], [1, 1], [2, 2]])).toBeNull();
  });
});
