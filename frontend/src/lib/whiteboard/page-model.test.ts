import { describe, expect, it } from "vitest";

import {
  BACKGROUNDS,
  PAGE_HEIGHT,
  PAGE_WIDTH,
  STREAM_DEFAULTS,
  contrastRatio,
  fitViewport,
  screenAt,
  screensIn,
  pageFrameId,
  penFor,
  recolorForBackground,
} from "./page-model";

/** Where the frame's corners land on screen under a viewport — Excalidraw's own mapping. */
function frameOnScreen(v: { zoom: number; scrollX: number; scrollY: number }) {
  const x = (sx: number) => (sx + v.scrollX) * v.zoom;
  const y = (sy: number) => (sy + v.scrollY) * v.zoom;
  return { left: x(0), right: x(PAGE_WIDTH), top: y(0), bottom: y(PAGE_HEIGHT) };
}

describe("fitViewport", () => {
  it.each([
    [1366, 600],
    [1920, 1080],
    [1280, 1024],
    [3840, 2160],
  ])("fills %ix%i on its tight side and centres the other", (w, h) => {
    const box = frameOnScreen(fitViewport(w, h));
    const width = box.right - box.left;
    const height = box.bottom - box.top;

    // The tight side is filled edge to edge — the bug this replaces left ~30% empty.
    expect(Math.max(width / w, height / h)).toBeCloseTo(1, 6);
    // Centred: equal margins on both axes.
    expect(box.left).toBeCloseTo(w - box.right, 6);
    expect(box.top).toBeCloseTo(h - box.bottom, 6);
  });

  it("zooms past 1 on a large screen, which scrollToContent refuses to do", () => {
    expect(fitViewport(3840, 2160).zoom).toBeCloseTo(2, 6);
  });

  it("fills the canvas with any one screen of a page that has grown downward", () => {
    const v = fitViewport(1366, 600, 2);
    const top = (2 * PAGE_HEIGHT + v.scrollY) * v.zoom;
    const bottom = (3 * PAGE_HEIGHT + v.scrollY) * v.zoom;
    expect(top).toBeCloseTo(600 - bottom, 6); // the third screen, centred like the first
  });
});

describe("the screens of a page", () => {
  it("counts the screens a page holds", () => {
    expect(screensIn(PAGE_HEIGHT)).toBe(1);
    expect(screensIn(3 * PAGE_HEIGHT)).toBe(3);
    expect(screensIn(0)).toBe(1);
  });

  it("finds the screen the teacher is on from Excalidraw's scroll, and never one the page lacks", () => {
    for (const screen of [0, 1, 4]) {
      const v = fitViewport(1366, 600, screen);
      expect(screenAt(v.scrollY, v.zoom, 600, 5)).toBe(screen);
    }
    const far = fitViewport(1366, 600, 9);
    expect(screenAt(far.scrollY, far.zoom, 600, 5)).toBe(4);
  });
});

describe("pageFrameId", () => {
  it("differs per page, so undo cannot carry one page's frame into another", () => {
    expect(pageFrameId("a")).not.toBe(pageFrameId("b"));
    expect(pageFrameId("a")).toBe("frame:a");
  });
});

describe("streaming defaults", () => {
  it.each(Object.entries(BACKGROUNDS))("%s: every palette colour reaches 7:1 on the canvas", (_, bg) => {
    expect(bg.palette[0]).toBe(bg.pen);
    for (const colour of bg.palette) {
      expect(contrastRatio(colour, bg.canvas)).toBeGreaterThanOrEqual(7);
    }
  });

  it("never starts below its own floor", () => {
    expect(STREAM_DEFAULTS.fontSize).toBeGreaterThanOrEqual(STREAM_DEFAULTS.minFontSize);
    expect(STREAM_DEFAULTS.strokeWidth).toBeGreaterThanOrEqual(STREAM_DEFAULTS.minStrokeWidth);
  });
});

describe("penFor", () => {
  it("starts each background with its own high-contrast pen", () => {
    expect(penFor("white")).toBe(BACKGROUNDS.white.pen);
    expect(penFor("blackboard")).toBe(BACKGROUNDS.blackboard.pen);
    expect(penFor("greenboard")).not.toBe(penFor("white"));
  });
});

describe("recolorForBackground", () => {
  it("turns black ink on white into the light pen on the blackboard — the lab's vanishing text", () => {
    const text = { id: "t", strokeColor: BACKGROUNDS.white.pen, backgroundColor: "transparent" };

    const [changed] = recolorForBackground([text], "white", "blackboard");

    expect(changed.strokeColor).toBe(BACKGROUNDS.blackboard.pen);
    expect(changed.backgroundColor).toBe("transparent");
    expect(contrastRatio(changed.strokeColor, BACKGROUNDS.blackboard.canvas)).toBeGreaterThanOrEqual(7);
  });

  it("maps each palette colour to the same position, and leaves a colour from outside the palette alone", () => {
    const red = { id: "r", strokeColor: BACKGROUNDS.white.palette[1], backgroundColor: "#123456" };

    const [changed] = recolorForBackground([red], "white", "greenboard");

    expect(changed.strokeColor).toBe(BACKGROUNDS.greenboard.palette[1]);
    expect(changed.backgroundColor).toBe("#123456");
  });

  it("returns nothing when nothing changes", () => {
    const custom = { id: "c", strokeColor: "#ff00ff", backgroundColor: "transparent" };

    expect(recolorForBackground([custom], "white", "blackboard")).toEqual([]);
    expect(recolorForBackground([custom], "white", "white")).toEqual([]);
  });
});
