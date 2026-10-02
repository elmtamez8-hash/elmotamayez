import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

import { ageTrail, CELEBRATIONS, CELEBRATION_MS, spawn, step, TRAIL_SECONDS } from "@/lib/whiteboard/effects";

const seeded = () => {
  let n = 0;
  return () => ((n = (n * 9301 + 49297) % 233280) / 233280);
};

describe("celebrations", () => {
  it("every kind starts with particles and is gone once its time is up", () => {
    for (const kind of CELEBRATIONS) {
      let particles = spawn(kind, 1600, 900, seeded());
      expect(particles.length, kind).toBeGreaterThan(0);

      for (let t = 0; t < CELEBRATION_MS / 1000 + 0.1; t += 0.05) particles = step(particles, 0.05);
      expect(particles, kind).toEqual([]);
    }
  });

  it("confetti falls back after its throw", () => {
    const confetti = spawn("party", 1600, 900, seeded())[0];
    let p = [confetti];
    for (let i = 0; i < 40; i++) p = step(p, 0.05);
    expect(p[0].vy).toBeGreaterThan(0); // past the top of its throw, coming down
  });

  it("is big enough to be seen through a compressed stream", () => {
    for (const kind of CELEBRATIONS) {
      for (const p of spawn(kind, 1600, 900, seeded())) expect(p.size, kind).toBeGreaterThanOrEqual(10);
    }
  });
});

describe("the pointer trail", () => {
  it("fades a point out after the trail's length", () => {
    const points = [{ x: 1, y: 1, age: 0 }];
    expect(ageTrail(points, TRAIL_SECONDS / 2)).toHaveLength(1);
    expect(ageTrail(points, TRAIL_SECONDS)).toHaveLength(0);
  });
});

describe("a display layer only (FR-032, SC-010)", () => {
  // Structural: an effect that cannot reach the board cannot change the page or an export.
  it("no effect file reaches the board", () => {
    const dir = join(process.cwd(), "src/components/whiteboard/overlays");
    const files = [
      ...readdirSync(dir).filter((f) => /\.tsx?$/.test(f) && !/\.test\./.test(f)).map((f) => join(dir, f)),
      join(process.cwd(), "src/lib/whiteboard/effects.ts"),
      join(process.cwd(), "src/lib/whiteboard/effect-sounds.ts"),
    ];

    for (const file of files) {
      const code = readFileSync(file, "utf8");
      expect(code, file).not.toMatch(/excalidraw-api|@excalidraw\/excalidraw|BoardApi|updateScene|addFiles/);
    }
  });
});
