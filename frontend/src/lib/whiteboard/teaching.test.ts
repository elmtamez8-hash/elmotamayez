import { describe, expect, it } from "vitest";

import { PAGE_HEIGHT, PAGE_WIDTH, STREAM_DEFAULTS } from "@/lib/whiteboard/page-model";
import { PENS } from "@/lib/whiteboard/pens";
import { templateFileId, templateLines, templateOf, TEMPLATES } from "@/lib/whiteboard/templates";
import { pickIndex, segmentAt, spinTo, wheelEntries } from "@/lib/whiteboard/wheel";

describe("page templates", () => {
  it("are stored under fixed ids the server accepts, and read back", () => {
    for (const name of TEMPLATES) {
      expect(templateFileId(name)).toMatch(/^template:[a-z-]+:v\d+$/);
      expect(templateOf(templateFileId(name))).toBe(name);
    }
    expect(templateOf("template:sticker-bravo:v1")).toBeNull();
  });

  it("draws the music paper as whole staves of five lines", () => {
    const { lines } = templateLines("music");
    expect(lines.length % 5).toBe(0);
    expect(lines.length / 5).toBeGreaterThanOrEqual(4);
  });

  it("draw something, inside the page, with room between lines for the stream", () => {
    for (const name of TEMPLATES) {
      const { lines, dots } = templateLines(name);
      expect(lines.length + dots.length, name).toBeGreaterThan(10);
      for (const [x, y] of dots) {
        expect(x).toBeGreaterThanOrEqual(0);
        expect(x).toBeLessThanOrEqual(PAGE_WIDTH);
        expect(y).toBeLessThanOrEqual(PAGE_HEIGHT);
      }
      const ys = [...new Set(lines.filter(([, y1, , y2]) => y1 === y2).map(([, y]) => y))].sort((a, b) => a - b);
      for (let i = 1; i < ys.length; i++) expect(ys[i] - ys[i - 1], name).toBeGreaterThanOrEqual(24);
    }
  });
});

describe("page templates fill the page", () => {
  it("run every line edge to edge, with as much room above the first as below the last", () => {
    for (const name of TEMPLATES) {
      const { lines, dots } = templateLines(name);
      const across = lines.filter(([x1, y1, x2, y2]) => y1 === y2 && x1 !== x2);
      for (const [x1, , x2] of across) {
        expect(Math.min(x1, x2), name).toBe(0);
        expect(Math.max(x1, x2), name).toBe(PAGE_WIDTH);
      }
      const ys = across.map(([, y]) => y).sort((a, b) => a - b);
      if (ys.length > 1 && ys[0] > 0) {
        // Centred: the gap at the top equals the gap at the bottom.
        expect(ys[0], name).toBeCloseTo(PAGE_HEIGHT - ys[ys.length - 1], 5);
      }
      if (dots.length > 0) {
        const xs = dots.map(([x]) => x);
        const ys = dots.map(([, y]) => y);
        expect(Math.min(...xs), name).toBeCloseTo(PAGE_WIDTH - Math.max(...xs), 5);
        expect(Math.min(...ys), name).toBeCloseTo(PAGE_HEIGHT - Math.max(...ys), 5);
      }
    }
  });
});

describe("ready pens", () => {
  it("are all at or above the stream's minimum stroke (FR-007)", () => {
    for (const pen of PENS) expect(pen.strokeWidth, pen.id).toBeGreaterThanOrEqual(STREAM_DEFAULTS.minStrokeWidth);
  });
});

describe("the wheel", () => {
  it("stops the chosen segment under the pointer, whatever it started from", () => {
    for (const count of [2, 3, 7, 30]) {
      for (const from of [0, 123, 4567]) {
        for (let index = 0; index < count; index++) {
          const angle = spinTo(from, index, count);
          expect(angle - from).toBeGreaterThanOrEqual(5 * 360);
          expect(segmentAt(angle, count), `${count}/${from}/${index}`).toBe(index);
        }
      }
    }
  });

  it("reads one name per line, and a lone number N as 1…N (Arabic digits too)", () => {
    expect(wheelEntries("أحمد\n\n مريم \nيوسف")).toEqual(["أحمد", "مريم", "يوسف"]);
    expect(wheelEntries("٤")).toEqual(["1", "2", "3", "4"]);
    expect(wheelEntries("5")).toHaveLength(5);
  });

  it("picks every index and nothing outside", () => {
    expect(pickIndex(4, () => 0)).toBe(0);
    expect(pickIndex(4, () => 0.9999)).toBe(3);
  });
});
