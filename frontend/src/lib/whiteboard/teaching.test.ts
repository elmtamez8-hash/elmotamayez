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
