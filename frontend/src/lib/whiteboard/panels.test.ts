import { beforeEach, describe, expect, it } from "vitest";

import { isNear, readPanelModes, writePanelModes } from "./panels";

describe("panel modes", () => {
  beforeEach(() => localStorage.clear());

  it("reads back what was chosen and drops anything it does not know", () => {
    writePanelModes({ tools: "auto", pages: "folded" });
    localStorage.setItem("whiteboard.panels", JSON.stringify({ ...JSON.parse(localStorage.getItem("whiteboard.panels")!), zoom: "gone", nope: "auto" }));
    expect(readPanelModes()).toEqual({ tools: "auto", pages: "folded" });
  });

  it("starts with every panel shown when storage is empty or broken", () => {
    expect(readPanelModes()).toEqual({});
    localStorage.setItem("whiteboard.panels", "{not json");
    expect(readPanelModes()).toEqual({});
  });

  it("brings a panel back only near it", () => {
    const rect = { left: 100, top: 100, right: 200, bottom: 150 };
    expect(isNear(rect, 150, 120)).toBe(true);
    expect(isNear(rect, 240, 120)).toBe(true); // within the reach
    expect(isNear(rect, 400, 120)).toBe(false);
  });
});
