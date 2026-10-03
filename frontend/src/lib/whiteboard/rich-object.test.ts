import { describe, expect, it, vi } from "vitest";

// The package itself does not load under jsdom; the door's pure logic is what is tested.
vi.mock("@excalidraw/excalidraw", () => ({
  CaptureUpdateAction: { IMMEDIATELY: "immediately", NEVER: "never" },
  convertToExcalidrawElements: (elements: unknown[]) => elements,
  exportToBlob: vi.fn(),
  exportToCanvas: vi.fn(),
  exportToSvg: vi.fn(),
  hashElementsVersion: vi.fn(),
  restoreElements: (elements: unknown[]) => elements,
  setCustomTextMetricsProvider: vi.fn(),
  useHandleLibrary: vi.fn(),
}));

import { replaceRichObject, type BoardApi } from "@/lib/whiteboard/excalidraw-api";
import { blankTable } from "@/lib/whiteboard/table";

describe("replaceRichObject", () => {
  it("keeps the element's id, place, angle, width and layer; only the picture and source change", () => {
    const table = { id: "t1", type: "image", fileId: "old", x: 40, y: 80, width: 600, height: 300, angle: 0.3, version: 4, customData: blankTable(2, 2) };
    const above = { id: "s1", type: "freedraw", version: 1 };
    let scene = [table, above] as unknown[];
    const api = {
      getSceneElementsIncludingDeleted: () => scene,
      updateScene: vi.fn(({ elements }: { elements: unknown[] }) => (scene = elements)),
    } as unknown as BoardApi;

    const data = blankTable(3, 2);
    expect(replaceRichObject(api, "t1", "new", 400, 300, data)).toBe(true);

    const [after, still] = scene as (typeof table)[];
    expect(after).toMatchObject({ id: "t1", fileId: "new", x: 40, y: 80, width: 600, angle: 0.3, version: 5, customData: data, crop: null });
    expect(after.height).toBe(450); // the new picture's proportions at the width the teacher chose
    expect(still).toBe(above); // the layer order is untouched

    // Gone meanwhile: nothing changes, and the caller is told.
    expect(replaceRichObject(api, "missing", "new2", 400, 300, data)).toBe(false);
  });
});
